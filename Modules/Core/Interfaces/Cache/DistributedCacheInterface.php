<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Cache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Distributed Cache Interface
 * 
 * Extends the basic cache interface with distributed capabilities.
 */
interface DistributedCacheInterface extends CacheInterface {
    /**
     * Increment value
     * 
     * @param string $key The key
     * @param int $by Amount to increment by
     * @return int|false New value or false on failure
     */
    public function increment(string $key, int $by = 1);
    
    /**
     * Decrement value
     * 
     * @param string $key The key
     * @param int $by Amount to decrement by
     * @return int|false New value or false on failure
     */
    public function decrement(string $key, int $by = 1);
    
    /**
     * Set if not exists
     * 
     * @param string $key The key
     * @param mixed $value The value
     * @param int $ttl Time to live in seconds
     * @return bool True if set, false if key already exists
     */
    public function setNx(string $key, $value, int $ttl = 0): bool;
    
    /**
     * Get multiple cache items
     * 
     * @param array $keys The keys
     * @return array Associative array of key => value pairs
     */
    public function getMultiple(array $keys): array;
    
    /**
     * Set multiple cache items
     * 
     * @param array $items Associative array of key => value pairs
     * @param int $ttl Time to live in seconds
     * @return bool Success status
     */
    public function setMultiple(array $items, int $ttl = 0): bool;
    
    /**
     * Delete multiple cache items
     * 
     * @param array $keys The keys
     * @return bool Success status
     */
    public function deleteMultiple(array $keys): bool;
}