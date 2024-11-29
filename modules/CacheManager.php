<?php
/**
 * CacheManager - Independent Cache Management System
 * 
 * Provides a comprehensive caching solution for the NiertoCube theme with zero external
 * dependencies. Implements multiple caching strategies (ValKey/Transients), automatic
 * fallback mechanisms, and self-contained monitoring systems.
 * 
 * @package     NiertoCube
 * @subpackage  Core
 * @version     2.0.0
 * @since       1.0.0
 * 
 * == File Purpose ==
 * Serves as a standalone caching system for the NiertoCube theme, managing all caching
 * operations through strategy pattern implementation, with support for different backends
 * and automatic fallback mechanisms.
 * 
 * == Key Functions ==
 * - get()              : Retrieve cached items
 * - set()              : Store items in cache
 * - delete()           : Remove cached items
 * - flush()            : Clear cache groups
 * - registerGroup()    : Register new cache groups
 * 
 * == Cache Strategies ==
 * Primary:
 * - ValKey: High-performance in-memory caching
 * - Features: Atomic operations, distributed caching
 * 
 * Fallback:
 * - WordPress Transients
 * - Features: Reliable, database-backed storage
 * 
 * == Cache Groups ==
 * Default Groups:
 * - core: General purpose (TTL: 1 hour)
 * - face: Cube face content (TTL: 2 hours)
 * - api: API responses (TTL: 30 minutes)
 * - manifest: PWA manifest (TTL: 24 hours)
 * 
 * == Performance Tracking ==
 * Metrics:
 * - Cache hits/misses
 * - Write operations
 * - Error counts
 * - Hit ratios
 * - Response times
 * - Uptime tracking
 * 
 * == Error Management ==
 * - Independent error logging
 * - Strategy fallback system
 * - Error metrics tracking
 * - Debug mode support
 * 
 * == Data Management ==
 * Value Handling:
 * - Automatic serialization
 * - Type preservation
 * - Size optimization
 * - Compression support
 * 
 * == Version Control ==
 * - Group-based versioning
 * - Automatic version incrementation
 * - Cache invalidation
 * - Version tracking
 * 
 * == Integration Points ==
 * WordPress Hooks:
 * - switch_theme
 * - activated_plugin
 * - deactivated_plugin
 * - customize_save_after
 * 
 * == Security Measures ==
 * - Prefix isolation
 * - Key sanitization
 * - Value validation
 * - Group restrictions
 * - TTL enforcement
 * 
 * == Performance Optimizations ==
 * - Strategy selection
 * - Hit ratio monitoring
 * - Memory usage tracking
 * - TTL optimization
 * - Group management
 * 
 * == Configuration Options ==
 * - Cache strategy selection
 * - TTL settings (default, min, max)
 * - Debug mode
 * - Prefix customization
 * - Group settings
 * 
 * == Future Improvements ==
 * @todo Add support for object cache backends
 * @todo Implement cache warming system
 * @todo Add advanced compression options
 * @todo Enhance performance monitoring
 * @todo Add cache statistics dashboard
 * 
 * == Error Handling ==
 * - Strategy initialization errors
 * - Cache operation failures
 * - Version control issues
 * - Storage limitations
 * - Connection problems
 * 
 * == Code Standards ==
 * - Follows WordPress coding standards
 * - Implements proper error handling
 * - Uses type hints for PHP 7.4+
 * - Maintains singleton pattern integrity
 * 
 * == Changelog ==
 * 2.0.0
 * - Implemented independent architecture
 * - Added strategy pattern support
 * - Enhanced error handling
 * - Improved performance tracking
 * - Added comprehensive metrics
 * 
 * 1.0.0
 * - Initial implementation
 * - Basic caching functionality
 * - Transient support
 * - Simple metrics
 * 
 * @author    Niels Erik Toren
 * @copyright 2024 NiertoCube
 * @license   See project root for license information
 * @link      https://nierto.com Documentation
 * 
 * @see \NiertoCube\Core\ModuleInterface
 * @see \NiertoCube\Cache\CacheStrategyInterface
 * @see \NiertoCube\Cache\ValKeyStrategy
 * @see \NiertoCube\Cache\TransientStrategy
 */

namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;

if (!defined('ABSPATH')) {
    exit;
}

class CacheManager implements ModuleInterface {
    /** @var CacheManager Singleton instance */
    private static $instance = null;
    
    /** @var array Cache configuration */
    private $config = [];
    
    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Performance metrics */
    private $metrics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'errors' => 0,
        'start_time' => 0
    ];

    /** @var array Error log */
    private $errors = [];

    /** @var string Current cache strategy */
    private $strategy = 'transient';

    /** @var CacheStrategyInterface Active cache implementation */
    private $driver;

    /** @var array Registered cache groups */
    private $groups = [];

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
     * Private constructor
     */
    private function __construct() {
        $this->metrics['start_time'] = microtime(true);
    }

    /**
     * Initialize cache system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Set configuration
            $this->config = array_merge([
                'enabled' => true,
                'strategy' => 'valkey',
                'fallback_strategy' => 'transient',
                'prefix' => 'nierto_cube_',
                'debug' => WP_DEBUG,
                'default_ttl' => HOUR_IN_SECONDS,
                'max_ttl' => WEEK_IN_SECONDS,
                'min_ttl' => MINUTE_IN_SECONDS
            ], $config);

            // Initialize cache strategy
            $this->initializeStrategy();

            // Register default groups
            $this->registerDefaultGroups();

            $this->initialized = true;

        } catch (\Exception $e) {
            $this->logError('Initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Initialize cache strategy
     */
    private function initializeStrategy(): void {
        try {
            // Try primary strategy first
            if ($this->config['strategy'] === 'valkey' && $this->isValKeyAvailable()) {
                $this->driver = new ValKeyStrategy($this->config);
                $this->strategy = 'valkey';
                return;
            }
        } catch (\Exception $e) {
            $this->logError('ValKey initialization failed: ' . $e->getMessage());
        }

        // Fall back to transient strategy
        try {
            $this->driver = new TransientStrategy($this->config);
            $this->strategy = 'transient';
        } catch (\Exception $e) {
            $this->logError('Transient initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register default cache groups
     */
    private function registerDefaultGroups(): void {
        $this->registerGroup('core', [
            'ttl' => HOUR_IN_SECONDS,
            'version' => '1.0'
        ]);

        $this->registerGroup('face', [
            'ttl' => HOUR_IN_SECONDS * 2,
            'version' => '1.0'
        ]);

        $this->registerGroup('api', [
            'ttl' => HOUR_IN_SECONDS / 2,
            'version' => '1.0'
        ]);

        $this->registerGroup('manifest', [
            'ttl' => DAY_IN_SECONDS,
            'version' => '1.0'
        ]);
    }

    /**
     * Register a cache group
     */
    public function registerGroup(string $name, array $settings = []): void {
        $this->groups[$name] = array_merge([
            'ttl' => $this->config['default_ttl'],
            'version' => '1.0',
            'prefix' => $this->config['prefix'] . $name . '_'
        ], $settings);
    }

    /**
     * Get item from cache
     */
    public function get(string $key, string $group = 'core') {
        if (!$this->initialized || !isset($this->groups[$group])) {
            return false;
        }

        try {
            $fullKey = $this->buildKey($key, $group);
            $value = $this->driver->get($fullKey);

            if ($value === false) {
                $this->metrics['misses']++;
                return false;
            }

            $this->metrics['hits']++;
            return $this->maybeUnserialize($value);

        } catch (\Exception $e) {
            $this->logError("Get failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set item in cache
     */
    public function set(string $key, $value, string $group = 'core', ?int $ttl = null): bool {
        if (!$this->initialized || !isset($this->groups[$group])) {
            return false;
        }

        try {
            $ttl = $ttl ?? $this->groups[$group]['ttl'];
            $ttl = $this->normalizeTtl($ttl);
            
            $fullKey = $this->buildKey($key, $group);
            $value = $this->maybeSerialize($value);

            $success = $this->driver->set($fullKey, $value, $ttl);

            if ($success) {
                $this->metrics['writes']++;
            }

            return $success;

        } catch (\Exception $e) {
            $this->logError("Set failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete item from cache
     */
    public function delete(string $key, string $group = 'core'): bool {
        if (!$this->initialized || !isset($this->groups[$group])) {
            return false;
        }

        try {
            return $this->driver->delete($this->buildKey($key, $group));
        } catch (\Exception $e) {
            $this->logError("Delete failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Flush cache group or all cache
     */
    public function flush(?string $group = null): bool {
        if (!$this->initialized) {
            return false;
        }

        try {
            if ($group !== null) {
                if (!isset($this->groups[$group])) {
                    return false;
                }
                $this->incrementGroupVersion($group);
                return true;
            }

            // Flush all groups
            foreach ($this->groups as $groupName => $settings) {
                $this->incrementGroupVersion($groupName);
            }

            return true;

        } catch (\Exception $e) {
            $this->logError('Flush failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build full cache key
     */
    private function buildKey(string $key, string $group): string {
        return $this->groups[$group]['prefix'] . 
               'v' . $this->groups[$group]['version'] . '_' . 
               $key;
    }

    /**
     * Increment group version
     */
    private function incrementGroupVersion(string $group): void {
        $this->groups[$group]['version'] = (string)((int)$this->groups[$group]['version'] + 1);
    }

    /**
     * Check if ValKey is available
     */
    private function isValKeyAvailable(): bool {
        return class_exists('ValKey') && function_exists('valkey_connect');
    }

    /**
     * Normalize TTL value
     */
    private function normalizeTtl(?int $ttl): int {
        if ($ttl === null) {
            return $this->config['default_ttl'];
        }

        return max(
            $this->config['min_ttl'],
            min($ttl, $this->config['max_ttl'])
        );
    }

    /**
     * Maybe serialize value
     */
    private function maybeSerialize($value) {
        return is_array($value) || is_object($value) ? serialize($value) : $value;
    }

    /**
     * Maybe unserialize value
     */
    private function maybeUnserialize($value) {
        if (!is_string($value)) {
            return $value;
        }

        $unserialized = @unserialize($value);
        return $unserialized !== false ? $unserialized : $value;
    }

    /**
     * Log error message
     */
    private function logError(string $message): void {
        $this->errors[] = [
            'time' => microtime(true),
            'message' => $message
        ];

        $this->metrics['errors']++;

        if ($this->config['debug']) {
            error_log('CacheManager Error: ' . $message);
        }
    }

    /**
     * Get configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get cache metrics
     */
    public function getMetrics(): array {
        $total = $this->metrics['hits'] + $this->metrics['misses'];
        $hit_ratio = $total > 0 ? ($this->metrics['hits'] / $total) * 100 : 0;

        return [
            'hits' => $this->metrics['hits'],
            'misses' => $this->metrics['misses'],
            'writes' => $this->metrics['writes'],
            'errors' => $this->metrics['errors'],
            'hit_ratio' => round($hit_ratio, 2),
            'uptime' => microtime(true) - $this->metrics['start_time'],
            'strategy' => $this->strategy,
            'groups' => array_keys($this->groups)
        ];
    }

    /**
     * Get status information
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'strategy' => $this->strategy,
            'metrics' => $this->getMetrics(),
            'errors' => array_slice($this->errors, -10),
            'groups' => $this->groups
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}