<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Cache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Basic Cache Interface
 * 
 * Defines the basic cache operations for the system.
 */
interface CacheInterface {
    /**
     * Set a value in the cache
     * 
     * @param string $key The key
     * @param mixed $value The value
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     */
    public function set(string $key, $value, int $ttl = 0): bool;
    
    /**
     * Get a value from the cache
     * 
     * @param string $key The key
     * @return mixed The value or null if not found
     */
    public function get(string $key);
    
    /**
     * Delete a value from the cache
     * 
     * @param string $key The key
     * @return bool Success status
     */
    public function delete(string $key): bool;
    
    /**
     * Check if a key exists in the cache
     * 
     * @param string $key The key
     * @return bool True if key exists
     */
    public function exists(string $key): bool;
    
    /**
     * Clear all items from the cache
     * 
     * @return bool Success status
     */
    public function clear(): bool;
}