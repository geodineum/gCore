<?php
declare(strict_types=1);
namespace gCore\Modules\Core;
use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Utils\ConfigLoader;
use gCore\Modules\Core\Utils\TopologyParser;
use gCore\Modules\Core\Utils\ExtensionResolver;
use gCore\Modules\Core\Exceptions\InitializationException;
use gCore\Modules\Core\Interfaces\Services\MicroserviceInterface;
use gCore\Modules\Core\Interfaces\Services\MicroserviceFactoryInterface;

if (!defined('ABSPATH')) {
    exit;
}

// Define GCORE_CONFIG_PATH if not already defined (for direct class usage)
if (!defined('GCORE_CONFIG_PATH')) {
    define('GCORE_CONFIG_PATH', dirname(__DIR__, 2) . '/config');
}

/**
 * gCore - Core Service Management System
 * 
 * Provides foundational service management and initialization for the gCore system.
 * Handles local service instantiation and basic state tracking without external dependencies.
 * 
 * @package     gCore
 * @subpackage  Core
 * @version     0.1.0
 */

class gCore implements ModuleInterface {
    private const CORE_VERSION = '0.1.0';
    
    /**
     * @var ConfigLoader
     */
    private $configLoader;
    
    /** @var gCore Singleton instance */
    private static $instance = null;
    
    private $testMode = false;
    
    /** @var array<string, array> Active service registry */
    private $serviceRegistry = [];
    
    /** @var array<string, object> Service instances */
    private $instances = [];
    
    /** @var array System configuration */
    private $config = [];
    
    /** @var bool System state */
    private $initialized = false;

    /** @var bool Bootstrap phase (allows getService during init) */
    private $bootstrapping = false;

    /** @var string|null Current environment */
    private static $environment = null;
    
    /** @var array Microservice factories */
    private $microserviceFactories = [];
    
    /** @var array Service topology */
    private $serviceTopology;
    
    /** @var array Metrics */
    private $metrics = [];
    
    /**
     * Service State Transitions
     * Defines valid state transitions for each service state
     */
    private const SERVICE_STATES = [
        'pending'   => ['starting', 'failed'],
        'starting'  => ['active', 'failed'],
        'active'    => ['stopping', 'failed'],
        'stopping'  => ['stopped', 'failed'],
        'stopped'   => ['starting'],
        'failed'    => ['starting']
    ];
    
    private const SUPPORTED_ENVIRONMENTS = [
        'development',
        'staging',
        'production',
        'wordpress'
    ];

    /**
     * Get singleton instance
     * @return self
     * @api
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize core system
     * @param array $config
     * @return void
     * @api
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        // Enter bootstrap phase - allows getService() during init
        $this->bootstrapping = true;

        try {
            // Update state to starting
            $this->updateManagerState('starting');

            // Initialize configuration system (config from compiled PHP / APCu / ValKey / YAML)
            $this->initializeConfig($config);

            // Initialize gNode-Client EARLY — needed for ValKey-backed topology
            $this->initializegNodeClientEarly();

            // Load service topology (ValKey canonical → YAML fallback)
            $this->loadServiceTopology();

            // Initialize service layers in priority order
            $this->initializeMicroservices();
            $this->bootstrapRequiredServices();
            $this->initializeRequestedServices();

            $this->initialized = true;
            $this->bootstrapping = false;
            $this->logCoreEvent('info', 'Core system initialized successfully');

        } catch (\Throwable $e) {
            $this->bootstrapping = false;
            $this->logCoreEvent('error', 'Core initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * validate the environment
     * @param string $environment
     * @return void
    */
    private function validateEnvironment(string $environment): void {
        if (!in_array($environment, self::SUPPORTED_ENVIRONMENTS)) {
            throw new InitializationException(
                "Unsupported environment: {$environment}"
            );
        }
    }

    /** 
     * Enable the test mode
     * @return void 
    */
    public function enableTestMode(): void {
        $this->testMode = true;
    }

    /** 
     * Are we in test mode?
     * @return bool
    */
    public function isTestMode(): bool {
        return $this->testMode;
    }

    /**
     * should we track the metrics?
     * @return bool
    */
    private function shouldTrackMetrics(): bool {
        return !$this->testMode && $this->config['monitoring']['metrics_enabled'] ?? true;
    }

    /**
     * Validate service state
     * @return void
    */
    private function validateServiceState(): void {
        // Allow service access during bootstrap phase
        if ($this->bootstrapping) {
            return;
        }
        if (!$this->initialized) {
            throw new InitializationException('Core system not initialized');
        }
    }

    /**
     * Validate service operation
     * @param string $service
     * @return void
     * @throws InitializationException If service is unknown
    */
    private function validateServiceOperation(string $service): void {
        if ($service === null) {
            throw new InitializationException("Service name cannot be null");
        }
        
        if (!isset($this->serviceRegistry[$service])) {
            throw new InitializationException("Unknown service: {$service}");
        }
    }

    /**
     * Load service topology
     * @return void
     */
    /**
     * Load service topology — ValKey canonical, YAML fallback
     *
     * Priority:
     *   1. ValKey (canonical source, stored per-site by compile-config.php)
     *   2. Compiled PHP (config/compiled.php, services section)
     *   3. YAML file (config/services.yaml, cold start only)
     *
     * @return void
     * @throws InitializationException If topology cannot be loaded from any source
     */
    private function loadServiceTopology(): void {
        try {
            $topology = null;
            $source = 'unknown';

            // Tier 1: Try ValKey (canonical, per-site)
            if ($this->isServiceActive('gnode_client')) {
                $topology = $this->loadTopologyFromValKey();
                if ($topology !== null) {
                    $source = 'valkey';
                }
            }

            // Tier 2: Fall back to ConfigLoader (compiled PHP / APCu / YAML)
            if ($topology === null) {
                $topologyPath = GCORE_CONFIG_PATH . '/services.yaml';
                $topology = $this->configLoader->load($topologyPath);
                $source = 'config_loader';
            }

            if (!is_array($topology) || empty($topology)) {
                throw new InitializationException("Service topology is empty or invalid");
            }

            // Validate version
            if (isset($topology['version'])) {
                $this->validateTopologyVersion($topology);
            }

            // Store topology
            $this->serviceTopology = $topology;

            // Log summary
            if (isset($topology['services']) && is_array($topology['services'])) {
                $this->logCoreEvent('info', 'Service topology loaded', [
                    'source' => $source,
                    'total_services' => count($topology['services']),
                ]);
            }

        } catch (\Exception $e) {
            $this->logCoreEvent('error', 'Failed to load service topology', [
                'error' => $e->getMessage(),
            ]);
            throw new InitializationException(
                "Failed to load service topology: {$e->getMessage()}"
            );
        }
    }

    /**
     * Load topology from ValKey (canonical per-site source)
     *
     * Reads from {site_id}:gcore:config:services stored by compile-config.php.
     * Hash-tagged for cluster co-location with sibling per-site keys.
     *
     * @return array|null Topology array or null if not available
     */
    private function loadTopologyFromValKey(): ?array {
        try {
            $storageAdapter = $this->getStorageAdapter();
            if ($storageAdapter === null) {
                return null;
            }

            $siteId = $this->config['site_id'] ?? $this->config['core']['site_id'] ?? 'default';
            $key = '{' . $siteId . '}:gcore:config:services';

            $gNodeClient = $storageAdapter->getgNodeClient();
            $data = $gNodeClient->luaGet($key);

            if ($data === null || $data === false) {
                return null;
            }

            $topology = is_string($data) ? json_decode($data, true) : $data;

            if (!is_array($topology) || !isset($topology['services'])) {
                $this->logCoreEvent('warning', 'ValKey topology invalid format', [
                    'key' => $key
                ]);
                return null;
            }

            return $topology;
        } catch (\Throwable $e) {
            $this->logCoreEvent('warning', 'ValKey topology read failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Initialize configuration system
     * 
     * @param array $runtime Runtime configuration overrides
     * @throws InitializationException If configuration loading fails
     */
    private function initializeConfig(array $runtime = []): void {
        try {
            $this->configLoader = new ConfigLoader();
            $configFile = GCORE_CONFIG_PATH . '/default.yaml';
            
            if (!file_exists($configFile)) {
                $this->logCoreEvent('warning', 'Default config file not found at ' . $configFile);
                $this->config = $runtime;
                return;
            }
            
            $this->config = $this->configLoader->load($configFile);
            
            // Merge with runtime config
            if (!empty($runtime)) {
                $this->config = array_replace_recursive($this->config, $runtime);
            }
        } catch (\Exception $e) {
            throw new InitializationException(
                "Configuration initialization failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
    
    /**
     * Initialize manager instance with configuration
     * 
     * @param string $manager Manager name
     * @throws InitializationException If initialization fails
     */
    private function initializeManager(string $manager): void {
        if ($this->isManagerActive($manager)) {
            return;
        }

        // Get manager configuration
        $config = $this->config['modules'][$manager] ?? null;
        if (!$config) {
            throw new InitializationException("No configuration found for {$manager}");
        }

        // Get trait configurations
        $traitConfigs = [];
        if (isset($config['traits'])) {
            foreach ($config['traits'] as $trait => $enabled) {
                if ($enabled) {
                    try {
                        $traitConfigs[$trait] = $this->configLoader->loadTraitConfig($manager, $trait);
                    } catch (\Exception $e) {
                        throw new InitializationException(
                            "Failed to load trait {$trait} for {$manager}: {$e->getMessage()}",
                            0,
                            $e
                        );
                    }
                }
            }
        }

        // Merge configurations
        $finalConfig = array_replace_recursive(
            $config,
            ['traits' => $traitConfigs]
        );

        // Create and initialize manager instance
        $instance = $this->createManagerInstance($manager, $finalConfig);
        $this->registerManager($manager, $instance);
    }

    /**
     * Get manager initialization order from topology
     * 
     * @return array Ordered list of manager names
     */
    private function getManagerInitializationOrder(): array {
        $topology = $this->config['topology'] ?? [];
        
        // Basic order if no topology defined
        if (empty($topology)) {
            return [
                'SecurityManager',
                'ErrorManager',
                'CacheManager'
            ];
        }

        // Return topology-based order
        return array_keys($topology);
    }

    private const DTAP_TIERS = ['development', 'testing', 'staging', 'acceptance', 'production'];

    /**
     * Resolve a DTAP environment from a hostname using the canonical schema
     * algorithm: take the subdomain prefix before the first dot and match it
     * (case-insensitive, whole-token) against the prefix rules; no match →
     * production. This replaces the prior substring matching, which classified
     * any bare domain that merely CONTAINED a token as non-production —
     * "protest.org"/"greatestdeals.com" → testing would have silently gated a
     * PRODUCTION site's notifications.
     *
     * @return string one of self::DTAP_TIERS
     */
    private function detectEnvironmentFromDomain(string $domain): string {
        // Single source: gcore-mu/dtap-rules.php (the one PHP mirror of
        // gNode's dtap_schema.yaml). gcore-mu is a fixed sibling of
        // Modules/ in every layout (dev tree and /opt deploy).
        $rules = dirname(__DIR__, 2) . '/gcore-mu/dtap-rules.php';
        if (is_readable($rules)) {
            require_once $rules;
        }
        if (!function_exists('gcore_dtap_environment_from_host')) {
            // Broken install (gcore-mu missing) — fail safe to production
            // so no notification/side-effect gate opens by accident.
            return 'production';
        }
        return gcore_dtap_environment_from_host($domain);
    }

    /**
     * Detect DTAP environment for gNode stream routing
     *
     * Returns one of self::DTAP_TIERS. Used to determine which gNode unified /
     * comms stream a site connects to, and (via gNode-Client) the environment
     * stamped onto comms messages for the COMMS daemon's non-prod send gate.
     *
     * Priority order (most authoritative first):
     * 1. Explicit config: $config['gnode_environment'] (stored at registration)
     * 2. WordPress WP_ENVIRONMENT_TYPE constant
     * 3. Domain prefix detection — canonical schema algorithm (not substring)
     * 4. Default: 'production' (safe default)
     *
     * @return string DTAP environment identifier
     */
    private function detectGNodeEnvironment(): string {
        // 1. Explicit config override
        if (isset($this->config['gnode_environment'])) {
            $env = strtolower(trim($this->config['gnode_environment']));
            if (in_array($env, self::DTAP_TIERS, true)) {
                return $env;
            }
        }

        // 2. WordPress environment constant (if available), mapped to DTAP tiers
        if (defined('WP_ENVIRONMENT_TYPE')) {
            $wpEnv = strtolower((string) WP_ENVIRONMENT_TYPE);
            $wpMap = [
                'local'       => 'development',
                'development' => 'development',
                'staging'     => 'staging',
                'production'  => 'production',
            ];
            if (isset($wpMap[$wpEnv])) {
                return $wpMap[$wpEnv];
            }
        }

        // 3. Domain prefix detection (canonical — matches the DTAP schema)
        if (function_exists('get_site_url')) {
            $host = strtolower((string) parse_url((string) get_site_url(), PHP_URL_HOST));
            if ($host !== '') {
                return $this->detectEnvironmentFromDomain($host);
            }
        }

        // 4. Safe default - production
        return 'production';
    }

    /**
     * Initialize gNode-Client early in the boot sequence
     *
     * Called BEFORE topology loading so we can fetch topology from ValKey.
     * This is a lightweight init — just establishes the connection.
     * Full service registration happens later in bootstrapRequiredServices().
     */
    private function initializegNodeClientEarly(): void {
        // Skip if already initialized (e.g., from a prior call)
        if ($this->isServiceActive('gnode_client')) {
            return;
        }

        try {
            $this->initializegNodeClient();
        } catch (\Throwable $e) {
            // gNode-Client is optional — topology will fall back to YAML
            $this->logCoreEvent('warning', 'gNode-Client early init failed, will use YAML topology fallback', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Initialize gNode Client directly (no wrapper)
     *
     * Uses the new simplified gNode-Client API that auto-discovers credentials.
     * gCore only needs to provide site_id and environment - gNode-Client handles:
     * - ValKey username construction from site_id
     * - Password resolution from standard locations
     * - Host/port from env vars or defaults
     *
     * @throws InitializationException If initialization fails
     */
    private function initializegNodeClient(): void {
        try {
            // Get config
            $geometricConfig = $this->config['geometric_topology'] ?? [];
            $coreConfig = $this->config['core'] ?? [];

            // Get site_id from config (required for per-site ACL isolation)
            $siteId = $this->config['site_id'] ?? $this->config['core']['site_id'] ?? 'default';

            // Get DTAP environment for stream isolation
            $environment = $this->config['gnode_environment'] ?? $this->detectGNodeEnvironment();

            // Build overrides from explicit config (backward compatibility)
            // If redis_* keys are explicitly set, use them as overrides
            $overrides = [
                'stream_prefix' => $geometricConfig['daemon']['stream_prefix'] ?? 'gnode',
                'use_fallback' => $geometricConfig['daemon']['use_fallback'] ?? true,
                'allow_local_execution' => true,
                'skip_connection_check' => true,  // Faster initialization, lazy connection
                'debug' => $coreConfig['debug'] ?? $this->config['debug'] ?? false,
                'batch_size' => 10,
                'timeout' => 1000,  // 1 second - fail fast if daemon unresponsive
                'lua_enabled' => true,
                'metrics_level' => 1,  // Basic metrics (0=none, 1=basic, 2=detailed)
            ];

            // Check for explicit valkey config overrides
            // NOTE: We use ValKey, not Redis. No redis fallback needed.
            $valkeyConfig = $this->config['valkey'] ?? [];
            if (empty($valkeyConfig)) {
                // Check flattened keys (legacy support)
                if (!empty($this->config['valkey_host'] ?? null)) {
                    $valkeyConfig['host'] = $this->config['valkey_host'];
                }
                if (!empty($this->config['valkey_port'] ?? null)) {
                    $valkeyConfig['port'] = $this->config['valkey_port'];
                }
                if (!empty($this->config['valkey_user'] ?? null)) {
                    $valkeyConfig['user'] = $this->config['valkey_user'];
                }
                $valkeyPassword = $this->config['valkey_auth'] ?? ($this->config['valkey_password'] ?? null);
                if (!empty($valkeyPassword)) {
                    $valkeyConfig['password'] = $valkeyPassword;
                }
            }

            // Merge explicit valkey config into overrides if provided
            if (!empty($valkeyConfig['host'])) {
                $overrides['host'] = $valkeyConfig['host'];
            }
            if (!empty($valkeyConfig['port'])) {
                $overrides['port'] = $valkeyConfig['port'];
            }
            if (!empty($valkeyConfig['user'])) {
                $overrides['user'] = $valkeyConfig['user'];
            }
            if (!empty($valkeyConfig['password_file']) && file_exists($valkeyConfig['password_file'])) {
                $overrides['password'] = trim(file_get_contents($valkeyConfig['password_file']));
            } elseif (empty($valkeyConfig['password']) && empty($valkeyConfig['auth'])) {
                // The configured password_file is stale/absent (older wp-config-
                // geodineum.yaml points at /opt/.../gNode/.gnode/ which no longer
                // exists). Because we DO pass a valkey user/host override below,
                // gNodeConfig treats the connection as explicitly configured and
                // requires a password — it will NOT fall back to credential
                // auto-discovery. Resolve the canonical centralized per-site
                // credential here so full gCore init finds the SAME password the
                // lightweight admin client (Status page) already resolves.
                $centralized = '/etc/geodineum/credentials/valkey_client_' . $siteId . '.password';
                if (is_readable($centralized)) {
                    $overrides['password'] = trim((string) file_get_contents($centralized));
                }
            }
            $overridePassword = $valkeyConfig['password'] ?? $valkeyConfig['auth'] ?? null;
            if (!empty($overridePassword)) {
                $overrides['password'] = $overridePassword;
            }

            // Create gNode-Client using the canonical gNodeClient::forSite() factory
            // This handles credential auto-discovery from (in order):
            // 1. Explicit overrides (above)
            // 2. Environment variables: VALKEY_PASSWORD, VALKEY_PASSWORD_FILE
            // 3. GNODE_BASE_PATH env var + /.gnode/valkey_client_{site_id}.password
            // 4. Standard: /opt/geodineum/gNode/.gnode/valkey_client_{site_id}.password
            // Set GNODE_BASE_PATH env var to customize credential location
            $gNodeClient = \gCore\gNode\gNodeClient::forSite(
                $siteId,
                $environment,
                $overrides
            );

            // Create SINGLE gNodeStorageAdapter wrapping gNode-Client
            // ALL storage operations flow through gNode-Client - never access ValKey directly!
            // Architecture: gCore Managers → gNodeStorageAdapter → gNode-Client → ValKey
            $gNodeStorageAdapter = new \gCore\Modules\Storage\gNodeStorageAdapter(
                $gNodeClient,
                $siteId,
                [
                    'debug' => $coreConfig['debug'] ?? $this->config['debug'] ?? false
                ]
            );

            // Store the SINGLE adapter instance - all managers share this
            $this->instances['gnode_storage_adapter'] = $gNodeStorageAdapter;
            $this->serviceRegistry['gnode_storage_adapter'] = [
                'id' => 'gnode_storage_adapter',
                'state' => 'active',
                'type' => 'singleton',
                'registered_at' => microtime(true),
                'deployment' => 'single',
                'role' => 'leader',
                'stats' => [
                    'state_changes' => 1,
                    'last_updated' => microtime(true)
                ],
                'capabilities' => ['storage', 'valkey', 'pooled_connection', 'lua_enabled', 'metrics']
            ];

            // Capability dimensions are schema-driven (gNode
            // service_schema.yaml, 30-dim canonical) — the former per-client
            // registerCapabilityDimension loop sent a command no daemon
            // handler ever implemented and was removed with it.

            // Register as service (proper pattern: instances + serviceRegistry)
            $this->instances['gnode_client'] = $gNodeClient;

            $this->serviceRegistry['gnode_client'] = [
                'id' => 'gnode_client',
                'state' => 'active',
                'type' => 'singleton',
                'registered_at' => microtime(true),
                'deployment' => 'single',
                'role' => 'leader',
                'stats' => [
                    'state_changes' => 1,
                    'last_updated' => microtime(true)
                ],
                'capabilities' => [
                    'geometric_discovery',      // Daemon-routed geometric ops
                    'template_rendering',       // Daemon-routed Tera templates
                    'stream_processing',        // Stream-based communication
                    'lua_batch',                // batched via gNode_BATCH_EXEC
                    'batch_cache',              // batched via gNode_BATCH_MGET/MSET
                    'smart_routing',            // Auto-routes to fastest path
                    'metrics_tracking',         // Built-in observability
                    'rate_limiting'             // Atomic rate limiting via Lua
                ]
            ];

            $this->logCoreEvent('info', 'gNode-Client initialized successfully', [
                'environment' => $environment,
                'site_id' => $siteId
            ]);

            // Publish stream contract schemas to ValKey (convention-over-configuration)
            // gCore's contracts live in config/schemas/contracts/ (separate from validation schemas)
            $schemasDir = defined('GCORE_CONFIG_PATH')
                ? GCORE_CONFIG_PATH . '/schemas/contracts'
                : __DIR__ . '/../../config/schemas/contracts';
            if (is_dir($schemasDir)) {
                try {
                    $result = \gCore\Modules\Core\Utils\GeodineumSchema::publish(
                        $gNodeClient->getStorage(),
                        $siteId,
                        $schemasDir,
                        $environment
                    );
                    if ($result['published'] > 0) {
                        $this->logCoreEvent('info', 'Schema contracts published to ValKey', $result);
                    }
                } catch (\Throwable $e) {
                    // Non-fatal — schemas are optional, components still work without them
                    $this->logCoreEvent('debug', 'Schema publication skipped', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (\Throwable $e) {
            $this->logCoreEvent('error', 'gNode-Client initialization failed', [
                'error' => $e->getMessage()
            ]);
            throw new InitializationException(
                "Failed to initialize gNode-Client: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get environment
     * @return string
    */
    private function detectEnvironment(): string {
        if (self::$environment !== null) {
            return self::$environment;
        }

        // Check config first
        if (isset($this->config['core']['environment']) && 
            $this->config['core']['environment'] !== 'auto') {
            return $this->config['core']['environment'];
        }

        // Check for WordPress
        if (defined('ABSPATH') && defined('WP_CONTENT_DIR')) {
            self::$environment = 'wordpress';
            return self::$environment;
        }

        self::$environment = 'standalone';
        return self::$environment;
    }

    /**
     * Check if core system is healthy
     * @return bool
    */
    public function isHealthy(): bool {
        if (!$this->initialized) {
            return false;
        }

        try {
            foreach ($this->instances as $service => $instance) {
                if ($instance instanceof MicroserviceInterface && !$instance->isAvailable()) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            $this->logCoreEvent('error', 'Health check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * update the metrics of gCore (internal / local)
     * @param string $operation
     * @return void
    */
    private function updateMetrics(string $operation): void {
        if (!$this->shouldTrackMetrics()) {
            return;
        }

        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = 0;
        }
        $this->metrics[$operation]++;
    }


    /**
     * Bootstrap required services with topology awareness and capability-based discovery
     * @return void
     * @throws InitializationException If topology is not loaded or initialization fails
    */
    /**
     * Bootstrap required services in priority order
     *
     * Uses the unified 'services' key from topology with 'category' and 'priority'
     * fields to determine initialization order. Lower priority = initialized first.
     *
     * gNode-Client is initialized early (before this method) so it's already
     * available for ValKey-backed topology and storage.
     *
     * @return void
     * @throws InitializationException If topology is not loaded
     */
    private function bootstrapRequiredServices(): void {
        $this->logCoreEvent('info', 'Starting bootstrap of required services');

        if (!isset($this->serviceTopology) || !is_array($this->serviceTopology)) {
            throw new InitializationException("Service topology not loaded");
        }

        // Get services from the unified 'services' key
        $services = $this->serviceTopology['services'] ?? [];
        if (empty($services)) {
            $this->logCoreEvent('warning', 'No services found in topology');
            return;
        }

        // Sort services by priority (lower = earlier), then by name for determinism
        uasort($services, function ($a, $b) {
            $pa = $a['priority'] ?? 999;
            $pb = $b['priority'] ?? 999;
            return $pa <=> $pb;
        });

        // Initialize required services in priority order
        // Skip gnode_client (already initialized early) and non-required services
        foreach ($services as $serviceName => $meta) {
            if ($serviceName === 'gnode_client') {
                continue; // Handled by initializegNodeClientEarly() — never retry via generic path
            }

            $required = $meta['required'] ?? false;
            $enabled = $meta['enabled'] ?? true;

            if (!$enabled) {
                continue;
            }

            if ($required) {
                $this->logCoreEvent('debug', "Initializing required service: {$serviceName}", [
                    'priority' => $meta['priority'] ?? '?',
                    'category' => $meta['category'] ?? '?'
                ]);
                $this->initializeService($serviceName);
            }
        }

        $this->logCoreEvent('info', 'Completed bootstrap of required services');
    }

    /**
     * Initialize requested services
     * @return void
    */
    private function initializeRequestedServices(): void {
        if (!isset($this->config['services'])) {
            $this->logCoreEvent('info', 'No additional services configured for initialization');
            return;
        }
        
        foreach ($this->config['services'] as $serviceName => $serviceConfig) {
            if (is_string($serviceName) && !$this->isServiceActive($serviceName)) {
                $this->initializeService($serviceName);
            }
        }
    }

    /**
     * Initialize microservices
     * @return void
    */
    private function initializeMicroservices(): void {
        // Check if microservices configuration exists
        if (!isset($this->config['microservices'])) {
            $this->logCoreEvent('info', 'Microservices configuration not found, skipping initialization');
            return;
        }
        
        try {
            // Check if microservices are enabled
            if (!isset($this->config['microservices']['enabled']) || 
                !$this->config['microservices']['enabled']) {
                $this->logCoreEvent('info', 'Microservices are disabled, skipping initialization');
                return;
            }
            
            // Check if factories are defined
            if (!isset($this->config['microservices']['factories']) || 
                !is_array($this->config['microservices']['factories'])) {
                $this->logCoreEvent('warning', 'No microservice factories defined');
                return;
            }
            
            // Initialize factories
            foreach ($this->config['microservices']['factories'] as $type => $factoryConfig) {
                if (!isset($factoryConfig['enabled']) || !$factoryConfig['enabled']) {
                    $this->logCoreEvent('info', "Factory {$type} is disabled, skipping");
                    continue;
                }
                
                if (!isset($factoryConfig['class'])) {
                    $this->logCoreEvent('warning', "Factory {$type} has no class defined, skipping");
                    continue;
                }
                
                $this->logCoreEvent('info', "Registering microservice factory: {$type}");
                $this->registerMicroserviceFactory(
                    $type,
                    $factoryConfig['class'],
                    $factoryConfig['config'] ?? []
                );
            }
        } catch (\Throwable $e) {
            $this->logCoreEvent('error', 'Microservice initialization error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->handleServiceError('core', 'microservice_initialization', $e);
        }
    }

    /**
     * Register microservice factory
     * @param string $type
     * @param string $factoryClass
     * @param array $config
     * @return void
    */
    private function registerMicroserviceFactory(
        string $type,
        string $factoryClass,
        array $config
    ): void {
        if (!class_exists($factoryClass)) {
            throw new InitializationException("Factory class not found: {$factoryClass}");
        }

        $factory = new $factoryClass($config);
        if (!$factory instanceof MicroserviceFactoryInterface) {
            throw new InitializationException(
                "Factory must implement MicroserviceFactoryInterface"
            );
        }

        $this->microserviceFactories[$type] = $factory;
    }

    /**
     * Initialize single service with topology support
     * @param string $service
     * @return void
    */
    private function initializeService(string $service): void {
        if ($this->isServiceActive($service)) {
            return;
        }

        // gnode_client requires factory construction (StorageInterface + siteId args)
        // — never create via the generic new $class() path
        if ($service === 'gnode_client') {
            $this->initializegNodeClientEarly();
            return;
        }

        try {
            // Initialize dependencies first
            $dependencies = [];
            if (class_exists(TopologyParser::class)) {
                $dependencies = TopologyParser::resolveDependencies($service);
            }
            
            foreach ($dependencies as $dependency) {
                $this->initializeService($dependency);
            }

            // Transition to starting state
            $this->transitionServiceState($service, 'starting');

            // Get service configuration
            $serviceConfig = $this->getServiceConfig($service);
            if (!$serviceConfig) {
                throw new \RuntimeException("Service not found in topology: {$service}");
            }

            // Create service instance (without calling initialize yet)
            try {
                $instance = $this->createServiceInstanceWithoutInit($service, $serviceConfig);
            } catch (\Throwable $e) {
                $this->handleServiceError($service, 'creation', $e);
                return;
            }

            // Register service BEFORE initialize() to prevent recursion
            // This allows managers to call getService() for themselves during init
            $this->registerService($service, $instance);

            // NOW call initialize() - the service is already registered
            if (method_exists($instance, 'initialize')) {
                $initConfig = $this->buildManagerConfig($service, $serviceConfig);
                $instance->initialize($initConfig);
            }

            // TOOL-tier topology registration moved to deploy-time script (register-tools.sh)
            // See the project roadmap (Phase 1).

            // Transition to active state
            $this->transitionServiceState($service, 'active');

            $this->logCoreEvent('debug', 'Service initialized', [
                'service' => $service,
                'type' => $serviceConfig['type'] ?? 'unknown'
            ]);

        } catch (\Exception $e) {
            $this->transitionServiceState($service, 'failed');
            $this->logCoreEvent('error', 'Service initialization failed', [
                'service' => $service,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create service instance based on topology configuration
     * @param string $service
     * @param array $config
     * @return object
    */
    private function createServiceInstance(string $service, array $config): object {
        // Get service type from config
        $type = $config['type'] ?? 'singleton';
        $this->logCoreEvent('debug', "Creating service instance for {$service} of type {$type}");

        switch ($type) {
            case 'singleton':
                // Get class from config
                if (!isset($config['class'])) {
                    throw new InitializationException("Class not specified for service: {$service}");
                }

                // OPTIONAL PACKAGE RESOLUTION
                // Check if this is an optional extension manager and resolve
                // to the installed package or the shipped stub.
                $class = $config['class'];
                $resolvedClass = ExtensionResolver::resolve($service);

                if ($resolvedClass !== $service) {
                    // ExtensionResolver returned a resolved class
                    $isExtension = ExtensionResolver::isExtensionInstalled($service);
                    $this->logCoreEvent('debug', "Extension resolution for {$service}", [
                        'original_class' => $class,
                        'resolved_class' => $resolvedClass,
                        'mode' => $isExtension ? 'full' : 'stub'
                    ]);
                    $class = $resolvedClass;

                    // Update config with resolved class for downstream use
                    $config['class'] = $class;
                    $config['_extension_mode'] = $isExtension ? 'full' : 'stub';
                }

                $this->logCoreEvent('info', "Creating singleton instance of class {$class}");

                return $this->createSingletonInstance(
                    $class,
                    $config
                );
                
            case 'microservice':
                return $this->createMicroserviceInstance($service, $config);
                
            default:
                throw new InitializationException("Unknown service type: {$type}");
        }
    }

    /**
     * Determine cluster role for a service
     * @param string $service
     * @return string
    */
    private function determineClusterRole(string $service): string {
        $config = $this->findServiceConfig($service);
        
        // Single deployment type is automatically leader
        if (isset($config['deployment']) && $config['deployment'] === 'single') {
            return 'leader';
        }
        
        // Multi deployment types are followers
        if (isset($config['deployment']) && $config['deployment'] === 'multi') {
            return 'follower';
        }
        
        // Default to follower for safety
        return 'follower';
    }

    /**
     * Find service config
     * @param string $service
     * @return array
     */
    private function findServiceConfig(string $service): array {
        // Try to find service in topology
        $config = $this->findServiceInTopology($service);
        
        // Fall back to default if not found
        if ($config === null) {
            return [
                'deployment' => 'single'
            ];
        }
        
        return $config;
    }
    
    /**
     * Find service in topology across all categories
     * 
     * @param string $service Service identifier
     * @return array|null Service configuration or null if not found
     */
    /**
     * Find service in topology
     *
     * Looks up service config from the unified 'services' key in the topology.
     *
     * @param string $service Service identifier
     * @return array|null Service configuration or null if not found
     */
    private function findServiceInTopology(string $service): ?array {
        if (!isset($this->serviceTopology) || !is_array($this->serviceTopology)) {
            return null;
        }

        // Primary: unified 'services' key (canonical format)
        if (isset($this->serviceTopology['services'][$service])) {
            return $this->serviceTopology['services'][$service];
        }

        return null;
    }

    /**
     * Validate topology version
     * @param array $topology
     * @return void
    */
    private function validateTopologyVersion(array $topology): void {
        if (!isset($topology['version'])) {
            throw new \RuntimeException("Topology version must be specified");
        }
        
        // Get minimum supported version from configuration
        $minVersion = $this->getMinimumSupportedVersion();
        
        if (version_compare($topology['version'], $minVersion, '<')) {
            throw new \RuntimeException(
                "Topology version {$topology['version']} is not compatible with minimum supported version {$minVersion}"
            );
        }
    }

    /**
     * Get minimum supported version
     * @return string
    */
    private function getMinimumSupportedVersion(): string {
        // Get from config or use default
        return getenv('GCORE_MIN_VERSION') ?: 
            ($this->config['minimum_version'] ?? '0.1.0');
    }

    /**
     * Get environment-aware core log path
     * @return string Path for core logging
    */
    private function getCorePath(): string {
        if ($this->detectEnvironment() === 'wordpress') {
            return WP_CONTENT_DIR . '/gcore-logs/gcore-core.log';
        }
        return sys_get_temp_dir() . '/gcore-core.log';
    }

    /**
     * Create singleton service instance
     * @param string $class,
     * @param array $config
     * @return object
    */
    private function createSingletonInstance(string $class, array $config): object {
        // Check if class exists
        if (!class_exists($class)) {
            $this->logCoreEvent('error', "Service class not found: {$class}", [
                'available_classes' => get_declared_classes(),
                'autoload_functions' => spl_autoload_functions()
            ]);
            
            // For debugging, try to find similar classes
            $allClasses = get_declared_classes();
            $relevantClasses = array_filter($allClasses, function($c) use ($class) {
                return (stripos($c, 'manager') !== false);
            });
            
            if (!empty($relevantClasses)) {
                $this->logCoreEvent('info', "Similar manager classes found", [
                    'similar_classes' => $relevantClasses
                ]);
            }
            
            // Check if the file exists (for manual loading)
            $classPath = str_replace('\\', '/', $class) . '.php';
            $this->logCoreEvent('info', "Checking for class file: {$classPath}");
            
            // Try to load the class manually if necessary
            if (file_exists($classPath)) {
                $this->logCoreEvent('info', "Found class file, attempting to load: {$classPath}");
                require_once $classPath;
                if (class_exists($class)) {
                    $this->logCoreEvent('info', "Successfully loaded class: {$class}");
                } else {
                    $this->logCoreEvent('error', "Failed to load class from file: {$classPath}");
                }
            } else {
                $this->logCoreEvent('error', "Class file not found: {$classPath}");
            }
            
            // If still not found, throw exception
            if (!class_exists($class)) {
                throw new \RuntimeException("Service class not found: {$class}");
            }
        }

        try {
            // Get singleton instance if available
            if (method_exists($class, 'getInstance')) {
                $this->logCoreEvent('info', "Using getInstance() for {$class}");
                $instance = $class::getInstance();
            } else {
                $this->logCoreEvent('info', "Using constructor for {$class}");
                $instance = new $class();
            }

            // UNIVERSAL MANAGER CONFIG INJECTION
            // Inject site_id, node_id, storage config, and gnode_client into ALL managers
            // This ensures multi-tenant isolation and gNode integration works across all services
            // Includes: internal managers (\Modules\Managers\) AND extension packages (gCore\SEO\, etc.)
            $isExtensionPackage = isset($config['_extension_mode']);
            $isInternalManager = strpos($class, '\\Modules\\Managers\\') !== false;
            if ($isInternalManager || $isExtensionPackage) {
                // Prioritize top-level config (from gcore-loader.php) over nested core.site_id
                $config['site_id'] = $this->config['site_id'] ?? $this->config['core']['site_id'] ?? 'default';
                $config['node_id'] = $this->config['node_id'] ?? $this->config['core']['node_id'] ?? 'default';

                // Inject storage config from runtime config (for managers that use ValKey)
                // Priority: valkey > redis (backward compatibility)
                $storageConfig = $this->config['valkey'] ?? $this->config['redis'] ?? null;
                if ($storageConfig !== null) {
                    $config['storage'] = array_merge($config['storage'] ?? [], $storageConfig);
                }

                // UNIVERSAL gNode-Client injection - all managers get gNode-Client if available
                // This enables ALL managers to register with gNode topology, not just explicitly listed ones
                if ($this->hasService('gnode_client')) {
                    $config['gnode_client'] = $this->instances['gnode_client'] ?? null;
                }

                // Extract manager name for logging
                $managerName = substr($class, strrpos($class, '\\') + 1);
                $this->logCoreEvent('info', "Injecting runtime config into {$managerName}", [
                    'site_id' => $config['site_id'],
                    'node_id' => $config['node_id'],
                    'has_gnode_client' => isset($config['gnode_client']) && $config['gnode_client'] !== null
                ]);
            }

            // NOTE: gnode_client injection moved to UNIVERSAL MANAGER CONFIG INJECTION above
            // All managers now receive gnode_client automatically if available

            // UNIFIED STORAGE INJECTION
            // Inject the SINGLE pre-created gNodeStorageAdapter into all managers
            // This ensures all managers share ONE adapter instance for maximum efficiency:
            // - Single pooled connection via ConnectionPool::pconnect()
            // - Single adapter wrapper (no duplicate object creation)
            // - Consistent Key-Based Lua approach across all managers
            //
            // Supports:
            // - Internal managers (gCore\Modules\Managers\Base\*)
            // - Extension packages (gCore\SEO\*, gCore\Analytics\*, etc.) via _extension_mode flag
            $needsStorageInjection = $isExtensionPackage || in_array($class, [
                'gCore\\Modules\\Managers\\Base\\ErrorManager\\ErrorManager',
                'gCore\\Modules\\Managers\\Base\\SecurityManager\\SecurityManager',
                'gCore\\Modules\\Managers\\Base\\CacheManager\\CacheManager',
                'gCore\\Modules\\Managers\\Base\\AnalyticsManager\\AnalyticsManager',
                'gCore\\Modules\\Managers\\Base\\StateManager\\StateManager',
                'gCore\\Modules\\Managers\\Base\\ManifestManager\\ManifestManager',
                'gCore\\Modules\\Managers\\Base\\APIManager\\APIManager',
            ], true);

            if ($needsStorageInjection && $this->hasService('gnode_storage_adapter')) {
                // Inject the SINGLE shared adapter (not raw storage)
                $config['gnode_storage_adapter'] = $this->instances['gnode_storage_adapter'] ?? null;
                if ($config['gnode_storage_adapter']) {
                    $managerName = substr($class, strrpos($class, '\\') + 1);
                    $this->logCoreEvent('info', "Injecting shared gNodeStorageAdapter into {$managerName}", [
                        'class' => $class,
                        'site_id' => $config['site_id'] ?? 'default',
                        'node_id' => $config['node_id'] ?? 'default',
                        'mode' => $isExtensionPackage ? ($config['_extension_mode'] ?? 'full') : 'internal'
                    ]);
                }
            }

            // Initialize with configuration
            if (method_exists($instance, 'initialize')) {
                $this->logCoreEvent('info', "Initializing {$class} with configuration");
                $instance->initialize($config);
            } else {
                $this->logCoreEvent('info', "{$class} does not have initialize method");
            }

            return $instance;
        } catch (\Throwable $e) {
            $this->logCoreEvent('error', "Failed to create instance of {$class}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Create service instance WITHOUT calling initialize()
     * This is used to prevent recursion when managers call getService() during their own init.
     * Initialize is called separately after the service is registered.
     *
     * @param string $service Service name
     * @param array $config Service configuration
     * @return object Service instance (not yet initialized)
     */
    private function createServiceInstanceWithoutInit(string $service, array $config): object {
        $type = $config['type'] ?? 'singleton';
        $this->logCoreEvent('debug', "Creating service instance (no init) for {$service} of type {$type}");

        switch ($type) {
            case 'singleton':
                if (!isset($config['class'])) {
                    throw new InitializationException("Class not specified for service: {$service}");
                }
                $class = $config['class'];

                // OPTIONAL PACKAGE RESOLUTION
                $resolvedClass = ExtensionResolver::resolve($service);
                if ($resolvedClass !== $service) {
                    $isExtension = ExtensionResolver::isExtensionInstalled($service);
                    $this->logCoreEvent('debug', "Extension resolution (no-init) for {$service}", [
                        'original_class' => $class,
                        'resolved_class' => $resolvedClass,
                        'mode' => $isExtension ? 'full' : 'stub'
                    ]);
                    $class = $resolvedClass;
                    $config['class'] = $class;
                }

                if (!class_exists($class)) {
                    throw new \RuntimeException("Service class not found: {$class}");
                }

                // Get singleton instance without calling initialize
                if (method_exists($class, 'getInstance')) {
                    $instance = $class::getInstance();
                } else {
                    $instance = new $class();
                }

                return $instance;

            case 'microservice':
                return $this->createMicroserviceInstance($service, $config);

            default:
                throw new InitializationException("Unknown service type: {$type}");
        }
    }

    /**
     * Build configuration for manager initialization
     * Injects site_id, node_id, storage config, and gnode_client
     *
     * @param string $service Service name
     * @param array $config Base configuration from topology
     * @return array Configuration ready for initialize()
     */
    private function buildManagerConfig(string $service, array $config): array {
        // Inject site_id and node_id
        $config['site_id'] = $this->config['site_id'] ?? $this->config['core']['site_id'] ?? 'default';
        $config['node_id'] = $this->config['node_id'] ?? $this->config['core']['node_id'] ?? 'default';

        // Inject storage config
        $storageConfig = $this->config['valkey'] ?? $this->config['redis'] ?? null;
        if ($storageConfig !== null) {
            $config['storage'] = array_merge($config['storage'] ?? [], $storageConfig);
        }

        // Inject gNode-Client if available
        if ($this->hasService('gnode_client')) {
            $config['gnode_client'] = $this->instances['gnode_client'] ?? null;
        }

        // Inject shared gNodeStorageAdapter for managers that use it
        if ($this->hasService('gnode_storage_adapter')) {
            $config['gnode_storage_adapter'] = $this->instances['gnode_storage_adapter'] ?? null;
        }

        // Merge per-extension config from admin panel
        if (function_exists('get_option')) {
            $extConfig = get_option('gcore_extension_config', []);
            if (isset($extConfig[$service]) && is_array($extConfig[$service])) {
                $config = array_merge($config, $extConfig[$service]);
            }
        }

        return $config;
    }

    /**
     * Create microservice instance
     * @param string $service,
     * @param array $config
     * @return object
     */
    private function createMicroserviceInstance(string $service, array $config): object {
        $implementation = $config['implementation'] ?? 'default';
        
        if (!isset($this->microserviceFactories[$implementation])) {
            throw new InitializationException(
                "No factory registered for implementation: {$implementation}"
            );
        }

        $factory = $this->microserviceFactories[$implementation];
        
        // Validate configuration
        if (!$factory->validateConfig($config)) {
            throw new InitializationException("Invalid service configuration");
        }

        // Merge with default configuration
        $serviceConfig = $this->config['microservices']['services'][$service] ?? [];
        $serviceConfig = array_replace_recursive($serviceConfig, $config);

        return $factory->createService($service, $implementation, $serviceConfig);
    }

    /**
     * Register service in registry
     * @param string $service,
     * @param object $instance
     */
    private function registerService(string $service, object $instance): void {
        // Validate instance type
        if (method_exists($instance, 'isAvailable')) {
            $type = 'microservice';
        } else {
            $type = 'singleton';
        }

        $this->instances[$service] = $instance;

        $this->serviceRegistry[$service] = [
            'id' => $service,
            'state' => 'active',
            'type' => $type,
            'registered_at' => microtime(true),
            'deployment' => 'single',
            'role' => 'leader',
            'stats' => [
                'state_changes' => 0,
                'last_updated' => microtime(true)
            ]
        ];
    }

    /**
     * Check if service is active
     * @param string $service
     * @return bool
    * @api
    */
    public function isServiceActive(string $service): bool {
        return isset($this->serviceRegistry[$service]) && 
               $this->serviceRegistry[$service]['state'] === 'active';
    }

    /**
     * Get service instance
     * 
     * @param string $service Direct service identifier, or if not specified will use capability-based discovery
     * @param array $capabilities Required capabilities for capability-based discovery
     * @return object Service instance
     * @throws \RuntimeException If service is not found or not active
     * @api
     */
    public function getService(string $service = null, array $capabilities = []): object {
        $this->validateServiceState();

        // If capabilities are provided but no specific service, use capability-based discovery
        if ($service === null && !empty($capabilities)) {
            return $this->findServiceByCapabilities($capabilities);
        }

        // Resolve lowercase capability aliases to canonical class names; canonical names pass through unchanged
        if ($service !== null) {
            $service = self::CAPABILITY_ALIAS_MAP[$service] ?? $service;
        }

        // Check if service is already active
        if ($this->isServiceActive($service)) {
            return $this->instances[$service];
        }

        // Lazy-load: If service exists in topology but isn't initialized yet, initialize it
        $serviceConfig = $this->getServiceConfig($service);
        if ($serviceConfig !== null) {
            try {
                $this->initializeService($service);
                if ($this->isServiceActive($service)) {
                    return $this->instances[$service];
                }
            } catch (\Throwable $e) {
                throw new InitializationException(
                    "Failed to lazy-load service {$service}: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        // Service not found in topology
        throw new InitializationException("Unknown service: {$service}");
    }

    /**
     * Get the shared gNode storage instance
     *
     * Returns the pooled gNode storage that should be used by all managers.
     * This eliminates duplicate ValKey connections and improves performance.
     *
     * @return \gCore\gNode\Storage\ValKeyStorage|null gNode storage instance or null if not available
     * @api
     */
    public function getStorage(): ?\gCore\gNode\Storage\ValKeyStorage
    {
        return $this->instances['gnode_storage'] ?? null;
    }

    /**
     * Get the shared gNode storage adapter (singleton)
     *
     * Returns the SINGLE gNodeStorageAdapter instance that is shared by all managers.
     * This ensures maximum efficiency through connection pooling and consistent
     * Key-Based Lua approach across the entire application.
     *
     * @return \gCore\Modules\Storage\gNodeStorageAdapter|null Storage adapter or null if not available
     * @api
     */
    public function getStorageAdapter(): ?\gCore\Modules\Storage\gNodeStorageAdapter
    {
        return $this->instances['gnode_storage_adapter'] ?? null;
    }

    /**
     * Get status of all extension packages
     *
     * Returns installation status for each extension manager package.
     * Useful for admin dashboards, diagnostics, and feature gating.
     *
     * @return array Status for each extension manager with keys:
     *               - installed: bool - Whether extension package is installed
     *               - mode: string - 'full' or 'stub'
     *               - class: string - Fully qualified class name being used
     *               - package: string - Composer package name for installation
     * @api
     */
    public function getExtensionStatus(): array
    {
        return ExtensionResolver::getStatus();
    }

    /**
     * Check if a specific extension package is installed
     *
     * @param string $managerName Manager name (e.g., 'SEOManager')
     * @return bool True if extension package is available
     * @api
     */
    public function isExtensionInstalled(string $managerName): bool
    {
        return ExtensionResolver::isExtensionInstalled($managerName);
    }

    /**
     * Get installation instructions for missing extension packages
     *
     * @return array Manager name => Composer install command
     * @api
     */
    public function getMissingExtensionPackages(): array
    {
        return ExtensionResolver::getMissingPackages();
    }

    /**
     * Canonical capability-alias → service class-name map.
     * Single source of truth for both getService() alias resolution and
     * findServiceByCapabilities() fallback discovery.
     */
    private const CAPABILITY_ALIAS_MAP = [
        'security' => 'SecurityManager',
        'auth' => 'SecurityManager',
        'crypto' => 'SecurityManager',
        'cache' => 'CacheManager',
        'storage' => 'CacheManager',
        'template' => 'TemplateManager',
        'rendering' => 'TemplateManager',
        'tera' => 'TemplateManager',
        'format' => 'FormatManager',
        'detection' => 'FormatManager',
        'conversion' => 'FormatManager',
        'validation' => 'FormatManager',
        'error' => 'ErrorManager',
        'logging' => 'ErrorManager',
        'api' => 'APIManager',
        'rest' => 'APIManager'
    ];

    /**
     * Find service based on capability requirements
     * 
     * @param array $capabilities Required capabilities with minimum values
     * @return object Service instance
     * @throws \RuntimeException If no matching service is found
     * @api
     */
    public function findServiceByCapabilities(array $capabilities): object {
        // Check if gNode-Client is available
        if ($this->hasService('gnode_client')) {
            try {
                $gNodeClient = $this->getService('gnode_client');
                $serviceIds = $gNodeClient->geometricDiscover($capabilities);

                if (!empty($serviceIds)) {
                    // Map discovered IDs to gCore services
                    foreach ($serviceIds as $serviceId) {
                        // Extract service name from "tool:ServiceName" format
                        if (preg_match('/^tool:(.+)$/', $serviceId, $matches)) {
                            $serviceName = $matches[1];
                            if ($this->hasService($serviceName)) {
                                $this->logCoreEvent('info', 'Found service by capabilities', [
                                    'capabilities' => $capabilities,
                                    'service' => $serviceName
                                ]);
                                return $this->getService($serviceName);
                            }
                        }
                    }
                }

                $this->logCoreEvent('warning', 'No service found for capabilities', [
                    'capabilities' => $capabilities
                ]);
            } catch (\Throwable $e) {
                $this->logCoreEvent('error', 'gNode discovery failed', [
                    'capabilities' => $capabilities,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Fallback mechanism if GeometricTopology isn't available or fails
        $this->logCoreEvent('info', 'Using fallback service discovery');
        
        // Map capabilities to services with common capability patterns
        $capabilityToServiceMap = self::CAPABILITY_ALIAS_MAP;
        
        // Find the most relevant service based on capability keys
        $relevantServices = [];
        foreach ($capabilities as $capability => $value) {
            if (isset($capabilityToServiceMap[$capability])) {
                $service = $capabilityToServiceMap[$capability];
                if (!isset($relevantServices[$service])) {
                    $relevantServices[$service] = 0;
                }
                $relevantServices[$service]++;
            }
        }
        
        // Sort by relevance (most matching capabilities first)
        arsort($relevantServices);
        
        // Try to get the most relevant service
        foreach (array_keys($relevantServices) as $service) {
            if ($this->hasService($service) && $this->isServiceActive($service)) {
                $this->logCoreEvent('info', 'Found fallback service', [
                    'service' => $service,
                    'capabilities' => $capabilities
                ]);
                return $this->getService($service);
            }
        }
        
        throw new \RuntimeException('No service found matching the required capabilities');
    }
    
    /**
     * Register capabilities for a service
     *
     * Stores capabilities in the local service registry. Services can be discovered
     * by their capabilities using findServiceByCapability().
     *
     * @param string $serviceName The name of the service
     * @param array $capabilities The capabilities to register (capability => weight)
     * @param array $metadata Additional metadata about the service
     * @return bool Success status
     * @api
     */
    public function registerServiceCapabilities(string $serviceName, array $capabilities, array $metadata = []): bool {
        if (!isset($this->serviceRegistry[$serviceName])) {
            $this->serviceRegistry[$serviceName] = [];
        }

        // Update capabilities in service registry
        $this->serviceRegistry[$serviceName]['capabilities'] = $capabilities;

        if (!empty($metadata)) {
            $this->serviceRegistry[$serviceName]['metadata'] = array_merge(
                $this->serviceRegistry[$serviceName]['metadata'] ?? [],
                $metadata
            );
        }

        $this->logCoreEvent('debug', 'Registered capabilities for service', [
            'service' => $serviceName,
            'capability_count' => count($capabilities)
        ]);

        return true;
    }

    /**
     * Get service information from topology
     * @param string $service
     * @return array|null
    */
    private function getServiceInfo(string $service): ?array {
        if (isset($this->serviceTopology[$service])) {
            return $this->serviceTopology[$service];
        }
        
        // Check categories
        foreach ($this->serviceTopology as $category => $services) {
            if (isset($services[$service])) {
                return $services[$service];
            }
        }
        
        return null;
    }

    /**
     * Check if a service exists in the registry
     * @param string $service
     * @return bool
     * @api
     */
    public function hasService(string $service): bool {
        return isset($this->serviceRegistry[$service]);
    }

    /**
     * Stop service if no active dependents
     * @param string $service
     * @return bool
    */
    public function stopService(string $service): bool {
        if (!$this->isServiceActive($service)) {
            return false;
        }

        try {
            // Check for active dependents
            $dependents = $this->getServiceDependents($service);
            if (!empty($dependents)) {
                throw new \RuntimeException(
                    "Cannot stop service with active dependents: " . 
                    implode(', ', $dependents)
                );
            }

            // Transition to stopping state
            $this->transitionServiceState($service, 'stopping');

            $instance = $this->instances[$service];

            // Call shutdown method if exists
            if (method_exists($instance, 'shutdown')) {
                $instance->shutdown();
            }

            // Transition to stopped state
            $this->transitionServiceState($service, 'stopped');

            // Remove instance
            unset($this->instances[$service]);

            $this->logCoreEvent('info', 'Service stopped', [
                'service' => $service
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logCoreEvent('error', 'Service stop failed', [
                'service' => $service,
                'error' => $e->getMessage()
            ]);
            
            // Revert to active state
            $this->transitionServiceState($service, 'active');
            
            return false;
        }
    }

    /**
     * Transition service state
     * @param string $service,
     * @param string $newState
     * @return void
    */
    private function transitionServiceState(string $service, string $newState): void {
        if (!isset($this->serviceRegistry[$service])) {
            $this->serviceRegistry[$service] = [
                'id' => $service,
                'state' => 'pending',
                'stats' => [
                    'state_changes' => 0,
                    'last_updated' => microtime(true)
                ]
            ];
        }

        $currentState = $this->serviceRegistry[$service]['state'];
        
        // If already in the requested state, just return silently
        if ($currentState === $newState) {
            $this->logCoreEvent('debug', 'Service already in desired state', [
                'service' => $service,
                'state' => $currentState
            ]);
            return;
        }

        // Validate state transition
        if (!isset(self::SERVICE_STATES[$currentState]) || 
            !in_array($newState, self::SERVICE_STATES[$currentState])) {
            $this->logCoreEvent('warning', 'Invalid state transition requested', [
                'service' => $service, 
                'from' => $currentState,
                'to' => $newState,
                'allowed' => self::SERVICE_STATES[$currentState] ?? []
            ]);
            throw new \RuntimeException(
                "Invalid state transition: {$currentState} -> {$newState}"
            );
        }

        // Update state
        $this->serviceRegistry[$service]['state'] = $newState;
        $this->serviceRegistry[$service]['stats']['state_changes']++;
        $this->serviceRegistry[$service]['stats']['last_updated'] = microtime(true);

        $this->logCoreEvent('debug', 'Service state changed', [
            'service' => $service,
            'from' => $currentState,
            'to' => $newState
        ]);
    }

    /**
     * Get service status information
     * @param string $service
     * @return array
     * @api
     */
    public function getServiceStatus(string $service): array {
        if (!isset($this->serviceRegistry[$service])) {
            return ['status' => 'unknown'];
        }

        $registry = $this->serviceRegistry[$service];
        
        return [
            'id' => $registry['id'],
            'state' => $registry['state'],
            'type' => $registry['type'],
            'uptime' => microtime(true) - $registry['registered_at'],
            'stats' => $registry['stats']
        ];
    }

    /**
     * Get services dependent on specified service
     * @param string $service
     * @return array
     */
    private function getServiceDependents(string $service): array {
        $dependents = [];

        $services = $this->serviceTopology['services'] ?? [];
        foreach ($services as $name => $meta) {
            if (in_array($service, $meta['dependencies'] ?? [])) {
                if ($this->isServiceActive($name)) {
                    $dependents[] = $name;
                }
            }
        }
        return $dependents;
    }

    /**
     * get service configuration
     * @param string $service
     * @return array|null
     */
    private function getServiceConfig(string $service): ?array {
        // Handle special case for testing
        if ($service === 'ErrorManager' && defined('TESTING')) {
            return [
                'class' => 'ErrorManager',
                'dependencies' => []
            ];
        }
        
        if (!isset($this->serviceTopology) || !is_array($this->serviceTopology)) {
            $this->logCoreEvent('error', 'Service topology not loaded, cannot get service config', [
                'service' => $service
            ]);
            return null;
        }
        
        // Look up in unified 'services' key
        if (isset($this->serviceTopology['services'][$service])) {
            return $this->serviceTopology['services'][$service];
        }

        $this->logCoreEvent('warning', "Service {$service} not found in topology");
        return null;
    }

    /**
     * Get overall system status
     * @return array
     * @api
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'services' => array_map(function($service) {
                return $this->getServiceStatus($service);
            }, array_keys($this->serviceRegistry)),
            'config' => [
                'environment' => $this->detectEnvironment(),
                'debug' => $this->config['core']['debug'] ?? false
            ],
            'health' => $this->isHealthy(),
            'version' => $this->getVersion(),
            'metrics' => $this->metrics,
            'uptime' => $this->initialized ? 
                microtime(true) - ($this->serviceRegistry['core']['registered_at'] ?? microtime(true)) : 0
        ];
    }

    /**
     * Get the service registry (primarily for REST API)
     * @return array
     * @api
     */
    public function getServiceRegistry(): array {
        return $this->serviceRegistry;
    }

    /**
     * Log core event
     *
     * Level filter: events below `config.core.log_level` are
     * dropped at the door. Default threshold is 'warn' (quiet by default;
     * INFO + DEBUG suppressed). Operators raise to 'info' or 'debug' for
     * troubleshooting via /etc/geodineum/components/gCore/gcore.env or
     * the site's wp-config-geodineum.yaml `core.log_level` key.
     *
     * Storm on failure, silent on success.
     *
     * @param string $level   debug|info|warn|error
     * @param string $message
     * @param array  $context
     * @return void
     */
    private function logCoreEvent(string $level, string $message, array $context = []): void {
        static $rank = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
        $threshold = $this->config['core']['log_level'] ?? 'warn';
        if (($rank[$level] ?? 1) < ($rank[$threshold] ?? 2)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';

        $logMessage = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr
        );

        // In test mode, just output to stdout
        if (defined('TESTING')) {
            echo $logMessage;
            return;
        }

        // Get environment-aware log path
        $logPath = $this->getCorePath();

        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        error_log($logMessage, 3, $logPath);

        if (isset($this->config['core']['debug']) && $this->config['core']['debug']) {
            error_log($logMessage);
        }
    }

    /**
     * Get core version
     * @return string
     */
    public function getVersion(): string {
        return self::CORE_VERSION;
    }

    /**
     * Handle service errors
     * @param string $service Service identifier
     * @param string $operation Operation that failed
     * @param \Throwable $e Exception that was thrown
     * @throws \RuntimeException Re-throws with contextual information
     */
    private function handleServiceError(
        string $service,
        string $operation,
        \Throwable $e
    ): void {
        $context = [
            'service' => $service,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
        
        $this->logCoreEvent('error', 'Service operation failed', $context);
        
        throw new \RuntimeException(
            "Service operation failed: {$operation}: {$e->getMessage()}",
            0,
            $e
        );
    }
    
    /**
     * Shutdown gCore framework with proper resource cleanup
     * 
     * @param bool $graceful Whether to perform a graceful shutdown (true) or forced (false)
     * @return bool Shutdown status
     * @api
     */
    public function shutdown(bool $graceful = true): bool {
        if (!$this->initialized) {
            return true;
        }
        
        $isDebug = defined('WP_DEBUG') && WP_DEBUG || 
                  getenv('GCORE_DEBUG') === 'true' || 
                  ($this->config['core']['debug'] ?? false);
                  
        if ($isDebug) {
            $this->logCoreEvent('info', 'Starting framework shutdown', [
                'graceful' => $graceful
            ]);
        }
        
        $shutdownStart = microtime(true);
        
        try {
            // Shutdown all services in reverse priority order
            $services = $this->serviceRegistry;
            
            // Sort by priority in descending order (to shutdown in reverse order)
            // This ensures that dependent services are shut down before their dependencies
            uasort($services, function($a, $b) {
                $priorityA = isset($a['priority']) ? $a['priority'] : 0;
                $priorityB = isset($b['priority']) ? $b['priority'] : 0;
                return $priorityB - $priorityA;
            });
            
            foreach (array_keys($services) as $serviceName) {
                $serviceStart = microtime(true);
                
                try {
                    if ($isDebug) {
                        $this->logCoreEvent('info', "Shutting down service: {$serviceName}");
                    }
                    
                    $this->shutdownService($serviceName);
                    
                    if ($isDebug) {
                        $serviceTime = microtime(true) - $serviceStart;
                        $this->logCoreEvent('info', "Service shutdown complete", [
                            'service' => $serviceName,
                            'time_ms' => round($serviceTime * 1000, 2)
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->logCoreEvent('error', 'Error shutting down service', [
                        'service' => $serviceName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    // Continue with other services even if this one fails
                }
            }
            
            // Clean up any remaining resources
            $this->cleanupResources();
            
            $this->initialized = false;
            
            if ($isDebug) {
                $totalTime = microtime(true) - $shutdownStart;
                $this->logCoreEvent('info', 'Framework shutdown complete', [
                    'time_ms' => round($totalTime * 1000, 2)
                ]);
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logCoreEvent('error', 'Error during framework shutdown', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // If this is a forced shutdown, try to clean up anyway
            if (!$graceful) {
                $this->cleanupResources();
                $this->initialized = false;
            }
            
            return false;
        }
    }
    
    /**
     * Clean up any remaining resources
     * 
     * @return void
     */
    private function cleanupResources(): void {
        // Close any open ValKey connections
        if (isset($GLOBALS['GCORE_VALKEY_CONNECTIONS']) && is_array($GLOBALS['GCORE_VALKEY_CONNECTIONS'])) {
            foreach ($GLOBALS['GCORE_VALKEY_CONNECTIONS'] as $connection) {
                if ($connection instanceof \Redis) {
                    try {
                        $connection->close();
                    } catch (\Exception $e) {
                        // Ignore errors during shutdown
                    }
                }
            }
            $GLOBALS['GCORE_VALKEY_CONNECTIONS'] = [];
        }
        
        // Clean up any temporary files created during runtime
        $tmpFiles = glob(sys_get_temp_dir() . '/gcore_*');
        foreach ($tmpFiles as $file) {
            if (is_file($file) && is_writable($file)) {
                @unlink($file);
            }
        }
        
        // Reset internal state
        $this->instances = [];
        $this->serviceRegistry = [];
    }
    
    /**
     * Shutdown a specific service
     * 
     * @param string $serviceName Service name
     * @return bool Shutdown status
     */
    private function shutdownService(string $serviceName): bool {
        if (!isset($this->serviceRegistry[$serviceName])) {
            return true;
        }
        
        if ($this->serviceRegistry[$serviceName]['state'] === 'active') {
            // Transition to stopping state
            $this->transitionServiceState($serviceName, 'stopping');
            
            // Get service instance
            $instance = $this->instances[$serviceName] ?? null;
            if ($instance && method_exists($instance, 'shutdown')) {
                try {
                    $instance->shutdown();
                } catch (\Exception $e) {
                    $this->logCoreEvent('warning', 'Error during service shutdown', [
                        'service' => $serviceName,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Transition to stopped state
            $this->transitionServiceState($serviceName, 'stopped');
        }
        
        return true;
    }

    /**
     * Get module configuration
     * @return array Configuration array
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     * @param array $config New configuration to merge
     */
    public function updateConfig(array $config): void {
        $this->config = array_replace_recursive($this->config, $config);
    }

    /**
     * Check if module is initialized
     * @return bool Initialization status
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Clean up on shutdown
     */
    public function __destruct() {
        if ($this->initialized) {
            // Stop all services in reverse dependency order
            $services = array_reverse(array_keys($this->serviceRegistry));
            foreach ($services as $service) {
                try {
                    $this->stopService($service);
                } catch (\Throwable $e) {
                    $this->logCoreEvent('error', 'Service shutdown failed', [
                        'service' => $service,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function __clone() {}

    public function __wakeup() {
        throw new \RuntimeException("Cannot unserialize singleton");
    }

    /**
     * Update manager state (used in initialization)
     * @param string $state
     * @return void
     */
    private function updateManagerState(string $state): void {
        // Set up core registry entry if it doesn't exist
        if (!isset($this->serviceRegistry['core'])) {
            $this->serviceRegistry['core'] = [
                'id' => 'core',
                'state' => 'pending',
                'type' => 'singleton',
                'registered_at' => microtime(true),
                'deployment' => 'single',
                'role' => 'leader',
                'priority' => 1000, // Highest priority
                'stats' => [
                    'state_changes' => 0,
                    'last_updated' => microtime(true)
                ]
            ];
        }
        
        $currentState = $this->serviceRegistry['core']['state'];
        
        // If already in the requested state, just return silently
        if ($currentState === $state) {
            return;
        }
        
        // Update state
        $this->serviceRegistry['core']['state'] = $state;
        $this->serviceRegistry['core']['stats']['state_changes']++;
        $this->serviceRegistry['core']['stats']['last_updated'] = microtime(true);
        
        // Log the state change
        $this->logCoreEvent('info', 'Core state changed', [
            'from' => $currentState,
            'to' => $state
        ]);
    }

    /**
     * Check if manager is active
     * @param string $manager
     * @return bool
     */
    private function isManagerActive(string $manager): bool {
        return isset($this->serviceRegistry[$manager]) && 
            $this->serviceRegistry[$manager]['state'] === 'active';
    }

    /**
     * Create manager instance
     * @param string $manager
     * @param array $config
     * @return object
     * @throws InitializationException If manager creation fails
     */
    private function createManagerInstance(string $manager, array $config): object {
        try {
            $className = $config['class'] ?? null;
            if (!$className) {
                throw new InitializationException("No class specified for manager: {$manager}");
            }

            // OPTIONAL PACKAGE RESOLUTION
            // Check if this is a extension manager and resolve to installed package or stub
            $resolvedClass = ExtensionResolver::resolve($manager);
            if ($resolvedClass !== $manager) {
                $isExtension = ExtensionResolver::isExtensionInstalled($manager);
                $this->logCoreEvent('info', "Extension resolution for {$manager}", [
                    'original_class' => $className,
                    'resolved_class' => $resolvedClass,
                    'mode' => $isExtension ? 'full' : 'stub'
                ]);
                $className = $resolvedClass;
                $config['class'] = $className;
                $config['_extension_mode'] = $isExtension ? 'full' : 'stub';
            }

            // Check if class exists
            if (!class_exists($className)) {
                throw new InitializationException("Manager class not found: {$className}");
            }

            // Create instance
            if (method_exists($className, 'getInstance')) {
                $instance = $className::getInstance();
            } else {
                $instance = new $className();
            }

            // Initialize the manager
            if (method_exists($instance, 'initialize')) {
                $instance->initialize($config);
            }

            return $instance;
        } catch (\Throwable $e) {
            $this->logCoreEvent('error', 'Failed to create manager instance', [
                'manager' => $manager,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new InitializationException(
                "Failed to create manager instance: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Register manager
     * @param string $manager
     * @param object $instance
     * @return void
     */
    private function registerManager(string $manager, object $instance): void {
        // Add to instances
        $this->instances[$manager] = $instance;
        
        // Add to service registry
        $this->serviceRegistry[$manager] = [
            'id' => $manager,
            'state' => 'active',
            'type' => 'singleton',
            'registered_at' => microtime(true),
            'deployment' => 'single',
            'role' => 'leader',
            'priority' => $this->getManagerPriority($manager),
            'stats' => [
                'state_changes' => 0,
                'last_updated' => microtime(true)
            ]
        ];
        
        $this->logCoreEvent('info', 'Manager registered', [
            'manager' => $manager,
            'class' => get_class($instance)
        ]);
    }
    
    /**
     * Get manager priority (higher priority means it will be shut down later)
     * @param string $manager
     * @return int
     */
    private function getManagerPriority(string $manager): int {
        $priorities = [
            'ErrorManager' => 900,   // High priority - should be one of the last to shut down
            'SecurityManager' => 800,
            'CacheManager' => 700,
            'TemplateManager' => 650,
            'FormatManager' => 625,
            'APIManager' => 600
        ];
        
        return $priorities[$manager] ?? 500; // Default priority
    }
}
