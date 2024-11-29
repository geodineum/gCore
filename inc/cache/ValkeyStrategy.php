<?php
namespace NiertoCube\Cache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ValKey Strategy - Implements caching strategy using ValKey
 * 
 * @package NiertoCube
 * @subpackage Cache
 * @since 1.0.0
 */
class ValKeyStrategy implements CacheStrategyInterface {
    /** @var \Redis|null Redis connection instance */
    private $connection = null;

    /** @var array ValKey configuration */
    private $config;

    /** @var bool Connection state */
    private $isConnected = false;

    /** @var array Error log */
    private $errors = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->config = $this->loadConfig();
        if ($this->config['enabled']) {
            $this->initializeConnection();
        }
    }

    /**
     * Load ValKey configuration
     * 
     * @return array Configuration settings
     */
    private function loadConfig(): array {
        $settings = get_option('nierto_cube_settings', []);
        return [
            'enabled' => !empty($settings['use_valkey']),
            'host' => $settings['valkey_ip'] ?? '127.0.0.1',
            'port' => $settings['valkey_port'] ?? 6379,
            'auth' => $settings['valkey_auth'] ?? '',
            'timeout' => 2.0,
            'retry_interval' => 100,
            'read_timeout' => 1.0
        ];
    }

    /**
     * Initialize ValKey connection
     * 
     * @throws \RuntimeException If connection fails
     */
    private function initializeConnection(): void {
        if ($this->isConnected) {
            return;
        }

        try {
            $this->connection = new \Redis();
            
            // Enable automatic reconnection
            $this->connection->setOption(\Redis::OPT_RECONNECT, true);
            
            // Connect with timeout
            $connected = $this->connection->connect(
                $this->config['host'],
                $this->config['port'],
                $this->config['timeout']
            );

            if (!$connected) {
                throw new \RuntimeException('Failed to connect to ValKey server');
            }

            // Authenticate if credentials provided
            if (!empty($this->config['auth'])) {
                $authenticated = $this->connection->auth($this->config['auth']);
                if (!$authenticated) {
                    throw new \RuntimeException('ValKey authentication failed');
                }
            }

            $this->isConnected = true;

        } catch (\Exception $e) {
            $this->logError('Connection failed: ' . $e->getMessage());
            $this->connection = null;
            throw new \RuntimeException('ValKey connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get value from cache
     * 
     * @param string $key Cache key
     * @return mixed|false Cached value or false if not found
     */
    public function get(string $key) {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $value = $this->connection->get($key);
            return $value !== false ? $this->unserializeValue($value) : false;
        } catch (\Exception $e) {
            $this->logError("Get failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get multiple values from cache
     * 
     * @param array $keys Array of keys
     * @return array Associative array of key => value
     */
    public function getMultiple(array $keys): array {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $values = $this->connection->mget($keys);
            $result = [];
            
            foreach ($keys as $i => $key) {
                if ($values[$i] !== false) {
                    $result[$key] = $this->unserializeValue($values[$i]);
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logError('Multi-get failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Set value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds
     * @return bool Success
     */
    public function set(string $key, $value, ?int $ttl = null): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $serialized = $this->serializeValue($value);
            return $ttl !== null ? 
                $this->connection->setex($key, $ttl, $serialized) :
                $this->connection->set($key, $serialized);
        } catch (\Exception $e) {
            $this->logError("Set failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set multiple values in cache
     * 
     * @param array $values Key-value pairs
     * @param int|null $ttl Time to live in seconds
     * @return bool Success
     */
    public function setMultiple(array $values, ?int $ttl = null): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            $pipeline = $this->connection->pipeline();
            foreach ($values as $key => $value) {
                $serialized = $this->serializeValue($value);
                if ($ttl !== null) {
                    $pipeline->setex($key, $ttl, $serialized);
                } else {
                    $pipeline->set($key, $serialized);
                }
            }
            $results = $pipeline->exec();
            
            return !in_array(false, $results, true);
        } catch (\Exception $e) {
            $this->logError('Multi-set failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete value from cache
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete(string $key): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return $this->connection->del($key) > 0;
        } catch (\Exception $e) {
            $this->logError("Delete failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete multiple values from cache
     * 
     * @param array $keys Cache keys
     * @return bool Success
     */
    public function deleteMultiple(array $keys): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return $this->connection->del(...$keys) > 0;
        } catch (\Exception $e) {
            $this->logError('Multi-delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all cache entries
     * 
     * @return bool Success
     */
    public function clear(): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return $this->connection->flushDB();
        } catch (\Exception $e) {
            $this->logError('Clear cache failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if key exists in cache
     * 
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        try {
            return $this->connection->exists($key) > 0;
        } catch (\Exception $e) {
            $this->logError("Exists check failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    public function getStats(): array {
        if (!$this->isAvailable()) {
            return [
                'status' => 'unavailable',
                'errors' => $this->errors
            ];
        }

        try {
            $info = $this->connection->info();
            return [
                'status' => 'connected',
                'hits' => $info['keyspace_hits'] ?? 0,
                'misses' => $info['keyspace_misses'] ?? 0,
                'memory_used' => $info['used_memory_human'] ?? '0B',
                'uptime' => $info['uptime_in_seconds'] ?? 0,
                'version' => $info['redis_version'] ?? 'unknown',
                'errors' => $this->errors
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $this->errors
            ];
        }
    }

    /**
     * Get remaining TTL for key
     * 
     * @param string $key Cache key
     * @return int|null Remaining TTL in seconds or null if not found
     */
    public function getTtl(string $key): ?int {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $ttl = $this->connection->ttl($key);
            return $ttl > 0 ? $ttl : null;
        } catch (\Exception $e) {
            $this->logError("TTL check failed for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if cache is available
     * 
     * @return bool True if cache is available
     */
    public function isAvailable(): bool {
        if (!$this->config['enabled']) {
            return false;
        }

        if (!$this->isConnected) {
            try {
                $this->initializeConnection();
            } catch (\Exception $e) {
                return false;
            }
        }

        return $this->isConnected;
    }

    /**
     * Serialize value for storage
     * 
     * @param mixed $value Value to serialize
     * @return string Serialized value
     */
    private function serializeValue($value): string {
        return is_numeric($value) ? (string)$value : serialize($value);
    }

    /**
     * Unserialize value from storage
     * 
     * @param string $value Serialized value
     * @return mixed Unserialized value
     */
    private function unserializeValue(string $value) {
        if (is_numeric($value)) {
            return $value;
        }

        $unserialized = @unserialize($value);
        return $unserialized !== false ? $unserialized : $value;
    }

    /**
     * Log error message
     * 
     * @param string $message Error message
     */
    private function logError(string $message): void {
        $this->errors[] = [
            'time' => time(),
            'message' => $message
        ];

        if (WP_DEBUG) {
            error_log('ValKey Error: ' . $message);
        }

        // Keep only last 100 errors
        if (count($this->errors) > 100) {
            array_shift($this->errors);
        }
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct() {
        if ($this->connection) {
            try {
                $this->connection->close();
            } catch (\Exception $e) {
                // Ignore closing errors
            }
        }
    }
}