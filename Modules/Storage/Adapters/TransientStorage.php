<?php
declare(strict_types=1);
/**
 * TransientStorage - WordPress Transients Storage Adapter
 *
 * Primary default-tier storage adapter using WordPress Transients API.
 * Benefits:
 * - Works on any WordPress installation
 * - Automatically uses object cache if available (Redis, Memcached)
 * - Falls back to database if no object cache
 * - Zero additional dependencies
 *
 * @package     gCore
 * @subpackage  Storage\Adapters
 * @version     1.0.0
 */

namespace gCore\Modules\Storage\Adapters;

use gCore\Modules\Storage\Interfaces\StorageInterface;

if (!defined('ABSPATH')) {
    // This adapter requires WordPress
    return;
}

/**
 * TransientStorage
 *
 * WordPress Transients-based storage adapter implementing StorageInterface.
 * Uses set_transient/get_transient/delete_transient WordPress functions.
 */
class TransientStorage implements StorageInterface
{
    /**
     * Key prefix for namespacing
     * @var string
     */
    private $prefix;

    /**
     * Site ID for multi-site isolation
     * @var string
     */
    private $siteId;

    /**
     * Configuration array
     * @var array
     */
    private $config;

    /**
     * Local metrics tracking
     * @var array
     */
    private $metrics = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0
    ];

    /**
     * Constructor
     *
     * @param array $config Configuration options
     *   - prefix: Key prefix (default: 'gcore_')
     *   - site_id: Site identifier for multi-tenant isolation
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'prefix' => 'gcore_',
            'site_id' => 'default'
        ], $config);

        $this->prefix = $this->config['prefix'];
        $this->siteId = $this->config['site_id'];
    }

    /**
     * Build the full transient key with prefix and site isolation
     *
     * WordPress transient names are limited to 172 characters.
     * We use: {prefix}{site_id}_{key} format.
     *
     * @param string $key The original key
     * @return string The prefixed, namespaced key
     */
    private function buildKey(string $key): string
    {
        $fullKey = $this->prefix . $this->siteId . '_' . $key;

        // WordPress transient names must be <= 172 chars
        // If too long, hash the key portion
        if (strlen($fullKey) > 172) {
            $hashedKey = $this->prefix . $this->siteId . '_' . md5($key);
            return $hashedKey;
        }

        return $fullKey;
    }

    /**
     * Get a value from storage
     *
     * @param string $key The unique key
     * @param mixed $default Default value if not found
     * @return mixed The stored value or default
     */
    public function get(string $key, $default = null)
    {
        $transientKey = $this->buildKey($key);
        $value = get_transient($transientKey);

        if ($value === false) {
            $this->metrics['misses']++;
            return $default;
        }

        $this->metrics['hits']++;

        // Handle our serialization wrapper
        if (is_array($value) && isset($value['__gcore_wrapped'])) {
            return $value['data'];
        }

        return $value;
    }

    /**
     * Store a value in storage
     *
     * @param string $key The key
     * @param mixed $value The value to store
     * @param int $ttl Time-to-live in seconds (0 = no expiration)
     * @return bool Success status
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        $transientKey = $this->buildKey($key);

        // Wrap value to distinguish between false values and "not found"
        // WordPress get_transient returns false for both missing and false values
        $wrappedValue = [
            '__gcore_wrapped' => true,
            'data' => $value,
            'stored_at' => time()
        ];

        // WordPress transients: 0 = no expiration
        $result = set_transient($transientKey, $wrappedValue, $ttl);

        if ($result) {
            $this->metrics['sets']++;
        }

        return $result;
    }

    /**
     * Delete a value from storage
     *
     * @param string $key The key to delete
     * @return bool Success status
     */
    public function delete(string $key): bool
    {
        $transientKey = $this->buildKey($key);
        $result = delete_transient($transientKey);

        if ($result) {
            $this->metrics['deletes']++;
        }

        return $result;
    }

    /**
     * Check if a key exists
     *
     * @param string $key The key to check
     * @return bool True if exists
     */
    public function exists(string $key): bool
    {
        $transientKey = $this->buildKey($key);
        $value = get_transient($transientKey);
        return $value !== false;
    }

    /**
     * Get multiple values
     *
     * @param array $keys Array of keys
     * @param mixed $default Default for missing keys
     * @return array Key => value pairs
     */
    public function getMultiple(array $keys, $default = null): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * Store multiple values
     *
     * @param array $items Key => value pairs
     * @param int $ttl Time-to-live in seconds
     * @return bool True if all succeeded
     */
    public function setMultiple(array $items, int $ttl = 0): bool
    {
        $success = true;

        foreach ($items as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Delete multiple values
     *
     * @param array $keys Array of keys to delete
     * @return bool True if all deleted
     */
    public function deleteMultiple(array $keys): bool
    {
        $success = true;

        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Clear all gCore transients
     *
     * Note: This only clears transients matching our prefix.
     * It does NOT clear all WordPress transients.
     *
     * @return bool Success status
     */
    public function clear(): bool
    {
        global $wpdb;

        try {
            // Build pattern for our transients
            $pattern = '_transient_' . $this->prefix . $this->siteId . '_%';
            $timeoutPattern = '_transient_timeout_' . $this->prefix . $this->siteId . '_%';

            // Delete from options table
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $pattern,
                    $timeoutPattern
                )
            );

            // If using object cache, we can't easily clear just our keys
            // But WordPress handles transient expiration automatically
            if (wp_using_ext_object_cache()) {
                // Object cache may have these cached - try to flush group if possible
                if (function_exists('wp_cache_flush_group')) {
                    wp_cache_flush_group('transient');
                }
            }

            return true;

        } catch (\Exception $e) {
            error_log('[gCore TransientStorage] Clear failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Increment a numeric value
     *
     * @param string $key The key
     * @param int $step Increment step
     * @return int|false New value or false
     */
    public function increment(string $key, int $step = 1)
    {
        $value = $this->get($key, 0);

        if (!is_numeric($value)) {
            return false;
        }

        $newValue = (int)$value + $step;

        // Preserve TTL if possible (WordPress doesn't expose transient TTL)
        // We'll set without TTL, which means it persists until manually deleted
        if ($this->set($key, $newValue)) {
            return $newValue;
        }

        return false;
    }

    /**
     * Decrement a numeric value
     *
     * @param string $key The key
     * @param int $step Decrement step
     * @return int|false New value or false
     */
    public function decrement(string $key, int $step = 1)
    {
        return $this->increment($key, -$step);
    }

    /**
     * Check if storage is available
     *
     * @return bool True if WordPress transients are available
     */
    public function isAvailable(): bool
    {
        // Check if WordPress functions are available
        return function_exists('get_transient') &&
               function_exists('set_transient') &&
               function_exists('delete_transient');
    }

    /**
     * Get storage adapter information
     *
     * @return array Adapter information
     */
    public function getInfo(): array
    {
        $usingObjectCache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();

        return [
            'type' => 'transient',
            'version' => '1.0.0',
            'prefix' => $this->prefix,
            'site_id' => $this->siteId,
            'using_object_cache' => $usingObjectCache,
            'capabilities' => [
                'ttl' => true,
                'atomic_increment' => false,  // Not truly atomic
                'batch_operations' => false,  // Sequential implementation
                'distributed' => $usingObjectCache,
                'persistent' => true
            ],
            'metrics' => $this->metrics
        ];
    }

    /**
     * Get adapter type
     *
     * @return string 'transient'
     */
    public function getType(): string
    {
        return 'transient';
    }

    /**
     * Get current metrics
     *
     * @return array Metrics array
     */
    public function getMetrics(): array
    {
        $total = $this->metrics['hits'] + $this->metrics['misses'];
        $hitRatio = $total > 0 ? round(($this->metrics['hits'] / $total) * 100, 2) : 0;

        return array_merge($this->metrics, [
            'hit_ratio' => $hitRatio
        ]);
    }
}
