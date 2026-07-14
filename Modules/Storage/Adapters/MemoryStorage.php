<?php
declare(strict_types=1);
/**
 * MemoryStorage - In-Memory Storage Adapter
 *
 * Non-persistent storage adapter for testing and fallback scenarios.
 * Data exists only for the duration of the PHP request.
 *
 * Use cases:
 * - Unit testing without external dependencies
 * - Fallback when no other storage is available
 * - Standalone PHP environments without WordPress
 * - Development and debugging
 *
 * @package     gCore
 * @subpackage  Storage\Adapters
 * @version     1.0.0
 */

namespace gCore\Modules\Storage\Adapters;

use gCore\Modules\Storage\Interfaces\StorageInterface;

if (!defined('ABSPATH')) {
    // Allow standalone usage
    if (!defined('GCORE_STANDALONE')) {
        define('GCORE_STANDALONE', true);
    }
}

/**
 * MemoryStorage
 *
 * In-memory storage adapter implementing StorageInterface.
 * Data is stored in a PHP array and lost at end of request.
 */
class MemoryStorage implements StorageInterface
{
    /**
     * In-memory data store
     * Structure: ['key' => ['value' => mixed, 'expires' => int|null]]
     * @var array
     */
    private $store = [];

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
     * Metrics tracking
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
     *   - site_id: Site identifier for isolation
     *   - max_items: Maximum items to store (default: 10000)
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'prefix' => 'gcore_',
            'site_id' => 'default',
            'max_items' => 10000
        ], $config);

        $this->prefix = $this->config['prefix'];
        $this->siteId = $this->config['site_id'];
    }

    /**
     * Build the full key with prefix and site isolation
     *
     * @param string $key The original key
     * @return string The namespaced key
     */
    private function buildKey(string $key): string
    {
        return $this->prefix . $this->siteId . ':' . $key;
    }

    /**
     * Check if an entry has expired
     *
     * @param array $entry The stored entry
     * @return bool True if expired
     */
    private function isExpired(array $entry): bool
    {
        if ($entry['expires'] === null) {
            return false; // No expiration
        }

        return time() > $entry['expires'];
    }

    /**
     * Clean up expired entries (lazy cleanup)
     * Called periodically to prevent memory bloat
     *
     * @return int Number of entries removed
     */
    private function cleanup(): int
    {
        $removed = 0;
        $now = time();

        foreach ($this->store as $key => $entry) {
            if ($entry['expires'] !== null && $now > $entry['expires']) {
                unset($this->store[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Enforce max items limit using LRU-like eviction
     *
     * @return void
     */
    private function enforceLimit(): void
    {
        $maxItems = $this->config['max_items'];

        if (count($this->store) >= $maxItems) {
            // Remove oldest 10% of items
            $removeCount = (int)($maxItems * 0.1);
            $keys = array_keys($this->store);
            $toRemove = array_slice($keys, 0, $removeCount);

            foreach ($toRemove as $key) {
                unset($this->store[$key]);
            }
        }
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
        $fullKey = $this->buildKey($key);

        if (!isset($this->store[$fullKey])) {
            $this->metrics['misses']++;
            return $default;
        }

        $entry = $this->store[$fullKey];

        // Check expiration
        if ($this->isExpired($entry)) {
            unset($this->store[$fullKey]);
            $this->metrics['misses']++;
            return $default;
        }

        $this->metrics['hits']++;
        return $entry['value'];
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
        $fullKey = $this->buildKey($key);

        // Enforce item limit
        $this->enforceLimit();

        // Calculate expiration
        $expires = ($ttl > 0) ? time() + $ttl : null;

        $this->store[$fullKey] = [
            'value' => $value,
            'expires' => $expires,
            'stored_at' => time()
        ];

        $this->metrics['sets']++;
        return true;
    }

    /**
     * Delete a value from storage
     *
     * @param string $key The key to delete
     * @return bool True (always succeeds for memory storage)
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->buildKey($key);

        if (isset($this->store[$fullKey])) {
            unset($this->store[$fullKey]);
            $this->metrics['deletes']++;
        }

        return true;
    }

    /**
     * Check if a key exists (and is not expired)
     *
     * @param string $key The key to check
     * @return bool True if exists and not expired
     */
    public function exists(string $key): bool
    {
        $fullKey = $this->buildKey($key);

        if (!isset($this->store[$fullKey])) {
            return false;
        }

        $entry = $this->store[$fullKey];

        if ($this->isExpired($entry)) {
            unset($this->store[$fullKey]);
            return false;
        }

        return true;
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
     * @return bool True (always succeeds for memory storage)
     */
    public function setMultiple(array $items, int $ttl = 0): bool
    {
        foreach ($items as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    /**
     * Delete multiple values
     *
     * @param array $keys Array of keys to delete
     * @return bool True (always succeeds for memory storage)
     */
    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    /**
     * Clear all stored values (within namespace)
     *
     * @return bool True (always succeeds)
     */
    public function clear(): bool
    {
        $prefix = $this->prefix . $this->siteId . ':';

        foreach (array_keys($this->store) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset($this->store[$key]);
            }
        }

        return true;
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

        // Get existing TTL if available
        $fullKey = $this->buildKey($key);
        $ttl = 0;

        if (isset($this->store[$fullKey]) && $this->store[$fullKey]['expires'] !== null) {
            $remaining = $this->store[$fullKey]['expires'] - time();
            $ttl = max(0, $remaining);
        }

        $this->set($key, $newValue, $ttl);
        return $newValue;
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
     * @return bool Always true for memory storage
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Get storage adapter information
     *
     * @return array Adapter information
     */
    public function getInfo(): array
    {
        // Run cleanup to get accurate count
        $this->cleanup();

        return [
            'type' => 'memory',
            'version' => '1.0.0',
            'prefix' => $this->prefix,
            'site_id' => $this->siteId,
            'item_count' => count($this->store),
            'max_items' => $this->config['max_items'],
            'capabilities' => [
                'ttl' => true,
                'atomic_increment' => true,  // Atomic within single request
                'batch_operations' => true,
                'distributed' => false,
                'persistent' => false  // Data lost at end of request
            ],
            'metrics' => $this->metrics,
            'warning' => 'Memory storage is non-persistent. Data is lost when request ends.'
        ];
    }

    /**
     * Get adapter type
     *
     * @return string 'memory'
     */
    public function getType(): string
    {
        return 'memory';
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
            'hit_ratio' => $hitRatio,
            'item_count' => count($this->store)
        ]);
    }

    /**
     * Get all keys (for debugging)
     *
     * @return array Array of keys
     */
    public function getKeys(): array
    {
        $this->cleanup();
        return array_keys($this->store);
    }
}
