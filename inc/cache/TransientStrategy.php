<?php
namespace NiertoCube\Cache;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * TransientStrategy - WordPress transient-based caching implementation
 * 
 * @package NiertoCube
 * @subpackage Cache
 * @since 1.0.0
 */
class TransientStrategy implements CacheStrategyInterface {
    /** @var array Performance metrics */
    private $metrics = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'errors' => 0
    ];

    /** @var array Configuration */
    private $config;

    /** @var bool Debug mode status */
    private $debug;

    /**
     * Constructor
     * 
     * @param array $config Configuration options
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'prefix' => 'nierto_cube_',
            'default_ttl' => HOUR_IN_SECONDS,
            'max_ttl' => WEEK_IN_SECONDS,
            'min_ttl' => MINUTE_IN_SECONDS,
            'debug' => WP_DEBUG
        ], $config);

        $this->debug = $this->config['debug'];
    }

    /**
     * Get item from cache
     * 
     * @param string $key Cache key
     * @return mixed|false
     */
    public function get(string $key) {
        try {
            $value = get_transient($this->prefixKey($key));
            
            if ($value === false) {
                $this->metrics['misses']++;
                return false;
            }

            $this->metrics['hits']++;
            return $this->maybeUnserialize($value);
        } catch (\Exception $e) {
            $this->logError("Get failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get multiple items from cache
     * 
     * @param array $keys Cache keys
     * @return array
     */
    public function getMultiple(array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Set item in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live
     * @return bool
     */
    public function set(string $key, $value, ?int $ttl = null): bool {
        try {
            $ttl = $this->normalizeTtl($ttl);
            $value = $this->maybeSerialize($value);
            
            $success = set_transient(
                $this->prefixKey($key),
                $value,
                $ttl
            );

            if ($success) {
                $this->metrics['writes']++;
            } else {
                $this->metrics['errors']++;
            }

            return $success;
        } catch (\Exception $e) {
            $this->logError("Set failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set multiple items in cache
     * 
     * @param array $values Key-value pairs
     * @param int|null $ttl Time to live
     * @return bool
     */
    public function setMultiple(array $values, ?int $ttl = null): bool {
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Delete item from cache
     * 
     * @param string $key Cache key
     * @return bool
     */
    public function delete(string $key): bool {
        try {
            return delete_transient($this->prefixKey($key));
        } catch (\Exception $e) {
            $this->logError("Delete failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete multiple items from cache
     * 
     * @param array $keys Cache keys
     * @return bool
     */
    public function deleteMultiple(array $keys): bool {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Clear all cache entries
     * 
     * @return bool
     */
    public function clear(): bool {
        global $wpdb;

        try {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} 
                     WHERE option_name LIKE %s 
                     OR option_name LIKE %s",
                    $wpdb->esc_like('_transient_' . $this->config['prefix']) . '%',
                    $wpdb->esc_like('_transient_timeout_' . $this->config['prefix']) . '%'
                )
            );
            return true;
        } catch (\Exception $e) {
            $this->logError("Clear cache failed: " . $e->getMessage());
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
        return get_transient($this->prefixKey($key)) !== false;
    }

    /**
     * Get remaining TTL for key
     * 
     * @param string $key Cache key
     * @return int|null Remaining TTL in seconds or null if not found
     */
    public function getTtl(string $key): ?int {
        $timeout = get_option('_transient_timeout_' . $this->prefixKey($key));
        return $timeout ? ($timeout - time()) : null;
    }

    /**
     * Get cache statistics
     * 
     * @return array
     */
    public function getStats(): array {
        global $wpdb;

        try {
            $total = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} 
                     WHERE option_name LIKE %s",
                    $wpdb->esc_like('_transient_' . $this->config['prefix']) . '%'
                )
            );

            return array_merge($this->metrics, [
                'total_keys' => $total,
                'hit_ratio' => $this->calculateHitRatio(),
                'driver' => 'transient'
            ]);
        } catch (\Exception $e) {
            $this->logError("Stats collection failed: " . $e->getMessage());
            return $this->metrics;
        }
    }

    /**
     * Check if cache is available
     * 
     * @return bool
     */
    public function isAvailable(): bool {
        return true; // Transients are always available in WordPress
    }

    /**
     * Normalize TTL value
     * 
     * @param int|null $ttl Time to live
     * @return int
     */
    private function normalizeTtl(?int $ttl): int {
        if ($ttl === null) {
            $ttl = $this->config['default_ttl'];
        }
        return max(
            $this->config['min_ttl'],
            min($ttl, $this->config['max_ttl'])
        );
    }

    /**
     * Add prefix to cache key
     * 
     * @param string $key Cache key
     * @return string
     */
    private function prefixKey(string $key): string {
        return $this->config['prefix'] . $key;
    }

    /**
     * Maybe serialize value
     * 
     * @param mixed $value Value to serialize
     * @return mixed
     */
    private function maybeSerialize($value) {
        return is_array($value) || is_object($value) ? serialize($value) : $value;
    }

    /**
     * Maybe unserialize value
     * 
     * @param mixed $value Value to unserialize
     * @return mixed
     */
    private function maybeUnserialize($value) {
        if (!is_string($value)) {
            return $value;
        }

        $unserialized = @unserialize($value);
        return $unserialized !== false ? $unserialized : $value;
    }

    /**
     * Calculate cache hit ratio
     * 
     * @return float
     */
    private function calculateHitRatio(): float {
        $total = $this->metrics['hits'] + $this->metrics['misses'];
        return $total > 0 ? round(($this->metrics['hits'] / $total) * 100, 2) : 0;
    }

    /**
     * Log error message
     * 
     * @param string $message Error message
     */
    private function logError(string $message): void {
        if ($this->debug) {
            error_log("NiertoCube TransientStrategy Error: {$message}");
        }
        $this->metrics['errors']++;
    }
}