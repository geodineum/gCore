<?php
declare(strict_types=1);
/**
 * gNode Storage Adapter - Wraps gNode-Client for StorageInterface
 *
 * ALL storage operations flow through gNode-Client, ensuring:
 * - Consistent credential management (auto-discovery)
 * - Automatic metrics tracking via Lua functions
 * - Per-site isolation and accountability
 * - Single point of ValKey access
 *
 * Architecture:
 *   gCore Managers → gNodeStorageAdapter → gNode-Client → ValKey
 *
 * This is the ONLY way gCore should access ValKey storage.
 * Never use ValKeyStorage directly - always go through gNode-Client.
 *
 * @package gCore\Modules\Storage
 * @version 3.0.0
 */
namespace gCore\Modules\Storage;

use gCore\Modules\Core\Interfaces\Shared\StorageInterface;
use gCore\Modules\Core\Exceptions\StorageException;
use gCore\gNode\gNodeClientInterface;

class gNodeStorageAdapter implements StorageInterface
{
    /**
     * gNode-Client instance (the ONLY way to access ValKey)
     * Accepts both gNodeClient (new) and KeyBasedClientLuaEnabled (deprecated)
     * @var gNodeClientInterface|object
     */
    private $gNodeClient;

    /**
     * Site identifier for multi-tenant isolation
     * @var string
     */
    private $siteId;

    /**
     * Debug mode flag
     * @var bool
     */
    private $debug = false;

    /**
     * Constructor
     *
     * @param gNodeClientInterface|object $gNodeClient gNode-Client instance (gNodeClient or KeyBasedClientLuaEnabled)
     * @param string $siteId Site identifier (for logging/metrics context)
     * @param array $config Configuration options:
     *   - debug: bool Enable debug logging
     */
    public function __construct($gNodeClient, string $siteId = 'default', array $config = [])
    {
        $this->gNodeClient = $gNodeClient;
        $this->siteId = $siteId;
        $this->debug = $config['debug'] ?? false;

        if ($this->debug) {
            error_log("gNodeStorageAdapter: Initialized with gNode-Client (site={$siteId})");
        }
    }

    /**
     * Get the underlying gNode-Client instance
     *
     * Use this when you need gNode-Client specific features like:
     * - geometricDiscover()
     * - batchExec()
     * - templateRender()
     *
     * @return gNodeClientInterface|object
     */
    public function getgNodeClient()
    {
        return $this->gNodeClient;
    }

    /**
     * Get the site identifier
     *
     * @return string
     */
    public function getSiteId(): string
    {
        return $this->siteId;
    }

    /**
     * Check if connected to storage
     *
     * @return bool Connection status
     */
    public function isConnected(): bool
    {
        try {
            return $this->gNodeClient->isConnected();
        } catch (\Exception $e) {
            $this->logDebug("isConnected error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set a value in storage using gNode-Client Lua function
     *
     * @param string $key Storage key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     */
    public function set(string $key, $value, int $ttl = 0): bool
    {
        try {
            return $this->gNodeClient->luaSet($key, $value, $ttl);
        } catch (\Exception $e) {
            $this->logDebug("SET error for key '{$key}': " . $e->getMessage());
            throw new StorageException("Failed to set key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get a value from storage using gNode-Client Lua function
     *
     * @param string $key Storage key
     * @return mixed Stored value or null if not found
     */
    public function get(string $key)
    {
        try {
            return $this->gNodeClient->luaGet($key);
        } catch (\Exception $e) {
            $this->logDebug("GET error for key '{$key}': " . $e->getMessage());
            throw new StorageException("Failed to get key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a value from storage using gNode-Client Lua function
     *
     * @param string $key Storage key
     * @return bool Success status
     */
    public function delete(string $key): bool
    {
        try {
            return $this->gNodeClient->luaDel($key);
        } catch (\Exception $e) {
            $this->logDebug("DELETE error for key '{$key}': " . $e->getMessage());
            throw new StorageException("Failed to delete key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if a key exists in storage
     *
     * @param string $key Storage key
     * @return bool True if key exists
     */
    public function exists(string $key): bool
    {
        try {
            return $this->gNodeClient->luaExists($key);
        } catch (\Exception $e) {
            $this->logDebug("EXISTS error for key '{$key}': " . $e->getMessage());
            throw new StorageException("Failed to check key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all keys matching a pattern
     *
     * @param string $pattern Key pattern (e.g. "user:*")
     * @return array Array of matching keys
     */
    public function keys(string $pattern): array
    {
        try {
            return $this->gNodeClient->keys($pattern);
        } catch (\Exception $e) {
            $this->logDebug("KEYS error for pattern '{$pattern}': " . $e->getMessage());
            throw new StorageException("Failed to get keys for pattern '{$pattern}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Batch get multiple values (batched)
     *
     * Uses gNode-Client's optimized batch operations.
     *
     * @param array $keys Array of keys to get
     * @return array Associative array of key => value pairs
     */
    public function batchGet(array $keys): array
    {
        try {
            return $this->gNodeClient->batchCacheGet($keys);
        } catch (\Exception $e) {
            $this->logDebug("BATCH GET error: " . $e->getMessage());
            throw new StorageException("Failed to batch get: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Batch set multiple values (batched)
     *
     * Uses gNode-Client's optimized batch operations.
     *
     * @param array $keyValues Associative array of key => value pairs
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     */
    public function batchSet(array $keyValues, int $ttl = 0): bool
    {
        try {
            return $this->gNodeClient->batchCacheSet($keyValues, $ttl);
        } catch (\Exception $e) {
            $this->logDebug("BATCH SET error: " . $e->getMessage());
            throw new StorageException("Failed to batch set: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check rate limit using gNode-Client
     *
     * @param string $operation Operation identifier (e.g., 'api', 'render')
     * @param int $limit Maximum requests allowed in window (default: 100)
     * @param int $window Time window in seconds (default: 60)
     * @return array ['allowed' => bool, 'current' => int, 'limit' => int, 'remaining' => int]
     */
    public function checkRateLimit(string $operation, int $limit = 100, int $window = 60): array
    {
        try {
            return $this->gNodeClient->checkRateLimit($operation, $limit, $window);
        } catch (\Exception $e) {
            $this->logDebug("Rate limit error: {$e->getMessage()}");
            // Fail open on error
            return [
                'allowed' => true,
                'current' => 0,
                'limit' => $limit,
                'remaining' => $limit,
                'window' => $window,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get cache statistics
     *
     * @return array Stats including hits, misses, writes, hit_ratio, etc.
     */
    public function getCacheStats(): array
    {
        try {
            return $this->gNodeClient->getCacheStats();
        } catch (\Exception $e) {
            $this->logDebug("Cache stats error: {$e->getMessage()}");
            return ['error' => $e->getMessage(), 'site_id' => $this->siteId];
        }
    }

    /**
     * Increment a key value
     *
     * @param string $key Storage key
     * @param int $by Amount to increment by
     * @return int New value
     */
    public function incrBy(string $key, int $by = 1): int
    {
        try {
            return $this->gNodeClient->luaIncrBy($key, $by);
        } catch (\Exception $e) {
            $this->logDebug("INCRBY error for key '{$key}': " . $e->getMessage());
            throw new StorageException("Failed to increment key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrement a key value
     *
     * @param string $key Storage key
     * @param int $by Amount to decrement by
     * @return int New value
     */
    public function decrBy(string $key, int $by = 1): int
    {
        try {
            return $this->gNodeClient->luaDecrBy($key, $by);
        } catch (\Exception $e) {
            $this->logDebug("DECRBY error for key '{$key}': " . $e->getMessage());
            throw new StorageException("Failed to decrement key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Set a hash field
     *
     * @param string $key Hash key
     * @param string $field Field name
     * @param mixed $value Field value
     * @return bool True if set succeeded
     */
    public function hSet(string $key, string $field, $value): bool
    {
        try {
            return $this->gNodeClient->luaHSet($key, $field, $value);
        } catch (\Exception $e) {
            $this->logDebug("HSET error: " . $e->getMessage());
            throw new StorageException("Failed to hSet: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get a hash field
     *
     * @param string $key Hash key
     * @param string $field Field name
     * @return mixed Field value or null if not found
     */
    public function hGet(string $key, string $field)
    {
        try {
            return $this->gNodeClient->luaHGet($key, $field);
        } catch (\Exception $e) {
            $this->logDebug("HGET error: " . $e->getMessage());
            throw new StorageException("Failed to hGet: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get all hash fields
     *
     * @param string $key Hash key
     * @return array Associative array of field => value pairs
     */
    public function hGetAll(string $key): array
    {
        try {
            return $this->gNodeClient->luaHGetAll($key);
        } catch (\Exception $e) {
            $this->logDebug("HGETALL error: " . $e->getMessage());
            throw new StorageException("Failed to hGetAll: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Publish message to a channel
     *
     * @param string $channel Channel name
     * @param string $message Message to publish
     * @return int Number of subscribers that received the message
     */
    public function publish(string $channel, string $message): int
    {
        try {
            return $this->gNodeClient->publish($channel, $message);
        } catch (\Exception $e) {
            $this->logDebug("PUBLISH error: " . $e->getMessage());
            throw new StorageException("Failed to publish: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Call a registered Lua function via gNode-Client
     *
     * @param string $function Function name (e.g., 'GNODE_CACHE_GET')
     * @param array $keys Keys to pass
     * @param array $args Arguments to pass
     * @return mixed Function result
     */
    public function fcall(string $function, array $keys, array $args)
    {
        try {
            return $this->gNodeClient->fcall($function, $keys, $args);
        } catch (\Exception $e) {
            $this->logDebug("FCALL error for '{$function}': " . $e->getMessage());
            throw new StorageException("Failed to call function '{$function}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Magic method to proxy method calls to gNode-Client
     *
     * Allows access to gNode-Client methods not explicitly defined here.
     *
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed Method result
     */
    public function __call(string $method, array $args)
    {
        if (method_exists($this->gNodeClient, $method)) {
            return call_user_func_array([$this->gNodeClient, $method], $args);
        }

        throw new StorageException("Method '{$method}' not found in gNode-Client");
    }

    /**
     * Log debug message
     *
     * @param string $message Debug message
     */
    private function logDebug(string $message): void
    {
        if ($this->debug) {
            error_log("gNodeStorageAdapter: {$message}");
        }
    }
}
