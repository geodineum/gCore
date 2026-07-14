<?php
declare(strict_types=1);
/**
 * StorageFactory - Storage Adapter Auto-Detection and Creation
 *
 * Factory class that automatically selects the best available storage adapter
 * based on the environment and configuration.
 *
 * Selection Priority:
 * 1. Explicit adapter type in config
 * 2. ValKey/Redis if gNode is available
 * 3. WordPress Transients if in WordPress environment
 * 4. APCu if available (future)
 * 5. File storage (future)
 * 6. Memory storage (fallback)
 *
 * @package     gCore
 * @subpackage  Storage
 * @version     1.0.0
 */

namespace gCore\Modules\Storage;

use gCore\Modules\Storage\Interfaces\StorageInterface;
use gCore\Modules\Storage\Adapters\TransientStorage;
use gCore\Modules\Storage\Adapters\MemoryStorage;

if (!defined('ABSPATH')) {
    if (!defined('GCORE_STANDALONE')) {
        define('GCORE_STANDALONE', true);
    }
}

/**
 * StorageFactory
 *
 * Creates and returns the most appropriate storage adapter for the environment.
 */
class StorageFactory
{
    /**
     * Cached storage instance (singleton per configuration)
     * @var StorageInterface|null
     */
    private static $instance = null;

    /**
     * Configuration used for cached instance
     * @var array
     */
    private static $cachedConfig = [];

    /**
     * Create or retrieve a storage adapter instance
     *
     * @param array $config Configuration options
     *   - adapter: Force specific adapter ('transient', 'memory', 'valkey')
     *   - prefix: Key prefix for namespacing
     *   - site_id: Site identifier for multi-tenant isolation
     *   - gnode_client: gNode-Client instance for ValKey adapter
     *   - force_new: Force creation of new instance (default: false)
     * @return StorageInterface The storage adapter
     */
    public static function create(array $config = []): StorageInterface
    {
        // Check if we can return cached instance
        $forceNew = $config['force_new'] ?? false;
        unset($config['force_new']);

        if (!$forceNew && self::$instance !== null && self::$cachedConfig === $config) {
            return self::$instance;
        }

        // Determine which adapter to use
        $adapterType = self::detectAdapter($config);

        // Create the appropriate adapter
        $adapter = self::createAdapter($adapterType, $config);

        // Cache if not forcing new
        if (!$forceNew) {
            self::$instance = $adapter;
            self::$cachedConfig = $config;
        }

        return $adapter;
    }

    /**
     * Detect the best available storage adapter
     *
     * @param array $config Configuration options
     * @return string Adapter type identifier
     */
    public static function detectAdapter(array $config = []): string
    {
        // 1. Check for explicit adapter override
        if (isset($config['adapter']) && !empty($config['adapter'])) {
            return $config['adapter'];
        }

        // 2. Check for gNode-Client / ValKey availability
        //    This is the enhanced path: if gNode is available, use it
        if (isset($config['gnode_client']) && $config['gnode_client'] !== null) {
            // ValKey adapter would be created by existing CacheManager
            // We return 'valkey' to indicate gNode is available
            // But the actual storage is handled by gNode-Client
            return 'valkey';
        }

        // 3. Check for WordPress environment
        if (self::isWordPress()) {
            return 'transient';
        }

        // 4. Check for APCu extension (future)
        // if (self::hasAPCu()) {
        //     return 'apcu';
        // }

        // 5. File storage could be checked here (future)
        // if (self::canUseFileStorage()) {
        //     return 'file';
        // }

        // 6. Fallback to memory storage
        return 'memory';
    }

    /**
     * Create an adapter instance by type
     *
     * @param string $type Adapter type
     * @param array $config Configuration
     * @return StorageInterface The adapter instance
     * @throws \InvalidArgumentException If adapter type is unknown
     */
    private static function createAdapter(string $type, array $config): StorageInterface
    {
        switch ($type) {
            case 'transient':
                return new TransientStorage($config);

            case 'memory':
                return new MemoryStorage($config);

            case 'valkey':
                // ValKey is handled by the existing system (CacheManager + gNode)
                // If we get here without gNode, fall back to transient or memory
                if (self::isWordPress()) {
                    return new TransientStorage($config);
                }
                return new MemoryStorage($config);

            // Future adapters
            // case 'apcu':
            //     return new APCuStorage($config);
            // case 'file':
            //     return new FileStorage($config);

            default:
                throw new \InvalidArgumentException(
                    "Unknown storage adapter type: {$type}. " .
                    "Available types: transient, memory"
                );
        }
    }

    /**
     * Check if running in WordPress environment
     *
     * @return bool True if WordPress is loaded
     */
    public static function isWordPress(): bool
    {
        return defined('ABSPATH') && function_exists('get_transient');
    }

    /**
     * Check if APCu extension is available
     *
     * @return bool True if APCu can be used
     */
    public static function hasAPCu(): bool
    {
        return extension_loaded('apcu') &&
               ini_get('apc.enabled') &&
               function_exists('apcu_fetch');
    }

    /**
     * Check if file-based storage can be used
     *
     * @param string|null $path Optional path to check
     * @return bool True if file storage is available
     */
    public static function canUseFileStorage(?string $path = null): bool
    {
        if ($path === null) {
            // Default paths to check
            if (self::isWordPress() && defined('WP_CONTENT_DIR')) {
                $path = WP_CONTENT_DIR . '/cache/gcore';
            } else {
                $path = sys_get_temp_dir() . '/gcore';
            }
        }

        // Check if directory exists or can be created
        if (!is_dir($path)) {
            $created = @mkdir($path, 0755, true);
            if (!$created) {
                return false;
            }
        }

        return is_writable($path);
    }

    /**
     * Get information about all available adapters
     *
     * @return array Adapter availability information
     */
    public static function getAvailableAdapters(): array
    {
        return [
            'transient' => [
                'available' => self::isWordPress(),
                'description' => 'WordPress Transients API',
                'persistent' => true,
                'distributed' => function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
            ],
            'memory' => [
                'available' => true,  // Always available
                'description' => 'In-memory storage (non-persistent)',
                'persistent' => false,
                'distributed' => false
            ],
            'valkey' => [
                'available' => false,  // Requires gNode
                'description' => 'ValKey/Redis via gNode',
                'persistent' => true,
                'distributed' => true,
                'full' => true
            ],
            'apcu' => [
                'available' => self::hasAPCu(),
                'description' => 'APCu shared memory cache',
                'persistent' => false,  // Per-process
                'distributed' => false,
                'status' => 'planned'
            ],
            'file' => [
                'available' => self::canUseFileStorage(),
                'description' => 'File-based persistent storage',
                'persistent' => true,
                'distributed' => false,
                'status' => 'planned'
            ]
        ];
    }

    /**
     * Get the recommended adapter for current environment
     *
     * @param array $config Configuration options
     * @return array Recommendation with type and reason
     */
    public static function getRecommendation(array $config = []): array
    {
        $type = self::detectAdapter($config);

        $reasons = [
            'valkey' => 'gNode available - using ValKey storage with gNode enhancement',
            'transient' => 'WordPress detected - using Transients API with automatic object cache support',
            'apcu' => 'APCu extension available - using shared memory for fast access',
            'file' => 'Using file-based storage for persistence',
            'memory' => 'Using in-memory storage (non-persistent, testing mode)'
        ];

        return [
            'recommended' => $type,
            'reason' => $reasons[$type] ?? 'Default selection',
            'all_available' => self::getAvailableAdapters()
        ];
    }

    /**
     * Reset the cached instance (useful for testing)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$cachedConfig = [];
    }

    /**
     * Check if gNode enhancement is available
     * Convenience method for checking extension features
     *
     * @param array $config Configuration with potential gnode_client
     * @return bool True if gNode is available
     */
    public static function hasGNode(array $config = []): bool
    {
        // Check config for gNode client
        if (isset($config['gnode_client']) && $config['gnode_client'] !== null) {
            return true;
        }

        // Check global gNode detector if available
        if (class_exists('\gCore\Modules\Storage\gNodeDetector')) {
            return gNodeDetector::isAvailable();
        }

        return false;
    }
}
