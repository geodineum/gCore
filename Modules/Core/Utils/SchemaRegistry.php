<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Utils;

use gCore\Modules\Core\Exceptions\InitializationException;

/**
 * Schema Registry for gCore
 * Manages schema loading, caching, and resolution
 */
class SchemaRegistry 
{
    /** @var array Schema cache */
    private static array $schemaCache = [];
    
    /** @var string Schema directory */
    private static string $schemaDir = '';
    
    /** @var bool Initialized flag */
    public static bool $initialized = false;
    
    /** @var array Schema registry mapping schema keys to files */
    private static array $registry = [
        // Core schemas
        // CONSOLIDATED 2026-02-04: services.yaml now includes service_topology + dependencies
        'core' => 'core.yaml',
        'environment' => 'environment.yaml',
        'services' => 'services.yaml',
        'service_topology' => 'services.yaml',  // Alias: now consolidated into services.yaml
        'dependencies' => 'services.yaml',       // Alias: now consolidated into services.yaml
        'multisite' => 'multisite.yaml',
        'lua_scripts' => 'lua_scripts.yaml',

        // Manager schemas (unified with traits)
        // CONSOLIDATED 2026-02-04: All manager traits now embedded in manager files
        'manager/SecurityManager' => 'manager/SecurityManager.yaml',
        'manager/CacheManager' => 'manager/CacheManager.yaml',
        'manager/ErrorManager' => 'manager/ErrorManager.yaml',
        'manager/APIManager' => 'manager/APIManager.yaml',

        // SecurityManager trait aliases (now in manager/SecurityManager.yaml under 'traits' key)
        'trait/SecurityManager/wordpressIntegration' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/hardwareSecurityTrait' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/xssPreventionTrait' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/headerSecurity' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/sanitization' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/crypto' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/securityRules' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/securityMonitoring' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/advancedAuthentication' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/advancedCrypto' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/advancedRules' => 'manager/SecurityManager.yaml',
        'trait/SecurityManager/alerting' => 'manager/SecurityManager.yaml',

        // APIManager trait aliases (now in manager/APIManager.yaml under 'traits' key)
        'trait/APIManager/authentication' => 'manager/APIManager.yaml',
        'trait/APIManager/endpointManager' => 'manager/APIManager.yaml',
        'trait/APIManager/requestProcessor' => 'manager/APIManager.yaml',
        'trait/APIManager/responseCache' => 'manager/APIManager.yaml',
        'trait/APIManager/rateLimiter' => 'manager/APIManager.yaml',
        'trait/APIManager/validation' => 'manager/APIManager.yaml',
        'trait/APIManager/metricsCollector' => 'manager/APIManager.yaml',
        'trait/APIManager/webSocket' => 'manager/APIManager.yaml',

        // ErrorManager trait aliases (now in manager/ErrorManager.yaml under 'traits' key)
        'trait/ErrorManager/AdvancedLoggingTrait' => 'manager/ErrorManager.yaml',
        'trait/ErrorManager/NotificationTrait' => 'manager/ErrorManager.yaml',
        'trait/ErrorManager/ScriptHandlingTrait' => 'manager/ErrorManager.yaml',
    ];
    
    /**
     * Initialize schema registry
     *
     * @param string $schemaDir Schema directory
     * @return void
     */
    public static function initialize(string $schemaDir): void
    {
        self::$schemaDir = rtrim($schemaDir, '/');
        self::$initialized = true;
        
        // Auto-detect schemas in the directory
        self::autoDetectSchemas(self::$schemaDir);
    }
    
    /**
     * Register a schema
     *
     * @param string $key Schema key
     * @param string $file Schema file path
     * @return void
     */
    public static function register(string $key, string $file): void
    {
        self::$registry[$key] = $file;
        
        // Clear cache for this key to ensure it's reloaded
        if (isset(self::$schemaCache[$key])) {
            unset(self::$schemaCache[$key]);
        }
    }
    
    /**
     * Get a schema by key
     *
     * @param string $key Schema key
     * @param ConfigLoader $configLoader Config loader to use
     * @return array|null Schema or null if not found
     */
    public static function getSchema(string $key, ConfigLoader $configLoader): ?array
    {
        if (isset(self::$schemaCache[$key])) {
            return self::$schemaCache[$key];
        }
        
        if (!isset(self::$registry[$key])) {
            // If schema key not explicitly registered, look for a file with the same name
            $path = self::$schemaDir . '/' . $key . '.yaml';
            if (file_exists($path)) {
                self::register($key, $key . '.yaml');
            } else {
                return null;
            }
        }
        
        try {
            $schema = $configLoader->loadYamlFile('schemas/' . self::$registry[$key]);
            self::$schemaCache[$key] = $schema;
            return $schema;
        } catch (\Exception $e) {
            // Log error but don't throw
            error_log("Failed to load schema {$key}: {$e->getMessage()}");
            return null;
        }
    }
    
    /**
     * Get all registered schema keys
     *
     * @return array Schema keys
     */
    public static function getRegisteredSchemas(): array
    {
        return array_keys(self::$registry);
    }
    
    /**
     * Validate schema existence 
     *
     * @param string $schemaDir Schema directory
     * @return array Missing schemas
     * @throws InitializationException If schema directory not found
     */
    public static function validateSchemaExistence(string $schemaDir): array
    {
        if (!is_dir($schemaDir)) {
            throw new InitializationException("Schema directory not found: {$schemaDir}");
        }
        
        $missing = [];
        
        foreach (self::$registry as $key => $file) {
            $path = $schemaDir . '/' . $file;
            if (!file_exists($path)) {
                $missing[] = $key;
            }
        }
        
        return $missing;
    }
    
    /**
     * Clear schema cache
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$schemaCache = [];
    }
    
    /**
     * Auto-detect schemas in directory
     *
     * @param string $schemaDir Schema directory
     * @return int Number of schemas detected
     */
    public static function autoDetectSchemas(string $schemaDir): int
    {
        if (!is_dir($schemaDir)) {
            return 0;
        }
        
        $count = 0;
        
        // Recursive function to scan directory
        $scan = function($dir, $prefix = '') use (&$scan, &$count, $schemaDir) {
            $files = scandir($dir);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                
                $path = $dir . '/' . $file;
                
                if (is_dir($path)) {
                    $newPrefix = $prefix ? $prefix . '/' . $file : $file;
                    $scan($path, $newPrefix);
                } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'yaml') {
                    $key = $prefix ? $prefix . '/' . pathinfo($file, PATHINFO_FILENAME) : pathinfo($file, PATHINFO_FILENAME);
                    $relativePath = substr($dir, strlen($schemaDir) + 1);
                    $relativePath = $relativePath ? $relativePath . '/' . $file : $file;
                    
                    if (!isset(self::$registry[$key])) {
                        self::register($key, $relativePath);
                        $count++;
                    }
                }
            }
        };
        
        $scan($schemaDir);
        
        return $count;
    }
}