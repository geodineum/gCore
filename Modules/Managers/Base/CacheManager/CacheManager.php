<?php
declare(strict_types=1);
namespace gCore\Modules\Managers\Base\CacheManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\Utils\SelfContainedErrorHandler;
use gCore\Modules\Core\Exceptions\{
    InitializationException,
    StorageException,
    ValidationException
};
use gCore\Modules\Core\Adapters\Shared\ValKeyStorage;
use gCore\Modules\Core\Shared\CacheScripts;
use gCore\Modules\Storage\Interfaces\StorageInterface;
use gCore\Modules\Storage\StorageFactory;
use gCore\Modules\Storage\gNodeDetector;

require_once __DIR__ . '/Traits/StreamCapabilities.php';
use gCore\Modules\Managers\Base\CacheManager\Traits\StreamCapabilities;

/**
 * Cache Manager Implementation
 *
 * Provides distributed caching capabilities with multi-site isolation.
 */
class CacheManager implements ModuleInterface {
    use StreamCapabilities;
    use ManagerConfigTrait;

    private const DEFAULTS = [
        'storage' => [
            'host' => null,
            'port' => null,
            'timeout' => 2.0,
            'prefix' => 'cache_',
            'auth' => null,
        ],
        'default_ttl' => 3600,
        'site_id' => 'default',
        'node_id' => 'node1',
        'use_gnode' => true,
    ];
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Storage adapter
     */
    private $storage = null;
    
    /**
     * Configuration
     */
    private $config = [];
    
    /**
     * Initialization state
     */
    private $initialized = false;
    
    /**
     * Node metadata for multi-tenant isolation
     */
    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];
    
    /**
     * Cache metrics
     */
    private $metrics = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0
    ];

    /**
     * gNode-Client instance for gNode integration
     */
    private $gNodeClient = null;

    /**
     * Use gNode integration flag
     */
    private $useGNode = false;
    
    /**
     * Get singleton instance
     * 
     * @return ModuleInterface CacheManager instance
     */
    public static function getInstance(): ModuleInterface {
        if (self::$instance === null) {
            self::$instance = new self();
            // No auto-initialization to prevent circular dependencies
            // The gCore framework will handle initialization with proper config
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
    }
    
    /**
     * Capability vector for GeometricTopology integration
     * 
     * @var array
     */
    private $capabilityVector = [
        'cache' => 1.0,
        'storage' => 0.8,
        'errors' => 0.2,
        'logging' => 0.3
    ];
    
    /**
     * Initialize CacheManager with configuration
     * 
     * @param array $config Configuration options
     * @return void
     * @throws InitializationException If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Layered config: DEFAULTS → ValKey (defaults + per-site) → $config arg
            $siteId = (string)($config['site_id'] ?? self::DEFAULTS['site_id']);
            $valkeyConfig = [];
            $cfgStorage = $this->gcoreResolveStorage($config);
            if ($cfgStorage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($cfgStorage, $siteId, 'CacheManager');
            }
            $this->config = array_merge(self::DEFAULTS, $valkeyConfig, $config);

            // Sensitive: storage.auth reads from secrets keyspace with
            // fallback to $config passthrough (legacy direct-injection path).
            if (empty($this->config['storage']['auth']) && $cfgStorage !== null) {
                $secret = $this->gcoreGetSecret($cfgStorage, $siteId, 'CacheManager', 'storage.auth');
                if ($secret !== null) {
                    $this->config['storage']['auth'] = $secret;
                }
            }

            // Set node metadata for multi-tenant isolation
            $this->nodeMetadata = [
                'site_id' => $this->config['site_id'],
                'node_id' => $this->config['node_id']
            ];

            // Check for gNode-Client integration
            if (isset($config['gnode_client']) &&
                $config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface &&
                ($this->config['use_gnode'] ?? true)) {
                $this->gNodeClient = $config['gnode_client'];
                $this->useGNode = true;

                // Log successful gNode integration
                SelfContainedErrorHandler::logInfo(
                    'CacheManager',
                    'initialize',
                    'CacheManager using gNode integration',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'node_id' => $this->nodeMetadata['node_id']
                    ]
                );
            } else {
                $this->useGNode = false;

                // Log legacy mode
                SelfContainedErrorHandler::logWarning(
                    'CacheManager',
                    'initialize',
                    'CacheManager using legacy mode',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'node_id' => $this->nodeMetadata['node_id'],
                        'reason' => !isset($config['gnode_client']) ? 'no_gnode_client' :
                                   (!($config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface) ? 'invalid_gnode_client' :
                                   'gnode_disabled')
                    ]
                );
            }
            
            // Initialize storage
            $this->initializeStorage();

            $this->initialized = true;
            
            // Log successful initialization
            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'initialize',
                'Successfully initialized CacheManager',
                [
                    'site_id' => $this->nodeMetadata['site_id'],
                    'node_id' => $this->nodeMetadata['node_id']
                ]
            );
            
        } catch (\Exception $e) {
            // Log error using SelfContainedErrorHandler
            SelfContainedErrorHandler::logError(
                'CacheManager',
                'initialize',
                $e,
                [
                    'site_id' => $this->nodeMetadata['site_id'] ?? 'default',
                    'node_id' => $this->nodeMetadata['node_id'] ?? 'node1',
                    'config' => SelfContainedErrorHandler::safeJsonEncode($this->config ?? [])
                ]
            );
            
            throw new InitializationException(
                'Failed to initialize CacheManager: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Initialize storage for cache data
     *
     * Uses shared gNode storage when available (eliminates duplicate connections),
     * falls back to StorageInterface adapters when gNode is not available.
     *
     * @return void
     * @throws StorageException If storage initialization fails
     */
    private function initializeStorage(): void {
        try {
            // SHARED ADAPTER PATH: Use pre-created gNodeStorageAdapter (maximum efficiency)
            // This adapter is created ONCE in gCore and shared by ALL managers
            if (isset($this->config['gnode_storage_adapter']) && $this->config['gnode_storage_adapter'] !== null) {
                // Use the SINGLE shared adapter directly - no wrapping needed
                $this->storage = $this->config['gnode_storage_adapter'];

                // Notify gNodeDetector if gnode_client was also injected
                if ($this->useGNode && $this->gNodeClient !== null) {
                    gNodeDetector::setClient($this->gNodeClient);
                }

                SelfContainedErrorHandler::logInfo(
                    'CacheManager',
                    'initializeStorage',
                    'Using shared gNodeStorageAdapter (pooled, Key-Based Lua)',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'mode' => 'shared_adapter'
                    ]
                );
            } elseif ($this->useGNode && $this->gNodeClient !== null) {
                // LEGACY PATH: Use gNode-Client's storage wrapped with adapter
                $this->storage = new \gCore\Modules\Storage\gNodeStorageAdapter(
                    $this->gNodeClient->getStorage(),
                    $this->nodeMetadata['site_id'] ?? 'default',
                    [
                        'debug' => $this->config['debug'] ?? false,
                        'lua_enabled' => true,
                        'metrics_level' => 1
                    ]
                );

                // Notify gNodeDetector that client is available
                gNodeDetector::setClient($this->gNodeClient);

                SelfContainedErrorHandler::logInfo(
                    'CacheManager',
                    'initializeStorage',
                    'Using gNode-Client storage with Key-Based Lua adapter (legacy)',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'mode' => 'gnode_client_lua'
                    ]
                );
            } else {
                // FREE TIER PATH: Use StorageFactory to get best available adapter
                // Priority: WordPress Transients > APCu (future) > Memory
                $this->storage = StorageFactory::create([
                    'prefix' => $this->config['storage']['prefix'] ?? 'gcore_cache_',
                    'site_id' => $this->nodeMetadata['site_id']
                ]);

                $adapterType = $this->storage->getType();

                SelfContainedErrorHandler::logInfo(
                    'CacheManager',
                    'initializeStorage',
                    'Using default-tier storage adapter',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'adapter' => $adapterType,
                        'mode' => 'free_tier'
                    ]
                );

                // Log upgrade hint (non-blocking)
                if ($adapterType === 'memory') {
                    SelfContainedErrorHandler::logWarning(
                        'CacheManager',
                        'initializeStorage',
                        'Using non-persistent memory storage. Consider enabling gNode for production.',
                        ['upgrade_info' => gNodeDetector::getUpgradePrompt()]
                    );
                }
            }
        } catch (\Exception $e) {
            throw new StorageException(
                'Failed to initialize storage: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Execute a ValKey script
     * 
     * @param string $scriptName Script name
     * @param array $keys Keys for the script
     * @param array $args Arguments for the script
     * @return mixed Script result
     * @throws StorageException If script execution fails
     */
    protected function runScript(string $scriptName, array $keys = [], array $args = []): mixed {
        if (!$this->initialized) {
            throw new StorageException('CacheManager not initialized');
        }
        
        try {
            $script = CacheScripts::getScript($scriptName);
            if (!$script) {
                throw new StorageException("Script not found: {$scriptName}");
            }
            
            return $this->storage->evalScript(
                $script['script'],
                $keys,
                $args
            );
        } catch (\Exception $e) {
            $this->logError('script_execution', $scriptName, $e->getMessage());
            throw new StorageException("Script execution failed: {$scriptName}", 0, $e);
        }
    }
    
    /**
     * Get cache key with proper namespace (legacy format)
     * Format: cache:{site_id}:{node_id}:{key}
     *
     * @param string $key Original key
     * @return string Namespaced key
     */
    protected function getKey(string $key): string {
        return "cache:{$this->nodeMetadata['site_id']}:{$this->nodeMetadata['node_id']}:{$key}";
    }

    /**
     * Get cache key with gNode namespace format
     * Format: {site_id}:cache:key (with literal braces for cluster slot hashing)
     *
     * Note: The curly braces around site_id are REQUIRED for the Lua functions
     * to detect already-prefixed keys and avoid double-prefixing.
     *
     * @param string $key Original key
     * @return string Namespaced key
     */
    protected function getGNodeKey(string $key): string {
        $siteId = $this->nodeMetadata['site_id'];
        return "{{$siteId}}:cache:{$key}";
    }

    // ========================================================================
    // gNode INTEGRATION METHODS - Direct FCALL to gNode ValKey Functions
    // ========================================================================

    /**
     * Set cache value using gNode direct FCALL
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     * @throws StorageException If operation fails
     */
    private function gNodeSet(string $key, $value, int $ttl): bool {
        try {
            if ($this->gNodeClient === null) {
                throw new StorageException("gNode-Client not available");
            }

            $gNodeKey = $this->getGNodeKey($key);
            $serialized = $this->serialize($value);

            // Use gNode-Client fcall for secure FCALL-only operations
            // gNode_CACHE_SET args: key, value, ttl, site_id, nx, xx
            $result = $this->gNodeClient->fcall(
                'GNODE_CACHE_SET',
                [],  // No key arguments (function handles namespacing)
                [
                    $gNodeKey,
                    $serialized,
                    (string)$ttl,
                    $this->nodeMetadata['site_id'],
                    'false',  // nx (only if not exists)
                    'false'   // xx (only if exists)
                ]
            );

            return $result === 'OK' || $result === true;

        } catch (\Throwable $e) {
            $this->logError('gnode_set', $key, $e->getMessage());
            throw new StorageException("gNode cache set failed: {$key}", 0, $e);
        }
    }

    /**
     * Get cache value using gNode direct FCALL
     * Includes automatic metrics tracking (hits/misses) server-side
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    private function gNodeGet(string $key) {
        try {
            if ($this->gNodeClient === null) {
                $this->metrics['misses']++;
                return null;
            }

            $gNodeKey = $this->getGNodeKey($key);

            // Use gNode-Client fcall for secure FCALL-only operations
            // gNode_CACHE_GET args: key, site_id
            $result = $this->gNodeClient->fcall(
                'GNODE_CACHE_GET',
                [],
                [
                    $gNodeKey,
                    $this->nodeMetadata['site_id']
                ]
            );

            if ($result === null || $result === false) {
                $this->metrics['misses']++;
                return null;
            }

            $this->metrics['hits']++;

            // gNode may return JSON-decoded value if it was JSON
            // Otherwise returns string that needs unserialization
            return is_string($result) ? $this->unserialize($result) : $result;

        } catch (\Throwable $e) {
            $this->metrics['misses']++;
            $this->logError('gnode_get', $key, $e->getMessage());
            return null;
        }
    }

    /**
     * Delete cache value using gNode direct FCALL
     *
     * @param string $key Cache key
     * @return bool Success status (true if deleted, false if not found or error)
     */
    private function gNodeDelete(string $key): bool {
        try {
            if ($this->gNodeClient === null) {
                return false;
            }

            $gNodeKey = $this->getGNodeKey($key);

            // Use gNode-Client fcall for secure FCALL-only operations
            $result = $this->gNodeClient->fcall(
                'GNODE_CACHE_DEL',
                [],
                [
                    $gNodeKey,
                    $this->nodeMetadata['site_id']
                ]
            );

            return (int)$result === 1;

        } catch (\Throwable $e) {
            $this->logError('gnode_delete', $key, $e->getMessage());
            return false;
        }
    }

    /**
     * Check if cache key exists using gNode direct FCALL
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    private function gNodeExists(string $key): bool {
        try {
            if ($this->gNodeClient === null) {
                return false;
            }

            $gNodeKey = $this->getGNodeKey($key);

            // Use gNode-Client fcall for secure FCALL-only operations
            $result = $this->gNodeClient->fcall(
                'GNODE_CACHE_EXISTS',
                [],
                [
                    $gNodeKey,
                    $this->nodeMetadata['site_id']
                ]
            );

            return (int)$result === 1;

        } catch (\Throwable $e) {
            $this->logError('gnode_exists', $key, $e->getMessage());
            return false;
        }
    }

    /**
     * Increment cache value using gNode direct FCALL
     *
     * @param string $key Cache key
     * @param int $by Amount to increment by
     * @return int|false New value or false on failure
     */
    private function gNodeIncrement(string $key, int $by = 1) {
        try {
            if ($this->gNodeClient === null) {
                return false;
            }

            $gNodeKey = $this->getGNodeKey($key);

            // Use gNode-Client fcall for secure FCALL-only operations
            // gNode_CACHE_INCR args: key, amount, site_id
            $result = $this->gNodeClient->fcall(
                'GNODE_CACHE_INCR',
                [],
                [
                    $gNodeKey,
                    (string)$by,
                    $this->nodeMetadata['site_id']
                ]
            );

            return is_numeric($result) ? (int)$result : false;

        } catch (\Throwable $e) {
            $this->logError('gnode_increment', $key, $e->getMessage());
            return false;
        }
    }

    /**
     * Decrement cache value using gNode direct FCALL
     *
     * @param string $key Cache key
     * @param int $by Amount to decrement by
     * @return int|false New value or false on failure
     */
    private function gNodeDecrement(string $key, int $by = 1) {
        try {
            if ($this->gNodeClient === null) {
                return false;
            }

            $gNodeKey = $this->getGNodeKey($key);

            // Use gNode-Client fcall for secure FCALL-only operations
            // gNode_CACHE_DECR args: key, amount, site_id
            $result = $this->gNodeClient->fcall(
                'GNODE_CACHE_DECR',
                [],
                [
                    $gNodeKey,
                    (string)$by,
                    $this->nodeMetadata['site_id']
                ]
            );

            return is_numeric($result) ? (int)$result : false;

        } catch (\Throwable $e) {
            $this->logError('gnode_decrement', $key, $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // gNode BATCH OPERATIONS - Multi-Key Operations
    // ========================================================================

    /**
     * Get multiple cache values using gNode batch operation
     * batched into a single round-trip vs sequential gets
     *
     * @param array $keys Array of cache keys
     * @return array Key-value pairs (only existing keys returned)
     * @throws StorageException If operation fails
     */
    private function gNodeGetMultiple(array $keys): array {
        if (empty($keys)) {
            return [];
        }

        try {
            // Convert to gNode keys
            $gNodeKeys = [];
            foreach ($keys as $key) {
                $gNodeKeys[] = $this->getGNodeKey($key);
            }

            // Use CANONICAL gNodeClient::batchCacheGet()
            // This uses GNODE_BATCH_MGET_RESP3 - the optimized RESP3 batch function
            if ($this->gNodeClient !== null && method_exists($this->gNodeClient, 'batchCacheGet')) {
                $results = $this->gNodeClient->batchCacheGet($gNodeKeys);

                // Process results - returns associative array key => value
                $values = [];
                foreach ($keys as $index => $originalKey) {
                    $gNodeKey = $gNodeKeys[$index];
                    $value = $results[$gNodeKey] ?? null;
                    if ($value !== null && $value !== false) {
                        $values[$originalKey] = is_string($value) ? $this->unserialize($value) : $value;
                        $this->metrics['hits']++;
                    } else {
                        $this->metrics['misses']++;
                    }
                }
                return $values;
            }

            // Fallback: Use gNode-Client fcall for FCALL-only operations
            if ($this->gNodeClient === null) {
                throw new StorageException("gNode-Client required for batch get operations");
            }

            $results = $this->gNodeClient->fcall(
                'GNODE_CACHE_MGET',
                [],
                [
                    json_encode($gNodeKeys),
                    $this->nodeMetadata['site_id']
                ]
            );

            // Process results - RESP3 array returned
            $values = [];
            if (is_array($results)) {
                foreach ($results as $index => $value) {
                    $originalKey = $keys[$index] ?? null;
                    if ($originalKey !== null && $value !== null && $value !== false) {
                        $values[$originalKey] = is_string($value) ? $this->unserialize($value) : $value;
                        $this->metrics['hits']++;
                    } else {
                        $this->metrics['misses']++;
                    }
                }
            }

            return $values;

        } catch (\Throwable $e) {
            $this->logError('gnode_mget', implode(',', $keys), $e->getMessage());
            throw new StorageException("gNode batch get failed", 0, $e);
        }
    }

    /**
     * Set multiple cache values using gNode batch operation
     * batched into a single round-trip vs sequential sets
     *
     * @param array $items Associative array of key => value pairs
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     * @throws StorageException If operation fails
     */
    private function gNodeSetMultiple(array $items, int $ttl): bool {
        if (empty($items)) {
            return true;
        }

        try {
            // Prepare items with gNode keys and serialized values
            $gNodeItems = [];
            foreach ($items as $key => $value) {
                $gNodeKey = $this->getGNodeKey($key);
                $serialized = $this->serialize($value);
                $gNodeItems[$gNodeKey] = $serialized;
            }

            // Use CANONICAL gNodeClient::batchCacheSet()
            // This uses GNODE_BATCH_MSET_RESP3 - the optimized RESP3 batch function
            if ($this->gNodeClient !== null && method_exists($this->gNodeClient, 'batchCacheSet')) {
                return $this->gNodeClient->batchCacheSet($gNodeItems, $ttl);
            }

            // Fallback: Use gNode-Client fcall for FCALL-only operations
            if ($this->gNodeClient === null) {
                throw new StorageException("gNode-Client required for batch set operations");
            }

            $result = $this->gNodeClient->fcall(
                'GNODE_CACHE_MSET',
                [],
                [
                    json_encode($gNodeItems),
                    (string)$ttl,
                    $this->nodeMetadata['site_id']
                ]
            );

            return $result === 'OK' || $result === true;

        } catch (\Throwable $e) {
            $this->logError('gnode_mset', implode(',', array_keys($items)), $e->getMessage());
            throw new StorageException("gNode batch set failed", 0, $e);
        }
    }

    /**
     * Delete multiple cache values using gNode batch operation
     * batched into a single round-trip vs sequential deletes
     *
     * @param array $keys Array of cache keys
     * @return bool Success status (true if all deleted)
     * @throws StorageException If operation fails
     */
    private function gNodeDeleteMultiple(array $keys): bool {
        if (empty($keys)) {
            return true;
        }

        try {
            // Convert to gNode keys
            $gNodeKeys = [];
            foreach ($keys as $key) {
                $gNodeKeys[] = $this->getGNodeKey($key);
            }

            // Use CANONICAL gNodeClient::batchCacheDel()
            // This uses GNODE_BATCH_MDEL_RESP3 - the optimized RESP3 batch function
            if ($this->gNodeClient !== null && method_exists($this->gNodeClient, 'batchCacheDel')) {
                $deletedCount = $this->gNodeClient->batchCacheDel($gNodeKeys);
                return $deletedCount === count($keys);
            }

            // Fallback: Use gNode-Client fcall for FCALL-only operations
            if ($this->gNodeClient === null) {
                throw new StorageException("gNode-Client required for batch delete operations");
            }

            $deletedCount = $this->gNodeClient->fcall(
                'GNODE_CACHE_MDEL',
                [],
                [
                    json_encode($gNodeKeys),
                    $this->nodeMetadata['site_id']
                ]
            );

            // Return true if all keys were deleted
            return (int)$deletedCount === count($keys);

        } catch (\Throwable $e) {
            $this->logError('gnode_mdel', implode(',', $keys), $e->getMessage());
            throw new StorageException("gNode batch delete failed", 0, $e);
        }
    }

    // ========================================================================
    // EXECUTEBATCH INTEGRATION - Batch Operations (single round-trip)
    // ========================================================================

    /**
     * Batch set multiple cache values using gNode executeBatch
     * batched into a single round-trip vs sequential operations
     *
     * @param array $items Associative array of key => ['value' => mixed, 'ttl' => int]
     * @return array Results array with success status for each key
     * @throws StorageException If gNode not available or operation fails
     * @api
     */
    public function batchSet(array $items): array {
        if (empty($items)) {
            return [];
        }

        // gNode-only feature (requires extension)
        $this->requireGNode('batch_operations');

        try {
            // Prepare batch commands
            $commands = [];
            foreach ($items as $key => $data) {
                $this->validateKey($key);

                $value = $data['value'] ?? $data;
                $ttl = is_array($data) && isset($data['ttl']) ? $data['ttl'] : 0;

                $gNodeKey = $this->getGNodeKey($key);
                $serialized = $this->serialize($value);

                // Add to batch using cache_set command
                $commands[] = [
                    'cmd' => 'cache_set',
                    'params' => [
                        'key' => $gNodeKey,
                        'value' => $serialized,
                        'ttl' => $ttl
                    ]
                ];
            }

            // Execute batch
            $results = $this->gNodeClient->executeBatch($commands);

            // Process results
            $output = [];
            $index = 0;
            foreach (array_keys($items) as $key) {
                $result = $results[$index] ?? null;
                $output[$key] = [
                    'success' => isset($result['status']) && $result['status'] === 'ok',
                    'result' => $result['result'] ?? null
                ];
                if ($output[$key]['success']) {
                    $this->metrics['sets']++;
                }
                $index++;
            }

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'batchSet',
                "Batch set completed",
                [
                    'count' => count($items),
                    'successful' => array_sum(array_column($output, 'success'))
                ]
            );

            return $output;

        } catch (\Throwable $e) {
            $this->logError('batch_set', implode(',', array_keys($items)), $e->getMessage());
            throw new StorageException(
                "Batch set failed",
                0,
                $e
            );
        }
    }

    /**
     * Batch get multiple cache values using gNode executeBatch
     * batched into a single round-trip vs sequential operations
     *
     * @param array $keys Array of cache keys
     * @return array Associative array of key => value pairs (null for missing keys)
     * @throws StorageException If gNode not available or operation fails
     * @api
     */
    public function batchGet(array $keys): array {
        if (empty($keys)) {
            return [];
        }

        // gNode-only feature (requires extension)
        $this->requireGNode('batch_operations');

        try {
            // Validate and prepare batch commands
            $commands = [];
            foreach ($keys as $key) {
                $this->validateKey($key);

                $gNodeKey = $this->getGNodeKey($key);

                // Add to batch using cache_get command
                $commands[] = [
                    'cmd' => 'cache_get',
                    'params' => [
                        'key' => $gNodeKey
                    ]
                ];
            }

            // Execute batch
            $results = $this->gNodeClient->executeBatch($commands);

            // Process results
            $output = [];
            $index = 0;
            foreach ($keys as $key) {
                $result = $results[$index] ?? null;

                if (isset($result['status']) && $result['status'] === 'ok' && isset($result['result']['value'])) {
                    $value = $result['result']['value'];
                    $output[$key] = is_string($value) ? $this->unserialize($value) : $value;
                    $this->metrics['hits']++;
                } else {
                    $output[$key] = null;
                    $this->metrics['misses']++;
                }
                $index++;
            }

            return $output;

        } catch (\Throwable $e) {
            $this->logError('batch_get', implode(',', $keys), $e->getMessage());
            throw new StorageException(
                "Batch get failed",
                0,
                $e
            );
        }
    }

    /**
     * Batch delete multiple cache values using gNode executeBatch
     * batched into a single round-trip vs sequential operations
     *
     * @param array $keys Array of cache keys
     * @return array Results array with success status for each key
     * @throws StorageException If gNode not available or operation fails
     * @api
     */
    public function batchDelete(array $keys): array {
        if (empty($keys)) {
            return [];
        }

        // gNode-only feature (requires extension)
        $this->requireGNode('batch_operations');

        try {
            // Validate and prepare batch commands
            $commands = [];
            foreach ($keys as $key) {
                $this->validateKey($key);

                $gNodeKey = $this->getGNodeKey($key);

                // Add to batch using cache_delete command
                $commands[] = [
                    'cmd' => 'cache_delete',
                    'params' => [
                        'key' => $gNodeKey
                    ]
                ];
            }

            // Execute batch
            $results = $this->gNodeClient->executeBatch($commands);

            // Process results
            $output = [];
            $index = 0;
            foreach ($keys as $key) {
                $result = $results[$index] ?? null;
                $output[$key] = [
                    'success' => isset($result['status']) && $result['status'] === 'ok',
                    'deleted' => isset($result['result']['deleted']) ? $result['result']['deleted'] : false
                ];
                if ($output[$key]['success']) {
                    $this->metrics['deletes']++;
                }
                $index++;
            }

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'batchDelete',
                "Batch delete completed",
                [
                    'count' => count($keys),
                    'successful' => array_sum(array_column($output, 'success'))
                ]
            );

            return $output;

        } catch (\Throwable $e) {
            $this->logError('batch_delete', implode(',', $keys), $e->getMessage());
            throw new StorageException(
                "Batch delete failed",
                0,
                $e
            );
        }
    }

    // ========================================================================
    // CONTENT OPERATIONS (PHASE 4)
    // ========================================================================

    /**
     * Store content with automatic minification and compression
     * Uses gNode contentStore command for server-side processing
     *
     * @param string $key Cache key
     * @param string $content Content to store (HTML/CSS/JS)
     * @param string $contentType MIME type (text/html, text/css, application/javascript)
     * @param bool $minify Enable minification (default: true)
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return array Result with success status and metadata
     * @throws StorageException If gNode not available or operation fails
     * @api
     */
    public function storeContent(
        string $key,
        string $content,
        string $contentType = 'text/html',
        bool $minify = true,
        int $ttl = 0
    ): array {
        // Validate
        $this->validateKey($key);

        // gNode-only feature (requires extension) - minification requires gNode
        $this->requireGNode('content_minification');

        try {
            $gNodeKey = $this->getGNodeKey($key);

            // Use gNode-Client contentStore command
            $result = $this->gNodeClient->contentStore(
                $gNodeKey,
                $content,
                $contentType,
                $minify,
                $ttl
            );

            // Log success
            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'storeContent',
                "Stored content with minification",
                [
                    'key' => $key,
                    'size_original' => strlen($content),
                    'size_stored' => $result['size'] ?? 'unknown',
                    'minified' => $minify,
                    'content_type' => $contentType
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('store_content', $key, $e->getMessage());
            throw new StorageException(
                "Failed to store content: {$key}",
                0,
                $e
            );
        }
    }

    /**
     * Retrieve stored content with automatic decompression
     * Uses gNode contentRetrieve command
     *
     * @param string $key Cache key
     * @return array|null Content data or null if not found
     * @api
     */
    public function retrieveContent(string $key): ?array {
        $this->validateKey($key);

        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Content operations require gNode integration (useGNode=true)'
            );
        }

        try {
            $gNodeKey = $this->getGNodeKey($key);

            // Use gNode-Client contentRetrieve command
            $result = $this->gNodeClient->contentRetrieve($gNodeKey);

            if (!$result || !isset($result['success']) || !$result['success']) {
                $this->metrics['misses']++;
                return null;
            }

            $this->metrics['hits']++;

            return [
                'content' => $result['content'],
                'content_type' => $result['content_type'] ?? 'text/html',
                'etag' => $result['etag'] ?? null,
                'size' => $result['size'] ?? strlen($result['content']),
                'compressed' => $result['compressed'] ?? false
            ];

        } catch (\Throwable $e) {
            $this->metrics['misses']++;
            $this->logError('retrieve_content', $key, $e->getMessage());
            return null;
        }
    }

    /**
     * Store template fragment with dependency tracking
     * Uses native gNode templateFragment method for type-safe operation
     *
     * @param string $id Template identifier
     * @param string $content Template content (Tera syntax)
     * @param array $dependencies Template dependencies (include references)
     * @param array $variables Template variables
     * @param int $ttl Time to live in seconds (null for no expiration)
     * @return array Result with success status
     * @throws StorageException If operation fails
     * @api
     */
    public function storeTemplate(
        string $id,
        string $content,
        array $dependencies = [],
        array $variables = [],
        ?int $ttl = null
    ): array {
        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Template operations require gNode integration (useGNode=true)'
            );
        }

        try {
            // Use native templateFragment method (type-safe, faster)
            $result = $this->gNodeClient->templateFragment(
                $id,
                $content,
                $dependencies,
                $variables,
                $ttl
            );

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'storeTemplate',
                "Registered template fragment using native method",
                [
                    'id' => $id,
                    'dependencies' => $dependencies,
                    'size' => strlen($content),
                    'method' => 'native_templateFragment'
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('store_template', $id, $e->getMessage());
            throw new StorageException(
                "Failed to store template: {$id}",
                0,
                $e
            );
        }
    }

    /**
     * Store asset bundle with automatic optimization
     * Uses native gNode assetBundle method for type-safe operation
     *
     * @param string $bundleId Bundle identifier
     * @param array $assets Array of asset identifiers or content
     * @param string $bundleType Bundle type ('css', 'js', or 'mixed')
     * @param bool $minify Enable minification
     * @param int|null $ttl Time to live in seconds (null for no expiration)
     * @return array Result with bundle details
     * @throws StorageException If operation fails
     * @api
     */
    public function storeAssetBundle(
        string $bundleId,
        array $assets,
        string $bundleType = 'mixed',
        bool $minify = true,
        ?int $ttl = null
    ): array {
        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Asset bundling requires gNode integration (useGNode=true)'
            );
        }

        try {
            // Use native assetBundle method (type-safe, faster)
            $result = $this->gNodeClient->assetBundle(
                $bundleId,
                $assets,
                $bundleType,
                $minify,
                $ttl
            );

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'storeAssetBundle',
                "Created asset bundle using native method",
                [
                    'bundle_id' => $bundleId,
                    'bundle_type' => $bundleType,
                    'asset_count' => count($assets),
                    'minified' => $minify,
                    'method' => 'native_assetBundle'
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('store_asset_bundle', $bundleId, $e->getMessage());
            throw new StorageException(
                "Failed to store asset bundle: {$bundleId}",
                0,
                $e
            );
        }
    }

    // ========================================================================
    // BROADCAST INVALIDATION - Distributed Cache Coherence
    // ========================================================================

    /**
     * Broadcast cache invalidation message to all nodes
     * Enables distributed cache coherence across multiple servers
     *
     * @param array $keys Array of cache keys to invalidate
     * @param string $reason Reason for invalidation (for logging)
     * @return string|false Message ID on success, false on failure
     * @throws StorageException If gNode not available
     * @api
     */
    public function broadcastInvalidate(array $keys, string $reason = 'manual'): string|false {
        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Broadcast operations require gNode integration (useGNode=true)'
            );
        }

        try {
            // Prepare broadcast message
            $messageType = 'cache_invalidate';
            $fields = [
                'keys' => $keys,
                'reason' => $reason,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'timestamp' => time()
            ];

            // Send broadcast message
            $messageId = $this->gNodeClient->writeBroadcastMessage($messageType, $fields);

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'broadcastInvalidate',
                "Broadcast cache invalidation",
                [
                    'keys' => $keys,
                    'reason' => $reason,
                    'message_id' => $messageId
                ]
            );

            return $messageId;

        } catch (\Throwable $e) {
            $this->logError('broadcast_invalidate', implode(',', $keys), $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast clear all cache message to all nodes
     *
     * @param string $reason Reason for clear (for logging)
     * @return string|false Message ID on success, false on failure
     * @throws StorageException If gNode not available
     * @api
     */
    public function broadcastClearAll(string $reason = 'manual'): string|false {
        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Broadcast operations require gNode integration (useGNode=true)'
            );
        }

        try {
            // Prepare broadcast message
            $messageType = 'cache_clear_all';
            $fields = [
                'reason' => $reason,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'timestamp' => time()
            ];

            // Send broadcast message
            $messageId = $this->gNodeClient->writeBroadcastMessage($messageType, $fields);

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'broadcastClearAll',
                "Broadcast clear all cache",
                [
                    'reason' => $reason,
                    'message_id' => $messageId
                ]
            );

            return $messageId;

        } catch (\Throwable $e) {
            $this->logError('broadcast_clear_all', 'all', $e->getMessage());
            return false;
        }
    }

    /**
     * Listen for cache invalidation broadcasts from other nodes
     * Processes invalidation messages and updates local cache
     *
     * @param int $count Number of messages to read (default: 10)
     * @param int $blockMs Time to block waiting for messages in milliseconds (default: 100)
     * @return array Array of processed messages
     * @throws StorageException If gNode not available
     * @api
     */
    public function listenForInvalidations(int $count = 10, int $blockMs = 100): array {
        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Broadcast operations require gNode integration (useGNode=true)'
            );
        }

        try {
            // Get broadcast reader
            $broadcastReader = $this->gNodeClient->getBroadcastReader();

            // Read cache-related broadcast messages
            $messages = $broadcastReader->readBroadcastMessages($count, $blockMs, 'cache_*');

            $processed = [];

            foreach ($messages as $message) {
                $messageType = $message['type'] ?? '';
                $fields = $message['fields'] ?? [];

                // Skip messages from our own node
                if (isset($fields['site_id'], $fields['node_id']) &&
                    $fields['site_id'] === $this->nodeMetadata['site_id'] &&
                    $fields['node_id'] === $this->nodeMetadata['node_id']) {
                    continue;
                }

                // Process based on message type
                if ($messageType === 'cache_invalidate' && isset($fields['keys'])) {
                    // Invalidate specified keys locally
                    $keys = $fields['keys'];
                    foreach ($keys as $key) {
                        $this->delete($key);
                    }

                    $processed[] = [
                        'type' => 'invalidate',
                        'keys' => $keys,
                        'source_node' => $fields['node_id'] ?? 'unknown'
                    ];

                    SelfContainedErrorHandler::logInfo(
                        'CacheManager',
                        'listenForInvalidations',
                        "Processed cache invalidation broadcast",
                        [
                            'keys' => $keys,
                            'source_node' => $fields['node_id'] ?? 'unknown'
                        ]
                    );
                } elseif ($messageType === 'cache_clear_all') {
                    // Clear all cache locally
                    $this->clear();

                    $processed[] = [
                        'type' => 'clear_all',
                        'source_node' => $fields['node_id'] ?? 'unknown'
                    ];

                    SelfContainedErrorHandler::logInfo(
                        'CacheManager',
                        'listenForInvalidations',
                        "Processed clear all cache broadcast",
                        [
                            'source_node' => $fields['node_id'] ?? 'unknown'
                        ]
                    );
                }
            }

            return $processed;

        } catch (\Throwable $e) {
            $this->logError('listen_invalidations', 'broadcast', $e->getMessage());
            return [];
        }
    }

    /**
     * Invalidate cache keys locally and broadcast to other nodes
     * Convenience method combining local delete and broadcast
     *
     * @param array $keys Array of cache keys to invalidate
     * @param bool $broadcast Whether to broadcast to other nodes (default: true)
     * @return bool Success status
     */
    public function invalidate(array $keys, bool $broadcast = true): bool {
        $success = true;

        // Delete locally first
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        // Broadcast to other nodes if enabled
        if ($broadcast && $this->useGNode) {
            $this->broadcastInvalidate($keys, 'invalidate_call');
        }

        return $success;
    }

    // ========================================================================
    // CONNECTION POOLING - Native RESP3 Mode
    // ========================================================================

    /**
     * Enable native RESP3 mode for lower protocol overhead
     * Bypasses encoding/decoding for better performance
     *
     * @return bool Success status
     * @throws StorageException If gNode not available
     * @api
     */
    public function enableNativeMode(): bool {
        if (!$this->useGNode) {
            throw new StorageException(
                'Native mode requires gNode integration (useGNode=true)'
            );
        }

        try {
            $result = $this->gNodeClient->enableNativeMode();

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'enableNativeMode',
                "Enabled native RESP3 mode",
                [
                    'site_id' => $this->nodeMetadata['site_id'],
                    'success' => $result
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('enable_native_mode', 'connection', $e->getMessage());
            throw new StorageException(
                "Failed to enable native mode",
                0,
                $e
            );
        }
    }

    /**
     * Disable native RESP3 mode
     * Reverts to standard encoding/decoding
     *
     * @return bool Success status
     * @throws StorageException If gNode not available
     * @api
     */
    public function disableNativeMode(): bool {
        if (!$this->useGNode) {
            throw new StorageException(
                'Native mode requires gNode integration (useGNode=true)'
            );
        }

        try {
            $result = $this->gNodeClient->disableNativeMode();

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'disableNativeMode',
                "Disabled native RESP3 mode",
                [
                    'site_id' => $this->nodeMetadata['site_id'],
                    'success' => $result
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('disable_native_mode', 'connection', $e->getMessage());
            throw new StorageException(
                "Failed to disable native mode",
                0,
                $e
            );
        }
    }

    /**
     * Check if native RESP3 mode is enabled
     *
     * @return bool True if native mode is enabled
     * @api
     */
    public function isNativeMode(): bool {
        if (!$this->useGNode) {
            return false;
        }

        try {
            return $this->gNodeClient->isNativeMode();

        } catch (\Throwable $e) {
            $this->logError('is_native_mode', 'connection', $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // FORMAT MANAGER INTEGRATION - Data Validation
    // ========================================================================

    /**
     * Get or create the format manager instance
     * Enables data validation and format checking
     *
     * @return \gCore\gNode\Format\FormatManager|null Format manager instance or null if gNode not available
     * @throws StorageException If gNode not available
     */
    public function getFormatManager(): ?\gCore\gNode\Format\FormatManager {
        if (!$this->useGNode) {
            throw new StorageException(
                'Format manager requires gNode integration (useGNode=true)'
            );
        }

        try {
            $formatManager = $this->gNodeClient->getFormatManager();

            SelfContainedErrorHandler::logDebug(
                'CacheManager',
                'getFormatManager',
                "Retrieved format manager",
                [
                    'site_id' => $this->nodeMetadata['site_id']
                ]
            );

            return $formatManager;

        } catch (\Throwable $e) {
            $this->logError('get_format_manager', 'format', $e->getMessage());
            throw new StorageException(
                "Failed to get format manager",
                0,
                $e
            );
        }
    }

    /**
     * Register a custom data format for validation
     *
     * @param string $formatId Format identifier
     * @param array $schema Format schema definition
     * @return bool Success status
     * @throws StorageException If gNode not available
     * @api
     */
    public function registerFormat(string $formatId, array $schema): bool {
        if (!$this->useGNode) {
            throw new StorageException(
                'Format registration requires gNode integration (useGNode=true)'
            );
        }

        try {
            $formatManager = $this->getFormatManager();

            if ($formatManager === null) {
                return false;
            }

            $result = $formatManager->registerFormat($formatId, $schema);

            SelfContainedErrorHandler::logInfo(
                'CacheManager',
                'registerFormat',
                "Registered format",
                [
                    'format_id' => $formatId,
                    'success' => $result
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('register_format', $formatId, $e->getMessage());
            throw new StorageException(
                "Failed to register format: {$formatId}",
                0,
                $e
            );
        }
    }

    /**
     * Validate data against a registered format
     *
     * @param string $formatId Format identifier
     * @param mixed $data Data to validate
     * @return bool True if valid
     * @throws ValidationException If data is invalid
     * @throws StorageException If gNode not available
     * @api
     */
    public function validateData(string $formatId, $data): bool {
        if (!$this->useGNode) {
            throw new StorageException(
                'Data validation requires gNode integration (useGNode=true)'
            );
        }

        try {
            $formatManager = $this->getFormatManager();

            if ($formatManager === null) {
                // Cannot validate without format manager
                return true;
            }

            $result = $formatManager->validate($formatId, $data);

            if (!$result['valid']) {
                $errors = implode(', ', $result['errors'] ?? []);
                throw new ValidationException(
                    "Data validation failed for format '{$formatId}': {$errors}"
                );
            }

            return true;

        } catch (ValidationException $e) {
            // Re-throw validation exceptions
            throw $e;

        } catch (\Throwable $e) {
            $this->logError('validate_data', $formatId, $e->getMessage());
            throw new StorageException(
                "Failed to validate data for format: {$formatId}",
                0,
                $e
            );
        }
    }

    /**
     * Set cache value with format validation
     * Validates data against registered format before caching
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param string $formatId Format identifier for validation
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     * @throws ValidationException If validation fails
     * @throws StorageException If operation fails
     * @api
     */
    public function setWithValidation(string $key, $value, string $formatId, int $ttl = 0): bool {
        // Validate data first
        $this->validateData($formatId, $value);

        // Set cache value
        return $this->set($key, $value, $ttl);
    }

    /**
     * Batch set with format validation
     * Validates all items against their formats before batch caching
     *
     * @param array $items Array of [key => ['value' => mixed, 'format' => string, 'ttl' => int]]
     * @return array Results array with success status for each key
     * @throws ValidationException If any validation fails
     * @throws StorageException If gNode not available or operation fails
     */
    public function batchSetWithValidation(array $items): array {
        if (empty($items)) {
            return [];
        }

        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Batch operations require gNode integration (useGNode=true)'
            );
        }

        try {
            // Validate all items first
            foreach ($items as $key => $data) {
                if (isset($data['format'])) {
                    $value = $data['value'] ?? $data;
                    $this->validateData($data['format'], $value);
                }
            }

            // All validations passed, proceed with batch set
            return $this->batchSet($items);

        } catch (\Throwable $e) {
            $this->logError('batch_set_with_validation', implode(',', array_keys($items)), $e->getMessage());
            throw new StorageException(
                "Batch set with validation failed",
                0,
                $e
            );
        }
    }

    /**
     * Get all registered formats
     *
     * @return array Array of format identifiers
     * @throws StorageException If gNode not available
     */
    public function getRegisteredFormats(): array {
        if (!$this->useGNode) {
            throw new StorageException(
                'Format operations require gNode integration (useGNode=true)'
            );
        }

        try {
            $formatManager = $this->getFormatManager();

            if ($formatManager === null) {
                return [];
            }

            return $formatManager->getRegisteredFormats();

        } catch (\Throwable $e) {
            $this->logError('get_registered_formats', 'all', $e->getMessage());
            return [];
        }
    }

    /**
     * Get format schema definition
     *
     * @param string $formatId Format identifier
     * @return array|null Format schema or null if not found
     * @throws StorageException If gNode not available
     */
    public function getFormatSchema(string $formatId): ?array {
        if (!$this->useGNode) {
            throw new StorageException(
                'Format operations require gNode integration (useGNode=true)'
            );
        }

        try {
            $formatManager = $this->getFormatManager();

            if ($formatManager === null) {
                return null;
            }

            return $formatManager->getFormatSchema($formatId);

        } catch (\Throwable $e) {
            $this->logError('get_format_schema', $formatId, $e->getMessage());
            return null;
        }
    }

    /**
     * Get server-side cache statistics from gNode
     * Combines local and gNode daemon metrics
     *
     * @return array cache statistics
     */
    public function getGNodeStats(): array {
        if (!$this->useGNode || $this->gNodeClient === null) {
            // Return only local metrics if not using gNode
            return $this->getMetrics();
        }

        try {
            // Use gNode-Client fcall for secure FCALL-only operations
            $gNodeStats = $this->gNodeClient->fcall(
                'GNODE_CACHE_STATS',
                [],
                [
                    $this->nodeMetadata['site_id']
                ]
            );

            // Merge with local metrics
            $localMetrics = $this->getMetrics();

            return [
                'local' => $localMetrics,
                'gnode' => $gNodeStats ?? [],
                'combined' => [
                    'hits' => ($localMetrics['hits'] ?? 0) + ($gNodeStats['hits'] ?? 0),
                    'misses' => ($localMetrics['misses'] ?? 0) + ($gNodeStats['misses'] ?? 0),
                    'sets' => ($localMetrics['sets'] ?? 0) + ($gNodeStats['sets'] ?? 0),
                    'deletes' => ($localMetrics['deletes'] ?? 0) + ($gNodeStats['deletes'] ?? 0),
                    'hit_ratio' => $this->calculateHitRatio(
                        ($localMetrics['hits'] ?? 0) + ($gNodeStats['hits'] ?? 0),
                        ($localMetrics['misses'] ?? 0) + ($gNodeStats['misses'] ?? 0)
                    )
                ]
            ];

        } catch (\Throwable $e) {
            $this->logError('gnode_stats', 'all', $e->getMessage());
            // Fallback to local metrics
            return $this->getMetrics();
        }
    }

    /**
     * Calculate hit ratio
     *
     * @param int $hits Number of hits
     * @param int $misses Number of misses
     * @return float Hit ratio percentage
     */
    private function calculateHitRatio(int $hits, int $misses): float {
        $total = $hits + $misses;
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }

    // ========================================================================
    // PUBLIC API METHODS
    // ========================================================================

    /**
     * Set cache item
     *
     * @param string $key The key
     * @param mixed $value The value
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     * @api
     */
    public function set(string $key, $value, int $ttl = 0): bool {
        $this->validateKey($key);
        $this->metrics['sets']++;

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeSet($key, $value, $ttl);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            $storageKey = $this->getKey($key);
            return $this->storage->set($storageKey, $value, $ttl);
        } catch (\Exception $e) {
            $this->logError('set', $key, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get cache item
     *
     * @param string $key The key
     * @return mixed The value or null if not found
     * @api
     */
    public function get(string $key) {
        $this->validateKey($key);

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeGet($key);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            $storageKey = $this->getKey($key);
            $value = $this->storage->get($storageKey);

            if ($value === null) {
                $this->metrics['misses']++;
                return null;
            }

            $this->metrics['hits']++;
            return $value;
        } catch (\Exception $e) {
            $this->logError('get', $key, $e->getMessage());
            $this->metrics['misses']++;
            return null;
        }
    }
    
    /**
     * Delete cache item
     *
     * @param string $key The key
     * @return bool Success status
     * @api
     */
    public function delete(string $key): bool {
        $this->validateKey($key);
        $this->metrics['deletes']++;

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeDelete($key);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            $storageKey = $this->getKey($key);
            return $this->storage->delete($storageKey);
        } catch (\Exception $e) {
            $this->logError('delete', $key, $e->getMessage());
            return false;
        }
    }

    /**
     * Check if cache item exists
     *
     * @param string $key The key
     * @return bool True if key exists
     * @api
     */
    public function exists(string $key): bool {
        $this->validateKey($key);

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeExists($key);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            $storageKey = $this->getKey($key);
            return $this->storage->exists($storageKey);
        } catch (\Exception $e) {
            $this->logError('exists', $key, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Increment cache value
     *
     * @param string $key The key
     * @param int $by Amount to increment by
     * @return int|false New value or false on failure
     * @api
     */
    public function increment(string $key, int $by = 1) {
        $this->validateKey($key);

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeIncrement($key, $by);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            $storageKey = $this->getKey($key);
            return $this->storage->increment($storageKey, $by);
        } catch (\Exception $e) {
            $this->logError('increment', $key, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Decrement cache value
     *
     * @param string $key The key
     * @param int $by Amount to decrement by
     * @return int|false New value or false on failure
     * @api
     */
    public function decrement(string $key, int $by = 1) {
        $this->validateKey($key);

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeDecrement($key, $by);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            $storageKey = $this->getKey($key);
            return $this->storage->decrement($storageKey, $by);
        } catch (\Exception $e) {
            $this->logError('decrement', $key, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set if not exists
     *
     * @param string $key The key
     * @param mixed $value The value
     * @param int $ttl Time to live in seconds
     * @return bool True if set, false if key already exists
     * @api
     */
    public function setNx(string $key, $value, int $ttl = 0): bool {
        $this->validateKey($key);

        try {
            $storageKey = $this->getKey($key);

            // Check if key exists first
            if ($this->storage->exists($storageKey)) {
                return false;
            }

            // Set the value
            $result = $this->storage->set($storageKey, $value, $ttl);

            if ($result) {
                $this->metrics['sets']++;
            }

            return $result;
        } catch (\Exception $e) {
            $this->logError('setNx', $key, $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get multiple cache items
     *
     * @param array $keys The keys
     * @return array Associative array of key => value pairs
     * @api
     */
    public function getMultiple(array $keys): array {
        // Validate all keys first
        foreach ($keys as $key) {
            $this->validateKey($key);
        }

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeGetMultiple($keys);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            // Build storage keys and map back to original keys
            $storageKeys = [];
            $keyMap = [];
            foreach ($keys as $key) {
                $storageKey = $this->getKey($key);
                $storageKeys[] = $storageKey;
                $keyMap[$storageKey] = $key;
            }

            // Get values from storage
            $values = $this->storage->getMultiple($storageKeys);

            // Map results back to original keys
            $result = [];
            foreach ($storageKeys as $storageKey) {
                $originalKey = $keyMap[$storageKey];
                $value = $values[$storageKey] ?? null;

                if ($value === null) {
                    $this->metrics['misses']++;
                } else {
                    $this->metrics['hits']++;
                }

                $result[$originalKey] = $value;
            }

            return $result;
        } catch (\Exception $e) {
            $this->logError('getMultiple', implode(',', $keys), $e->getMessage());

            // Fallback to individual gets
            $result = [];
            foreach ($keys as $key) {
                $result[$key] = $this->get($key);
            }
            return $result;
        }
    }
    
    /**
     * Set multiple cache items
     *
     * @param array $items Associative array of key => value pairs
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     * @api
     */
    public function setMultiple(array $items, int $ttl = 0): bool {
        if (empty($items)) {
            return true;
        }

        // Validate all keys first
        foreach ($items as $key => $value) {
            $this->validateKey($key);
        }

        // Update metrics
        $this->metrics['sets'] += count($items);

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeSetMultiple($items, $ttl);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            // Build storage items with namespaced keys
            $storageItems = [];
            foreach ($items as $key => $value) {
                $storageKey = $this->getKey($key);
                $storageItems[$storageKey] = $value;
            }

            return $this->storage->setMultiple($storageItems, $ttl);
        } catch (\Exception $e) {
            $this->logError('setMultiple', implode(',', array_keys($items)), $e->getMessage());

            // Fallback to individual sets
            $success = true;
            foreach ($items as $key => $value) {
                if (!$this->set($key, $value, $ttl)) {
                    $success = false;
                }
            }
            return $success;
        }
    }
    
    /**
     * Delete multiple cache items
     *
     * @param array $keys The keys
     * @return bool Success status
     * @api
     */
    public function deleteMultiple(array $keys): bool {
        if (empty($keys)) {
            return true;
        }

        // Validate all keys first
        foreach ($keys as $key) {
            $this->validateKey($key);
        }

        // Update metrics
        $this->metrics['deletes'] += count($keys);

        // Route to gNode or default-tier implementation
        if ($this->useGNode) {
            return $this->gNodeDeleteMultiple($keys);
        }

        // Free-tier: Use StorageInterface adapter
        try {
            // Build storage keys with namespace
            $storageKeys = [];
            foreach ($keys as $key) {
                $storageKeys[] = $this->getKey($key);
            }

            return $this->storage->deleteMultiple($storageKeys);
        } catch (\Exception $e) {
            $this->logError('deleteMultiple', implode(',', $keys), $e->getMessage());

            // Fallback to individual deletes
            $success = true;
            foreach ($keys as $key) {
                if (!$this->delete($key)) {
                    $success = false;
                }
            }
            return $success;
        }
    }
    
    /**
     * Clear cache
     *
     * @return bool Success status
     * @api
     */
    public function clear(): bool {
        try {
            // gNode path: Use pattern-based clearing
            if ($this->useGNode) {
                $pattern = "cache:{$this->nodeMetadata['site_id']}:{$this->nodeMetadata['node_id']}:*";
                $keys = $this->storage->keys($pattern);

                if (empty($keys)) {
                    return true;
                }

                return $this->storage->del(...$keys) > 0;
            }

            // Free-tier: Use StorageInterface clear
            return $this->storage->clear();
        } catch (\Exception $e) {
            $this->logError('clear', 'all', $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate cache key
     * 
     * @param string $key The key
     * @return void
     * @throws ValidationException If key is invalid
     */
    protected function validateKey(string $key): void {
        if (empty($key)) {
            throw new ValidationException('Cache key cannot be empty');
        }
        
        if (strpos($key, ' ') !== false) {
            throw new ValidationException('Cache key cannot contain spaces');
        }
    }
    
    /**
     * Serialize value for storage
     * 
     * @param mixed $value The value
     * @return string Serialized value
     */
    protected function serialize($value): string {
        if (is_null($value) || is_scalar($value)) {
            // Simple type prefix
            $type = gettype($value)[0];
            
            if (is_string($value)) {
                return "s:{$value}";
            }
            
            if (is_int($value)) {
                return "i:{$value}";
            }
            
            if (is_float($value)) {
                return "f:{$value}";
            }
            
            if (is_bool($value)) {
                return "b:" . ($value ? '1' : '0');
            }
            
            if (is_null($value)) {
                return "n:";
            }
        }
        
        // Complex types
        return "c:" . serialize($value);
    }
    
    /**
     * Unserialize value from storage
     * 
     * @param string $serialized Serialized value
     * @return mixed Unserialized value
     */
    protected function unserialize(string $serialized) {
        if (empty($serialized)) {
            return null;
        }
        
        $type = substr($serialized, 0, 2);
        $value = substr($serialized, 2);
        
        switch ($type) {
            case 's:':
                return $value;
                
            case 'i:':
                return (int)$value;
                
            case 'f:':
                return (float)$value;
                
            case 'b:':
                return $value === '1';
                
            case 'n:':
                return null;
                
            case 'c:':
                return unserialize($value, ['allowed_classes' => false]);
                
            default:
                // Fallback for old data
                return $serialized;
        }
    }
    
    /**
     * Log error
     *
     * @param string $operation The operation
     * @param string $key The key
     * @param string $message Error message
     * @return void
     */
    protected function logError(string $operation, string $key, string $message): void {
        SelfContainedErrorHandler::logErrorMessage(
            'CacheManager',
            $operation,
            "Error with key '{$key}': {$message}",
            [
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'key' => $key
            ]
        );
    }

    /**
     * Create a StorageException when an extension-gated feature is
     * requested without gNode integration available.
     *
     * @param string $feature Feature name (e.g., 'batch_operations', 'content_minification')
     * @return StorageException Exception with the feature description
     */
    protected function createExtensionFeatureException(string $feature): StorageException {
        $prompt = gNodeDetector::getUpgradePrompt($feature);
        $featureInfo = $prompt['requested_feature'] ?? ['name' => $feature, 'description' => 'Extension feature'];

        $message = sprintf(
            "Extension feature unavailable: %s\n" .
            "Description: %s\n" .
            "This feature requires gNode integration to be enabled.\n" .
            "See: %s",
            $featureInfo['name'],
            $featureInfo['description'],
            $prompt['action']['url'] ?? 'https://geodineum.com/docs/gnode-integration'
        );

        // Log the request (non-blocking)
        SelfContainedErrorHandler::logInfo(
            'CacheManager',
            'extension_feature_requested',
            "Extension feature requested without gNode: {$feature}",
            ['feature' => $feature, 'gnode_available' => false]
        );

        return new StorageException($message);
    }

    /**
     * Throw an extension-feature exception if gNode is unavailable.
     *
     * @param string $feature Feature name for error message
     * @return void
     * @throws StorageException If gNode is not available
     */
    protected function requireGNode(string $feature): void {
        if (!$this->useGNode) {
            throw $this->createExtensionFeatureException($feature);
        }
    }
    
    /**
     * Get keys matching a pattern
     * 
     * @param string $pattern Pattern to match
     * @return array Matching keys
     * @api
     */
    public function getKeys(string $pattern): array {
        try {
            return $this->storage->keys($pattern);
        } catch (\Exception $e) {
            $this->logError('getKeys', $pattern, $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get cache metrics (enhanced with gNode stats when available)
     *
     * @return array Metrics including mode indicator
     * @api
     */
    public function getMetrics(): array {
        $hitRatio = ($this->metrics['hits'] + $this->metrics['misses']) > 0
            ? round($this->metrics['hits'] / ($this->metrics['hits'] + $this->metrics['misses']) * 100, 2)
            : 0;

        return [
            'hits' => $this->metrics['hits'],
            'misses' => $this->metrics['misses'],
            'sets' => $this->metrics['sets'],
            'deletes' => $this->metrics['deletes'],
            'hit_ratio' => $hitRatio,
            'mode' => $this->useGNode ? 'gnode' : 'legacy'
        ];
    }
    
    /**
     * Get module configuration
     * 
     * @return array Configuration
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * Update module configuration
     * 
     * @param array $config New configuration
     * @return void
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);

        $cfgStorage = $this->gcoreResolveStorage($this->config);
        if ($cfgStorage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($cfgStorage, $siteId, 'CacheManager', (string)$key, $value);
            }
        }
    }

    /**
     * Check if module is initialized
     * 
     * @return bool Initialization status
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Raw per-site \Redis connection for stream operations that need direct
     * commands (XRANGE/XREVRANGE/XINFO/XADD) — used by CommsManager's admin
     * reads, which CacheManager's FCALL methods don't 1:1 cover.
     *
     * The credential is resolved through the ONE canonical resolver
     * (gNode-Client CredentialResolver, the  single source of truth) and
     * authenticated as THIS site's own least-privilege user (gnode_client_<site>),
     * so the connection can only touch {site}:* — no new cred reader, no broader
     * access, same file/location everything else uses. Returns null when
     * ext-redis or the credential is unavailable; callers degrade gracefully.
     *
     * NOTE: the architecturally-pure path is the StreamCapabilities trait
     * (FCALL-only via GNODE_STREAM_*); migrating CommsManager onto it is a
     * tracked follow-up. This keeps the raw escape hatch ACL-bounded meanwhile.
     */
    public function getConnection(): ?\Redis {
        if (!class_exists('\Redis', false) && !extension_loaded('redis')) {
            return null;
        }
        $siteId = $this->nodeMetadata['site_id']
            ?? ($this->config['site_id'] ?? null);
        if (empty($siteId) || $siteId === 'default') {
            return null;
        }
        $user = 'gnode_client_' . $siteId;
        try {
            $password = \gCore\gNode\Config\CredentialResolver::resolve($user);
        } catch (\Throwable $e) {
            return null;
        }
        $host = (string) (getenv('VALKEY_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('VALKEY_PORT') ?: 47445);
        $r = new \Redis();
        if (!@$r->connect($host, $port, 1.0)) {
            return null;
        }
        try {
            $r->auth([$user, $password]);
        } catch (\Throwable $e) {
            try { $r->close(); } catch (\Throwable $_) { /* noop */ }
            return null;
        }
        return $r;
    }

    /**
     * Pattern key lookup via SCAN (never the blocking KEYS). Used by
     * CommsManager's site listing. Runs over the per-site connection, so the
     * ACL naturally bounds results to keys this site may see — cross-site
     * listing is a gDash/operator-console concern. Returns [] on any error
     * (NOPERM, no access, ext-redis missing) so callers degrade, never fatal.
     *
     * @param string $pattern e.g. "{site}:comms:config" or "*:comms:config"
     * @return string[]
     */
    public function keys(string $pattern): array {
        $r = $this->getConnection();
        if (!($r instanceof \Redis)) {
            return [];
        }
        $found = [];
        try {
            $it = null;
            // SCAN_RETRY: don't end iteration on an empty intermediate batch.
            @$r->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            while (($batch = $r->scan($it, $pattern, 500)) !== false) {
                foreach ($batch as $k) {
                    $found[] = $k;
                }
            }
        } catch (\Throwable $e) {
            // NOPERM / cross-site / no access → partial or empty, never fatal.
        }
        try { $r->close(); } catch (\Throwable $_) { /* noop */ }
        return $found;
    }

    /**
     * Get module status
     *
     * @return array Status information
     */
    public function getStatus(): array {
        // Determine storage status based on mode
        $storageStatus = 'unknown';
        if ($this->useGNode && method_exists($this->storage, 'isConnected')) {
            $storageStatus = $this->storage->isConnected() ? 'connected' : 'disconnected';
        } elseif (method_exists($this->storage, 'isAvailable')) {
            $storageStatus = $this->storage->isAvailable() ? 'available' : 'unavailable';
        }

        return [
            'initialized' => $this->initialized,
            'site_id' => $this->nodeMetadata['site_id'],
            'node_id' => $this->nodeMetadata['node_id'],
            'storage' => $storageStatus,
            'storage_type' => method_exists($this->storage, 'getType') ? $this->storage->getType() : 'unknown',
            'mode' => $this->useGNode ? 'gnode' : 'free_tier',
            'metrics' => $this->getMetrics(),
            'capabilities' => $this->capabilityVector
        ];
    }
    
    /**
     * Get capability vector for service discovery
     * 
     * @return array Capability vector
     */
    public function getCapabilityVector(): array {
        return $this->capabilityVector;
    }
    
    /**
     * Prevent cloning of singleton
     */
    private function __clone() {
    }
    
    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new \Exception(
            "Cannot unserialize singleton"
        );
    }
}
