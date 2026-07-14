<?php
declare(strict_types=1);
/**
 * StorageInterface - Universal Storage Contract for gCore
 *
 * This interface defines the contract that all storage adapters must implement.
 * It's inspired by PSR-16 (Simple Cache) but tailored for gCore's needs.
 *
 * Storage adapters allow gCore to work with different backends:
 * - TransientStorage: WordPress transients (default tier, primary)
 * - MemoryStorage: In-memory storage (testing, non-WP fallback)
 * - ValKeyStorage: ValKey/Redis (gNode-enhanced)
 *
 * @package     gCore
 * @subpackage  Storage
 * @version     1.0.0
 */

namespace gCore\Modules\Storage\Interfaces;

if (!defined('ABSPATH')) {
    // Allow standalone usage outside WordPress
    if (!defined('GCORE_STANDALONE')) {
        define('GCORE_STANDALONE', true);
    }
}

/**
 * StorageInterface
 *
 * Defines the contract for all gCore storage adapters.
 * Implementations should handle serialization internally.
 */
interface StorageInterface
{
    /**
     * Get a value from storage
     *
     * @param string $key The unique key of this item
     * @param mixed $default Default value to return if key doesn't exist
     * @return mixed The value stored, or $default if not found
     */
    public function get(string $key, $default = null);

    /**
     * Store a value in storage
     *
     * @param string $key The key under which to store the value
     * @param mixed $value The value to store (will be serialized)
     * @param int $ttl Time-to-live in seconds (0 = no expiration)
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value, int $ttl = 0): bool;

    /**
     * Delete a value from storage
     *
     * @param string $key The unique key to delete
     * @return bool True if deleted (or didn't exist), false on error
     */
    public function delete(string $key): bool;

    /**
     * Check if a key exists in storage
     *
     * @param string $key The key to check
     * @return bool True if exists and not expired
     */
    public function exists(string $key): bool;

    /**
     * Get multiple values from storage
     *
     * @param array $keys Array of keys to retrieve
     * @param mixed $default Default value for missing keys
     * @return array Associative array of key => value pairs
     */
    public function getMultiple(array $keys, $default = null): array;

    /**
     * Store multiple values in storage
     *
     * @param array $items Associative array of key => value pairs
     * @param int $ttl Time-to-live in seconds (0 = no expiration)
     * @return bool True if all succeeded, false if any failed
     */
    public function setMultiple(array $items, int $ttl = 0): bool;

    /**
     * Delete multiple values from storage
     *
     * @param array $keys Array of keys to delete
     * @return bool True if all deleted, false if any failed
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * Clear all values from storage (within namespace/prefix)
     *
     * @return bool True on success, false on failure
     */
    public function clear(): bool;

    /**
     * Increment a numeric value
     *
     * @param string $key The key to increment
     * @param int $step Amount to increment by (default: 1)
     * @return int|false New value on success, false on failure
     */
    public function increment(string $key, int $step = 1);

    /**
     * Decrement a numeric value
     *
     * @param string $key The key to decrement
     * @param int $step Amount to decrement by (default: 1)
     * @return int|false New value on success, false on failure
     */
    public function decrement(string $key, int $step = 1);

    /**
     * Check if storage backend is available/connected
     *
     * @return bool True if storage is operational
     */
    public function isAvailable(): bool;

    /**
     * Get storage adapter information
     *
     * @return array Adapter info including type, version, capabilities
     */
    public function getInfo(): array;

    /**
     * Get the adapter type identifier
     *
     * @return string Adapter type (e.g., 'transient', 'memory', 'valkey')
     */
    public function getType(): string;
}
