<?php
/**
 * StateManager - Advanced State Management System
 * 
 * Provides centralized state management with real-time synchronization,
 * history tracking, and performance optimization for the NiertoCube theme.
 * 
 * Architectural Principles:
 * - Immutable state transitions
 * - Observable state patterns
 * - Transactional integrity
 * - History-aware operations
 * 
 * @package     NiertoCube
 * @subpackage  Modules\Level2
 * @version     2.0.0
 * @since       2.0.0
 */

namespace NiertoCube\Modules;  

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Cache\CacheStrategyInterface;
use NiertoCube\Modules\ErrorManager;
use NiertoCube\Modules\ResourceManager;
use NiertoCube\Modules\CacheManager;

class StateManager implements ModuleInterface, \ArrayAccess {
    /** @var StateManager Singleton instance */
    private static $instance = null;

    /** @var array Current state container */
    private $state = [];

    /** @var array State observers registry */
    private $observers = [];

    /** @var array State history stack */
    private $history = [];

    /** @var array Active transactions */
    private $transactions = [];

    /** @var array State validators */
    private $validators = [];

    /** @var array Middleware stack */
    private $middleware = [];

    /** @var array Performance metrics */
    private $metrics = [];

    /** @var bool Transaction flag */
    private $inTransaction = false;

    /** @var string|null Current transaction ID */
    private $currentTransaction = null;

    /** @var array Configuration settings */
    private $config = [
        'history_depth' => 50,
        'sync_interval' => 100,
        'persistence_driver' => 'local',
        'compression_threshold' => 1024,
        'debug_mode' => false
    ];

    /** @var array Integration handlers */
    private $integrations = [];

/** @var ErrorManager|null Error manager instance */
    private $errorManager = null;

    /** @var CacheStrategyInterface Cache implementation */
    private $cacheInterface = null;

    /** @var CacheManager|null Cache manager instance */
    private $cacheManager = null;

    /** @var ResourceManager Resource manager instance */
    private $resourceManager = null;

    /** @var bool Initialization state */
    private $initialized = false;

     /**
     * Initialize core dependencies
     * 
     * @throws \RuntimeException If critical dependencies unavailable
     */
    private function initializeCore(): void {
        try {
            // Get NiertoCore instance
            $core = \NiertoCube\Core\NiertoCore::getInstance();

            // Initialize Error Manager (Level 0 dependency)
            $this->errorManager = $core->getModule('Error');
            if (!$this->errorManager) {
                throw new \RuntimeException('ErrorManager not available');
            }

            // Initialize Cache Manager (Level 0 dependency)
            $this->cacheManager = $core->getModule('Cache');
            if (!$this->cacheManager) {
                throw new \RuntimeException('CacheManager not available');
            }
            
            // Get appropriate cache strategy from CacheManager
            $this->cache = $this->cacheManager->getStrategy();
            
            // Initialize Resource Manager (Level 1 dependency)
            if (isset($this->config['resource_management']) && $this->config['resource_management']) {
                $this->resourceManager = $core->getModule('Resource');
                if (!$this->resourceManager) {
                    $this->errorManager->logWarning(
                        'resource_manager_unavailable',
                        'ResourceManager not available but requested in config'
                    );
                }
            }

            // Register state persistence hooks
            $this->registerStatePersistence();

        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Core initialization failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Register WordPress hooks for state management
     */
    private function registerHooks(): void {
        add_action('wp_loaded', [$this, 'restoreState']);
        add_action('shutdown', [$this, 'persistState']);
        
        if ($this->config['debug_mode']) {
            add_action('admin_init', [$this, 'registerDebugHandlers']);
        }
    }

    /**
     * Initialize metrics tracking
     */
    private function initializeMetrics(): void {
        $this->metrics = [
            'state_updates' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'transaction_count' => 0,
            'observer_notifications' => 0,
            'start_time' => microtime(true)
        ];
    }

    /**
     * Register state persistence mechanisms
     */
    private function registerStatePersistence(): void {
        if (!$this->cacheManager) {
            return;
        }

        // Register cache group for state data
        $this->cacheManager->registerGroup('state', [
            'ttl' => DAY_IN_SECONDS,
            'version' => '1.0',
            'compression' => true
        ]);

        // Set up automatic state persistence
        if ($this->config['persistence_driver'] === 'cache') {
            add_action('shutdown', function() {
                $this->persistToCache();
            }, 20);
        }
    }

    /**
     * Persist state to cache
     */
    private function persistToCache(): void {
        if (!$this->cacheManager || empty($this->state)) {
            return;
        }

        try {
            $this->cacheManager->set(
                'state_data',
                [
                    'state' => $this->state,
                    'timestamp' => time(),
                    'checksum' => $this->calculateStateChecksum()
                ],
                'state'
            );
        } catch (\Exception $e) {
            $this->errorManager->logError(
                'state_persistence_failed',
                $e->getMessage()
            );
        }
    }

    /**
     * Calculate state checksum for integrity verification
     */
    private function calculateStateChecksum(): string {
        return hash('xxh3', serialize($this->state));
    }

    /**
     * Prevent direct instantiation
     */
    private function __construct() {}

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the state manager
     * 
     * @param array $config Configuration options
     * @throws \RuntimeException If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Merge configuration
            $this->config = array_merge($this->config, $config);

            // Initialize core systems
            $this->initializeCore();

            // Register WordPress hooks
            $this->registerHooks();

            // Set up performance monitoring
            $this->initializeMetrics();

            $this->initialized = true;

        } catch (\Exception $e) {
            throw new \RuntimeException(
                'StateManager initialization failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Get module configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if module is initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get module status
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'state_count' => count($this->state),
            'observer_count' => count($this->observers),
            'history_depth' => count($this->history),
            'in_transaction' => $this->inTransaction,
            'memory_usage' => memory_get_usage(true),
            'metrics' => $this->metrics
        ];
    }

    /**
     * ArrayAccess implementation for direct state access
     */
    public function offsetExists($offset): bool {
        return isset($this->state[$offset]);
    }

    public function offsetGet($offset) {
        return $this->state[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void {
        $this->setState($offset, $value);
    }

    public function offsetUnset($offset): void {
        $this->removeState($offset);
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}