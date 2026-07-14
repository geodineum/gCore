<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Basic Storage Interface
 * 
 * Defines the fundamental storage operations.
 */
interface StorageInterface {
    /**
     * Set a value in storage
     * 
     * @param string $key Storage key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     */
    public function set(string $key, $value, int $ttl = 0): bool;
    
    /**
     * Get a value from storage
     * 
     * @param string $key Storage key
     * @return mixed Stored value or null if not found
     */
    public function get(string $key);
    
    /**
     * Delete a value from storage
     * 
     * @param string $key Storage key
     * @return bool Success status
     */
    public function delete(string $key): bool;
    
    /**
     * Check if a key exists in storage
     * 
     * @param string $key Storage key
     * @return bool True if key exists
     */
    public function exists(string $key): bool;
    
    /**
     * Get all keys matching a pattern
     * 
     * @param string $pattern Key pattern (e.g. "user:*")
     * @return array Array of matching keys
     */
    public function keys(string $pattern): array;
}