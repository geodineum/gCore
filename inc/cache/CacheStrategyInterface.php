<?php
namespace NiertoCube\Cache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface CacheStrategyInterface
 * 
 * Defines the contract for cache implementations in NiertoCube theme.
 * Used by ValKeyStrategy and TransientStrategy.
 * 
 * @package NiertoCube
 * @subpackage Cache
 * @since 1.0.0
 */
interface CacheStrategyInterface {
    /**
     * Get value from cache
     * 
     * @param string $key Cache key
     * @return mixed|false Cached value or false if not found
     */
    public function get(string $key);

    /**
     * Get multiple values from cache
     * 
     * @param array $keys Array of keys
     * @return array Associative array of key => value
     */
    public function getMultiple(array $keys): array;

    /**
     * Set value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds
     * @return bool Success
     */
    public function set(string $key, $value, ?int $ttl = null): bool;

    /**
     * Set multiple values in cache
     * 
     * @param array $values Key-value pairs
     * @param int|null $ttl Time to live in seconds
     * @return bool Success
     */
    public function setMultiple(array $values, ?int $ttl = null): bool;

    /**
     * Delete value from cache
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete(string $key): bool;

    /**
     * Delete multiple values from cache
     * 
     * @param array $keys Cache keys
     * @return bool Success
     */
    public function deleteMultiple(array $keys): bool;

    /**
     * Clear all cache entries
     * 
     * @return bool Success
     */
    public function clear(): bool;

    /**
     * Check if key exists in cache
     * 
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getStats(): array;

    /**
     * Get remaining TTL for key
     * 
     * @param string $key Cache key
     * @return int|null Remaining TTL in seconds or null if not found
     */
    public function getTtl(string $key): ?int;

    /**
     * Check if cache is available
     * 
     * @return bool True if cache is available
     */
    public function isAvailable(): bool;
}