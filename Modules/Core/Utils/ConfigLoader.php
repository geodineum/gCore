<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Utils;

// Use exception classes with proper fallbacks
use gCore\Modules\Core\Utils\SecretLoader;
use gCore\Modules\Core\Utils\SchemaRegistry;

// Ensure exception classes are available, with fallbacks
if (!class_exists('\\gCore\\Modules\\Core\\Exceptions\\ValidationException')) {
    if (class_exists('\\gCore\\Modules\\Core\\Exceptions\\SecurityException')) {
        // Define ValidationException if it doesn't exist but its parent does
        class_alias('\\gCore\\Modules\\Core\\Exceptions\\SecurityException', '\\gCore\\Modules\\Core\\Exceptions\\ValidationException');
    } elseif (class_exists('\\gCore\\Modules\\Core\\Exceptions\\ErrorException')) {
        // Fall back to ErrorException if SecurityException is also missing
        class_alias('\\gCore\\Modules\\Core\\Exceptions\\ErrorException', '\\gCore\\Modules\\Core\\Exceptions\\ValidationException');
    } else {
        // Ultimate fallback to RuntimeException
        class_alias('\\RuntimeException', '\\gCore\\Modules\\Core\\Exceptions\\ValidationException');
    }
}

use gCore\Modules\Core\Exceptions\ValidationException;
use gCore\gNode\Config\CredentialResolver;

/**
 * Config loader for loading and validating configuration files
 *
 * Supports a four-tier caching strategy for zero-YAML-parsing at runtime:
 *   Tier 1: APCu (per-worker shared memory, ~1µs) + constellation generation check
 *   Tier 2: ValKey (distributed cache, ~0.1ms) — enables multi-server config
 *   Tier 3: Compiled PHP file (config/compiled.php, OPcache'd, ~50µs)
 *   Tier 4: YAML parsing (cold start / dev mode only, ~5-10ms)
 *
 * The "constellation generation" is a monotonic counter in ValKey that increments
 * on every config change. APCu entries store their generation — if it doesn't match
 * the current generation, the cache is stale (instant invalidation without TTL wait).
 *
 * Run `php scripts/compile-config.php` at deploy time to generate the compiled file.
 */
class ConfigLoader {
    /**
     * @var array Cache of loaded and validated configs (per-request in-memory)
     */
    private static $configCache = [];

    /**
     * @var array|null Compiled config loaded from compiled.php
     */
    private static $compiledConfig = null;

    /**
     * @var bool Whether compiled config was attempted
     */
    private static $compiledAttempted = false;

    /**
     * @var \Redis|null|false Bootstrap ValKey connection (null=untried, false=failed)
     */
    private static $bootstrapValKey = null;

    /**
     * @var int|null Cached constellation generation (per-request)
     */
    private static $cachedGeneration = null;

    /**
     * APCu TTL in seconds (5 minutes — balanced between freshness and performance)
     */
    private const APCU_TTL = 300;

    /** ValKey connection timeout (fail fast — never block startup) */
    private const VALKEY_TIMEOUT = 0.5;

    /** ValKey read timeout */
    private const VALKEY_READ_TIMEOUT = 0.5;

    /** Persistent connection ID for FPM worker reuse */
    private const VALKEY_PERSISTENT_ID = 'gcore_config';

    /**
     * @var SchemaRegistry The schema registry
     */
    private $schemaRegistry;

    /**
     * Instantiate a new config loader instance
     */
    public function __construct() {
        // Check if SchemaRegistry class exists to avoid "class not found" error
        if (class_exists('\\gCore\\Modules\\Core\\Utils\\SchemaRegistry')) {
            $this->schemaRegistry = new SchemaRegistry();
        } else {
            // Log the error but proceed with minimal functionality
            error_log('SchemaRegistry class not found, config validation will be limited');
            $this->schemaRegistry = null;
        }
    }

    /**
     * Load a config file with four-tier caching
     *
     * Resolution order:
     *   1. Per-request static cache (free — already in memory)
     *   2. APCu shared memory + constellation generation check (~1µs)
     *   3. ValKey distributed cache (~0.1ms) — enables multi-server config
     *   4. Compiled PHP file (OPcache'd, ~50µs, no YAML parsing)
     *   5. YAML file parsing (fallback, ~5-10ms)
     *
     * Lower tiers backfill upper tiers on success (YAML → ValKey + APCu, etc.)
     *
     * @param string $file The path to the config file
     * @param string|null $schemaName Optional schema name to validate against
     * @param bool $useCache Whether to use the config cache
     * @return array The loaded config
     * @throws ValidationException If the config is invalid
     */
    public function load(string $file, ?string $schemaName = null, bool $useCache = true): array {
        // Tier 0: Per-request static cache (already loaded this request)
        $cacheKey = md5($file . ($schemaName ?? ''));
        if ($useCache && isset(self::$configCache[$cacheKey])) {
            return self::$configCache[$cacheKey];
        }

        // Determine which compiled config section this file maps to
        $compiledSection = $this->getCompiledSection($file);

        // Tier 1: APCu (per-worker shared memory) + constellation generation freshness
        if ($useCache && $compiledSection !== null) {
            $config = $this->loadFromApcuWithGeneration($compiledSection);
            if ($config !== null) {
                self::$configCache[$cacheKey] = $config;
                return $config;
            }
        }

        // Tier 2: ValKey (distributed cache — enables constellation-wide config)
        if ($useCache && $compiledSection !== null) {
            $siteId = self::resolveSiteId();
            $config = $this->loadFromValKey($compiledSection, $siteId);
            if ($config !== null) {
                $this->storeInApcuWithGeneration($compiledSection, $config);
                self::$configCache[$cacheKey] = $config;
                return $config;
            }
        }

        // Tier 3: Compiled PHP file (OPcache'd, no YAML parsing)
        if ($useCache && $compiledSection !== null) {
            $config = $this->loadFromCompiled($compiledSection);
            if ($config !== null) {
                // Backfill ValKey for other constellation nodes
                $siteId = self::resolveSiteId();
                $this->storeToValKey($compiledSection, $config, $siteId);
                $this->storeInApcuWithGeneration($compiledSection, $config);
                self::$configCache[$cacheKey] = $config;
                return $config;
            }
        }

        // Tier 4: YAML parsing (cold start / dev mode / unknown files)
        if (!file_exists($file)) {
            throw new ValidationException("Config file not found: {$file}");
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $config = $this->loadFile($file, $ext);
        $config = $this->resolveEnvVars($config);

        // Validate against schema if provided and SchemaRegistry is available
        if ($schemaName !== null) {
            if ($this->schemaRegistry !== null) {
                $schema = $this->schemaRegistry->getSchema($schemaName, $this);
                if ($schema) {
                    $this->validate($config, $schema);
                } else {
                    error_log("Warning: Schema not found: {$schemaName}. Continuing without validation.");
                }
            } else {
                error_log("Warning: SchemaRegistry not available. Cannot validate against schema: {$schemaName}");
            }
        }

        // Backfill all upper tiers
        if ($useCache) {
            self::$configCache[$cacheKey] = $config;
            if ($compiledSection !== null) {
                $siteId = self::resolveSiteId();
                $this->storeToValKey($compiledSection, $config, $siteId);
                $this->storeInApcuWithGeneration($compiledSection, $config);
            }
        }

        return $config;
    }

    /**
     * Map a file path to its section name in compiled.php
     *
     * @param string $file Absolute path to config file
     * @return string|null Section name ('default', 'services') or null if not a compiled file
     */
    private function getCompiledSection(string $file): ?string {
        $basename = basename($file);
        switch ($basename) {
            case 'default.yaml':
            case 'default.yml':
                return 'default';
            case 'services.yaml':
            case 'services.yml':
                return 'services';
            default:
                return null;
        }
    }

    /**
     * Load config from APCu with constellation generation freshness check
     *
     * APCu entries store both config data and the generation they were cached at.
     * If the current constellation generation differs, the entry is stale.
     *
     * @param string $section Config section name
     * @return array|null Config array or null if not cached or stale
     */
    private function loadFromApcuWithGeneration(string $section): ?array {
        if (!function_exists('apcu_fetch')) {
            return null;
        }

        $success = false;
        $entry = apcu_fetch("gcore:config:{$section}", $success);

        if (!$success || !is_array($entry)) {
            return null;
        }

        // Support both old format (raw array) and new format ({data, gen})
        if (!isset($entry['_constellation_gen'])) {
            // Old format — treat as stale so it gets re-stored with generation
            return null;
        }

        $currentGen = $this->getConstellationGeneration();
        if ($currentGen !== null && $currentGen !== $entry['_constellation_gen']) {
            return null;
        }

        return $entry['data'] ?? null;
    }

    /**
     * Store config in APCu with constellation generation tag
     *
     * @param string $section Config section name
     * @param array $config Config data
     */
    private function storeInApcuWithGeneration(string $section, array $config): void {
        if (!function_exists('apcu_store')) {
            return;
        }

        apcu_store("gcore:config:{$section}", [
            'data' => $config,
            '_constellation_gen' => $this->getConstellationGeneration() ?? 0,
        ], self::APCU_TTL);
    }

    // =========================================================================
    // Tier 2: ValKey distributed cache (Geodineum Constellation)
    // =========================================================================

    /**
     * Resolve site ID for ValKey key prefixing
     *
     * @return string Site ID
     */
    private static function resolveSiteId(): string {
        $envSiteId = getenv('GCORE_SITE_ID');
        if ($envSiteId !== false && $envSiteId !== '') {
            return $envSiteId;
        }

        if (function_exists('home_url')) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
            if ($domain) {
                return str_replace(['.', '-'], '_', $domain);
            }
        }

        return 'default';
    }

    /**
     * Get a bootstrap ValKey connection for config loading
     *
     * Uses ONLY env vars + credential files — NOT the config being loaded.
     * This breaks the chicken-egg: config is needed to connect to ValKey,
     * but ValKey is needed to read config. Env vars solve it.
     *
     * @return \Redis|null Connection or null on failure (never blocks startup)
     */
    private static function getBootstrapValKey(): ?\Redis {
        if (self::$bootstrapValKey === false) {
            return null;
        }
        if (self::$bootstrapValKey instanceof \Redis) {
            return self::$bootstrapValKey;
        }
        if (!extension_loaded('redis')) {
            self::$bootstrapValKey = false;
            return null;
        }

        try {
            $host = getenv('VALKEY_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('VALKEY_PORT') ?: 47445);
            $siteId = self::resolveSiteId();

            // One canonical credential reader for the ecosystem: gNode-Client's
            // CredentialResolver. The file location and the
            // gnode_*→valkey_*.password mapping are the contract (see gNode
            // CREDENTIAL_RESOLUTION.md); this bootstrap never re-derives paths
            // of its own.
            $password = CredentialResolver::tryResolve('gnode_client_' . $siteId);
            if ($password === null || $password === '') {
                self::$bootstrapValKey = false;
                return null;
            }

            $redis = new \Redis();
            $connected = $redis->pconnect(
                $host, $port,
                self::VALKEY_TIMEOUT,
                self::VALKEY_PERSISTENT_ID
            );
            if (!$connected) {
                self::$bootstrapValKey = false;
                return null;
            }

            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::VALKEY_READ_TIMEOUT);

            $username = 'gnode_client_' . $siteId;
            if (!$redis->auth([$username, $password])) {
                self::$bootstrapValKey = false;
                return null;
            }

            self::$bootstrapValKey = $redis;
            return $redis;
        } catch (\Throwable $e) {
            error_log("[gCore] ConfigLoader::getBootstrapValKey failed to connect: " . $e->getMessage());
            self::$bootstrapValKey = false;
            return null;
        }
    }

    /**
     * Load config from ValKey distributed cache
     *
     * Keys: {site_id}:gcore:config:default, {site_id}:gcore:config:services
     * Written by compile-config.php at deploy time. Hash-tagged for cluster
     * co-location with sibling per-site keys.
     *
     * @param string $section Config section ('default' or 'services')
     * @param string $siteId Site identifier
     * @return array|null Config array or null if not available
     */
    private function loadFromValKey(string $section, string $siteId): ?array {
        $redis = self::getBootstrapValKey();
        if ($redis === null) {
            return null;
        }

        try {
            $key = '{' . $siteId . '}:gcore:config:' . $section;
            $data = $redis->get($key);
            if ($data === false || $data === null) {
                return null;
            }
            $config = json_decode($data, true);
            return is_array($config) ? $config : null;
        } catch (\Throwable $e) {
            error_log("[gCore] ConfigLoader::loadFromValKey failed for section '{$section}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store config to ValKey (backfill from compiled.php or YAML)
     *
     * Populates ValKey for other constellation nodes.
     *
     * @param string $section Config section ('default' or 'services')
     * @param array $config Config data
     * @param string $siteId Site identifier
     * @return bool Success
     */
    private function storeToValKey(string $section, array $config, string $siteId): bool {
        $redis = self::getBootstrapValKey();
        if ($redis === null) {
            return false;
        }

        try {
            $key = '{' . $siteId . '}:gcore:config:' . $section;
            $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }
            return $redis->set($key, $json) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the current constellation generation from ValKey
     *
     * The generation is a monotonically increasing integer, incremented on
     * every config change (deploy or runtime). Cached per-request.
     *
     * @return int|null Current generation or null if ValKey unavailable
     */
    private function getConstellationGeneration(): ?int {
        if (self::$cachedGeneration !== null) {
            return self::$cachedGeneration;
        }

        $redis = self::getBootstrapValKey();
        if ($redis === null) {
            return null;
        }

        try {
            $siteId = self::resolveSiteId();
            $key = '{' . $siteId . '}:constellation:generation';
            $gen = $redis->get($key);

            if ($gen === false || $gen === null) {
                self::$cachedGeneration = 0;
                return 0;
            }

            self::$cachedGeneration = (int)$gen;
            return self::$cachedGeneration;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Load config from compiled PHP file
     *
     * The compiled file is generated by scripts/compile-config.php at deploy time.
     * It contains pre-parsed, env-resolved PHP arrays — no YAML parsing needed.
     * OPcache makes subsequent includes essentially free (~50µs).
     *
     * @param string $section Config section name ('default' or 'services')
     * @return array|null Config array or null if compiled file not available
     */
    private function loadFromCompiled(string $section): ?array {
        // Load compiled config once per request
        if (!self::$compiledAttempted) {
            self::$compiledAttempted = true;
            $compiledPath = (defined('GCORE_CONFIG_PATH') ? GCORE_CONFIG_PATH : dirname(__DIR__, 3) . '/config')
                . '/compiled.php';

            if (file_exists($compiledPath)) {
                self::$compiledConfig = include $compiledPath;
            }
        }

        if (self::$compiledConfig === null || !is_array(self::$compiledConfig)) {
            return null;
        }

        return self::$compiledConfig[$section] ?? null;
    }

    /**
     * Check if compiled config is available and current
     *
     * @return array Status info: available, version_hash, timestamp, stale
     */
    public static function getCompiledStatus(): array {
        $configPath = defined('GCORE_CONFIG_PATH') ? GCORE_CONFIG_PATH : '';
        $compiledPath = $configPath . '/compiled.php';

        if (!file_exists($compiledPath)) {
            return ['available' => false, 'reason' => 'compiled.php not found'];
        }

        $compiled = include $compiledPath;
        if (!is_array($compiled) || !isset($compiled['_compiled'])) {
            return ['available' => false, 'reason' => 'compiled.php invalid format'];
        }

        $meta = $compiled['_compiled'];

        // Check if source files have been modified since compilation
        $stale = false;
        foreach ($meta['source_mtimes'] as $file => $mtime) {
            $currentMtime = file_exists($configPath . '/' . $file) ? filemtime($configPath . '/' . $file) : 0;
            if ($currentMtime !== $mtime) {
                $stale = true;
                break;
            }
        }

        $loader = new self();
        return [
            'available' => true,
            'version_hash' => $meta['version_hash'],
            'timestamp' => $meta['timestamp'],
            'stale' => $stale,
            'apcu_available' => function_exists('apcu_fetch'),
            'valkey_available' => self::getBootstrapValKey() !== null,
            'constellation_generation' => $loader->getConstellationGeneration(),
        ];
    }

    /**
     * Clear all config caches (per-request, APCu, OPcache)
     *
     * Call after config changes to force reload.
     */
    public static function clearAllCaches(): void {
        // Per-request cache
        self::$configCache = [];
        self::$compiledConfig = null;
        self::$compiledAttempted = false;
        self::$cachedGeneration = null;
        self::$bootstrapValKey = null;

        // APCu
        if (function_exists('apcu_delete')) {
            apcu_delete('gcore:config:default');
            apcu_delete('gcore:config:services');
        }

        // OPcache
        if (function_exists('opcache_invalidate')) {
            $configPath = defined('GCORE_CONFIG_PATH') ? GCORE_CONFIG_PATH : '';
            $compiledPath = $configPath . '/compiled.php';
            if (file_exists($compiledPath)) {
                opcache_invalidate($compiledPath, true);
            }
        }
    }

    /**
     * Load a file based on its extension
     * 
     * @param string $file The file path
     * @param string $ext The file extension
     * @return array The loaded data
     * @throws ValidationException If the file cannot be loaded
     */
    private function loadFile(string $file, string $ext): array {
        switch ($ext) {
            case 'yaml':
            case 'yml':
                if (!function_exists('yaml_parse_file')) {
                    throw new ValidationException("YAML extension not installed. Please install php-yaml extension.");
                }
                $data = yaml_parse_file($file);
                if ($data === false) {
                    throw new ValidationException("Failed to parse YAML file: {$file}");
                }
                return $data;
                
            case 'json':
                $data = json_decode(file_get_contents($file), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new ValidationException("Failed to parse JSON file: {$file} - " . json_last_error_msg());
                }
                return $data;
                
            default:
                throw new ValidationException("Unsupported config file extension: {$ext}");
        }
    }

    /**
     * Recursively resolve ${VAR:-default} environment variable references
     *
     * Supports: ${VAR}, ${VAR:-default}, ${VAR:-}
     *
     * @param mixed $data The config data to resolve
     * @return mixed The resolved data
     */
    private function resolveEnvVars($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->resolveEnvVars($value);
            }
            return $data;
        }

        if (!is_string($data) || strpos($data, '${') === false) {
            return $data;
        }

        return preg_replace_callback('/\$\{([^}]+)\}/', function ($matches) {
            $expr = $matches[1];

            // Handle ${VAR:-default}
            if (strpos($expr, ':-') !== false) {
                [$varName, $default] = explode(':-', $expr, 2);
                $env = getenv($varName);
                return ($env !== false && $env !== '') ? $env : $default;
            }

            // Handle ${VAR} (no default)
            $env = getenv($expr);
            return ($env !== false) ? $env : $matches[0];
        }, $data);
    }

    /**
     * Validate data against a schema
     * 
     * @param array $data The data to validate
     * @param array $schema The schema to validate against
     * @param string $path The current path (for error reporting)
     * @throws ValidationException If the data is invalid
     */
    public function validate(array $data, array $schema, string $path = ''): void {
        // Check type
        if (isset($schema['type'])) {
            $this->validateType($data, $schema['type'], $path);
        }
        
        // Check if data is an array
        if (!is_array($data)) {
            throw new ValidationException("Expected an array at {$path}");
        }
        
        // Check required properties
        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $required) {
                if (!isset($data[$required])) {
                    throw new ValidationException("Missing required property: {$required} at {$path}");
                }
            }
        }
        
        // Check minimum properties
        if (isset($schema['minProperties']) && count($data) < $schema['minProperties']) {
            throw new ValidationException("Object has too few properties, minimum {$schema['minProperties']} at {$path}");
        }
        
        // Check maximum properties
        if (isset($schema['maxProperties']) && count($data) > $schema['maxProperties']) {
            throw new ValidationException("Object has too many properties, maximum {$schema['maxProperties']} at {$path}");
        }
        
        // Validate properties
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $prop => $propSchema) {
                if (isset($data[$prop])) {
                    $value = $data[$prop];
                    $currentPath = $path ? "{$path}.{$prop}" : $prop;
                    
                    // Skip validation for secret placeholders
                    if (is_string($value) && 
                        (class_exists(SecretLoader::class) && 
                         (method_exists(SecretLoader::class, 'isSecretPlaceholder') && 
                          SecretLoader::isSecretPlaceholder($value)) || 
                         (method_exists(SecretLoader::class, 'isEnvPlaceholder') && 
                          SecretLoader::isEnvPlaceholder($value))
                        )
                    ) {
                        continue;
                    }
                    
                    if (is_array($propSchema)) {
                        if (isset($propSchema['$ref'])) {
                            $refSchema = $this->resolveReference($propSchema['$ref']);
                            $this->validate($value, $refSchema, $currentPath);
                        } else {
                            $this->validateValue($value, $propSchema, $currentPath);
                        }
                    }
                }
            }
        }
        
        // Validate pattern properties
        if (isset($schema['patternProperties']) && is_array($schema['patternProperties'])) {
            foreach ($data as $prop => $value) {
                foreach ($schema['patternProperties'] as $pattern => $patternSchema) {
                    if (preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $prop)) {
                        $currentPath = $path ? "{$path}.{$prop}" : $prop;
                        $this->validateValue($value, $patternSchema, $currentPath);
                    }
                }
            }
        }
        
        // Validate additional properties
        if (isset($schema['additionalProperties'])) {
            $knownProps = isset($schema['properties']) ? array_keys($schema['properties']) : [];
            $patternProps = [];
            
            if (isset($schema['patternProperties'])) {
                foreach ($schema['patternProperties'] as $pattern => $patternSchema) {
                    $patternProps[] = $pattern;
                }
            }
            
            foreach ($data as $prop => $value) {
                if (!in_array($prop, $knownProps) && !$this->matchesAnyPattern($prop, $patternProps)) {
                    $currentPath = $path ? "{$path}.{$prop}" : $prop;
                    
                    if ($schema['additionalProperties'] === false) {
                        throw new ValidationException("Additional property not allowed: {$prop} at {$path}");
                    } elseif (is_array($schema['additionalProperties'])) {
                        $this->validateValue($value, $schema['additionalProperties'], $currentPath);
                    }
                }
            }
        }
        
        // Validate dependencies
        if (isset($schema['dependencies']) && is_array($schema['dependencies'])) {
            foreach ($schema['dependencies'] as $prop => $dependency) {
                if (isset($data[$prop])) {
                    if (is_array($dependency) && !isset($dependency[0])) {
                        // Schema dependency
                        $this->validate($data, $dependency, $path);
                    } elseif (is_array($dependency)) {
                        // Property dependency
                        foreach ($dependency as $depProp) {
                            if (!isset($data[$depProp])) {
                                throw new ValidationException("Missing dependency {$depProp} required by {$prop} at {$path}");
                            }
                        }
                    } elseif (is_string($dependency)) {
                        // Single property dependency
                        if (!isset($data[$dependency])) {
                            throw new ValidationException("Missing dependency {$dependency} required by {$prop} at {$path}");
                        }
                    }
                }
            }
        }
    }
    
    /**
     * Check if a property matches any of the given patterns
     * 
     * @param string $prop The property to check
     * @param array $patterns The patterns to check against
     * @return bool True if the property matches any pattern
     */
    private function matchesAnyPattern(string $prop, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $prop)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Validate a value against a schema
     * 
     * @param mixed $value The value to validate
     * @param array $schema The schema to validate against
     * @param string $path The current path (for error reporting)
     * @throws ValidationException If the value is invalid
     */
    private function validateValue($value, array $schema, string $path): void {
        // Resolve references
        if (isset($schema['$ref'])) {
            $refSchema = $this->resolveReference($schema['$ref']);
            $this->validateValue($value, $refSchema, $path);
            return;
        }
        
        // Skip validation for secret placeholders
        if (is_string($value) && 
            (class_exists(SecretLoader::class) && 
             (method_exists(SecretLoader::class, 'isSecretPlaceholder') && 
              SecretLoader::isSecretPlaceholder($value)) || 
             (method_exists(SecretLoader::class, 'isEnvPlaceholder') && 
              SecretLoader::isEnvPlaceholder($value))
            )
        ) {
            return;
        }
        
        // Type validation
        if (isset($schema['type'])) {
            $this->validateType($value, $schema['type'], $path);
        }
        
        // Array validation
        if (is_array($value) && !isset($value[0])) { // Object
            if (isset($schema['properties']) || isset($schema['required']) || 
                isset($schema['additionalProperties']) || isset($schema['patternProperties'])) {
                $this->validate($value, $schema, $path);
            }
        } elseif (is_array($value)) { // Array
            $this->validateArray($value, $schema, $path);
        } elseif (is_string($value)) { // String
            $this->validateString($value, $schema, $path);
        } elseif (is_int($value) || is_float($value)) { // Numeric
            $this->validateNumber($value, $schema, $path);
        } elseif (is_bool($value)) { // Boolean
            // No additional validation for booleans
        }
        
        // Enum validation
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $enumValues = implode(', ', array_map(function($v) { 
                    return is_string($v) ? "\"{$v}\"" : (is_bool($v) ? ($v ? 'true' : 'false') : $v); 
                }, $schema['enum']));
                throw new ValidationException("Value must be one of: {$enumValues} at {$path}");
            }
        }
        
        // Const validation
        if (isset($schema['const']) && $value !== $schema['const']) {
            $const = is_string($schema['const']) ? "\"{$schema['const']}\"" : $schema['const'];
            throw new ValidationException("Value must be {$const} at {$path}");
        }
    }
    
    /**
     * Validate that a value is of the expected type
     * 
     * @param mixed $value The value to validate
     * @param string|array $type The expected type(s)
     * @param string $path The current path (for error reporting)
     * @throws ValidationException If the value is not of the expected type
     */
    private function validateType($value, $type, string $path): void {
        $types = is_array($type) ? $type : [$type];
        
        foreach ($types as $t) {
            switch ($t) {
                case 'string':
                    if (is_string($value)) return;
                    break;
                case 'number':
                    if (is_int($value) || is_float($value)) return;
                    break;
                case 'integer':
                    if (is_int($value)) return;
                    break;
                case 'boolean':
                    if (is_bool($value)) return;
                    break;
                case 'array':
                    if (is_array($value) && isset($value[0])) return;
                    break;
                case 'object':
                    if (is_array($value) && !isset($value[0])) return;
                    break;
                case 'null':
                    if ($value === null) return;
                    break;
            }
        }
        
        $expectedTypes = implode(', ', $types);
        $actualType = gettype($value);
        if ($actualType === 'array') {
            $actualType = isset($value[0]) ? 'array' : 'object';
        }
        
        throw new ValidationException("Type mismatch: expected {$expectedTypes}, got {$actualType} at {$path}");
    }
    
    /**
     * Validate an array value
     * 
     * @param array $value The array to validate
     * @param array $schema The schema to validate against
     * @param string $path The current path (for error reporting)
     * @throws ValidationException If the array is invalid
     */
    private function validateArray(array $value, array $schema, string $path): void {
        // Check minimum items
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            throw new ValidationException("Array has too few items, minimum {$schema['minItems']} at {$path}");
        }
        
        // Check maximum items
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            throw new ValidationException("Array has too many items, maximum {$schema['maxItems']} at {$path}");
        }
        
        // Check uniqueness
        if (isset($schema['uniqueItems']) && $schema['uniqueItems'] === true) {
            $serialized = array_map('serialize', $value);
            if (count(array_unique($serialized)) !== count($serialized)) {
                throw new ValidationException("Array items must be unique at {$path}");
            }
        }
        
        // Validate items
        if (isset($schema['items'])) {
            if (is_array($schema['items']) && !isset($schema['items']['type'])) {
                // Tuple validation
                foreach ($value as $i => $item) {
                    if ($i < count($schema['items'])) {
                        $itemSchema = $schema['items'][$i];
                        $itemPath = "{$path}[{$i}]";
                        $this->validateValue($item, $itemSchema, $itemPath);
                    } elseif (isset($schema['additionalItems'])) {
                        if ($schema['additionalItems'] === false) {
                            throw new ValidationException("Additional items not allowed at {$path}[{$i}]");
                        } elseif (is_array($schema['additionalItems'])) {
                            $itemPath = "{$path}[{$i}]";
                            $this->validateValue($item, $schema['additionalItems'], $itemPath);
                        }
                    }
                }
            } elseif (is_array($schema['items'])) {
                // Single schema for all items
                foreach ($value as $i => $item) {
                    $itemPath = "{$path}[{$i}]";
                    $this->validateValue($item, $schema['items'], $itemPath);
                }
            }
        }
        
        // Validate contains
        if (isset($schema['contains']) && count($value) > 0) {
            $found = false;
            foreach ($value as $item) {
                try {
                    $this->validateValue($item, $schema['contains'], $path);
                    $found = true;
                    break;
                } catch (ValidationException $e) {
                    // Ignore validation errors for contains
                }
            }
            
            if (!$found) {
                throw new ValidationException("Array must contain at least one item matching the schema at {$path}");
            }
        }
    }
    
    /**
     * Validate a string value
     * 
     * @param string $value The string to validate
     * @param array $schema The schema to validate against
     * @param string $path The current path (for error reporting)
     * @throws ValidationException If the string is invalid
     */
    private function validateString(string $value, array $schema, string $path): void {
        // Check minimum length
        if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            throw new ValidationException("String is too short, minimum length {$schema['minLength']} at {$path}");
        }
        
        // Check maximum length
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            throw new ValidationException("String is too long, maximum length {$schema['maxLength']} at {$path}");
        }
        
        // Check pattern
        if (isset($schema['pattern'])) {
            $pattern = '/' . str_replace('/', '\\/', $schema['pattern']) . '/';
            if (!preg_match($pattern, $value)) {
                throw new ValidationException("String does not match pattern {$schema['pattern']} at {$path}");
            }
        }
        
        // Check format
        if (isset($schema['format'])) {
            switch ($schema['format']) {
                case 'date-time':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
                        throw new ValidationException("Invalid date-time format at {$path}");
                    }
                    break;
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new ValidationException("Invalid email format at {$path}");
                    }
                    break;
                case 'hostname':
                    if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $value)) {
                        throw new ValidationException("Invalid hostname format at {$path}");
                    }
                    break;
                case 'ipv4':
                    if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        throw new ValidationException("Invalid IPv4 format at {$path}");
                    }
                    break;
                case 'ipv6':
                    if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        throw new ValidationException("Invalid IPv6 format at {$path}");
                    }
                    break;
                case 'uri':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        throw new ValidationException("Invalid URI format at {$path}");
                    }
                    break;
            }
        }
    }
    
    /**
     * Validate a numeric value
     * 
     * @param int|float $value The number to validate
     * @param array $schema The schema to validate against
     * @param string $path The current path (for error reporting)
     * @throws ValidationException If the number is invalid
     */
    private function validateNumber($value, array $schema, string $path): void {
        // Check minimum
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new ValidationException("Value must be >= {$schema['minimum']} at {$path}");
        }
        
        // Check exclusive minimum
        if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            throw new ValidationException("Value must be > {$schema['exclusiveMinimum']} at {$path}");
        }
        
        // Check maximum
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            throw new ValidationException("Value must be <= {$schema['maximum']} at {$path}");
        }
        
        // Check exclusive maximum
        if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            throw new ValidationException("Value must be < {$schema['exclusiveMaximum']} at {$path}");
        }
        
        // Check multiple of
        if (isset($schema['multipleOf']) && fmod($value, $schema['multipleOf']) !== 0.0) {
            throw new ValidationException("Value must be a multiple of {$schema['multipleOf']} at {$path}");
        }
    }
    
    /**
     * Resolve a JSON Schema reference
     * 
     * @param string $ref The reference to resolve
     * @return array The resolved schema
     * @throws ValidationException If the reference cannot be resolved
     */
    private function resolveReference(string $ref): array {
        // Handle external references
        if (preg_match('/^[a-zA-Z0-9_-]+\.json$/', $ref)) {
            return $this->schemaRegistry->getSchema($ref);
        }
        
        // Handle internal references
        if (strpos($ref, '#/') === 0) {
            $path = substr($ref, 2);
            $parts = explode('/', $path);
            
            // Get the current schema
            $schema = $this->schemaRegistry->getCurrentSchema();
            
            // Follow the path
            foreach ($parts as $part) {
                if (!isset($schema[$part])) {
                    throw new ValidationException("Reference not found: {$ref}");
                }
                $schema = $schema[$part];
            }
            
            return $schema;
        }
        
        throw new ValidationException("Invalid reference: {$ref}");
    }
}