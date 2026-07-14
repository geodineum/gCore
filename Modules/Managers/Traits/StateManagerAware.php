<?php
declare(strict_types=1);
/**
 * StateManagerAware Trait
 *
 * Provides easy StateManager integration for gCore managers.
 * Includes helper methods for common patterns like counters, windows, and deduplication.
 *
 * Usage:
 *   use gCore\Modules\Managers\Traits\StateManagerAware;
 *
 *   class MyManager {
 *       use StateManagerAware;
 *
 *       public function trackSomething() {
 *           $this->incrementCounter('my_metric', 1);
 *       }
 *   }
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Traits
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Traits;

use gCore\Modules\Core\Interfaces\Extensions\StateManagerInterface;

trait StateManagerAware
{
    /** @var StateManagerInterface|null Cached StateManager instance */
    private $stateManager = null;

    /** @var bool Whether we've attempted to load StateManager */
    private $stateManagerChecked = false;

    /** @var string Site ID for key prefixing */
    protected $siteId = 'default';

    // =========================================================================
    // CORE ACCESS
    // =========================================================================

    /**
     * Get StateManager instance (lazy loaded)
     *
     * @return StateManagerInterface|null StateManager or null if unavailable
     */
    protected function getStateManager(): ?StateManagerInterface
    {
        if ($this->stateManagerChecked) {
            return $this->stateManager;
        }

        $this->stateManagerChecked = true;

        try {
            // Get gCore instance
            if (class_exists('\\gCore\\Modules\\Core\\gCore')) {
                $gCore = \gCore\Modules\Core\gCore::getInstance();
                if ($gCore && method_exists($gCore, 'getService')) {
                    $sm = $gCore->getService('StateManager');
                    if ($sm instanceof StateManagerInterface) {
                        $this->stateManager = $sm;
                    }
                }
            }
        } catch (\Throwable $e) {
            // StateManager not available - graceful degradation
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[gCore] StateManager not available: " . $e->getMessage());
            }
        }

        return $this->stateManager;
    }

    /**
     * Check if StateManager is available
     *
     * @return bool True if StateManager is available and usable
     */
    protected function hasStateManager(): bool
    {
        return $this->getStateManager() !== null;
    }

    // =========================================================================
    // COUNTER OPERATIONS
    // =========================================================================

    /**
     * Increment a counter atomically
     *
     * @param string $key Counter key (will be prefixed with site_id)
     * @param int $delta Amount to increment (default 1)
     * @param int|null $ttl Optional TTL in seconds
     * @return int New counter value
     */
    protected function incrementCounter(string $key, int $delta = 1, ?int $ttl = null): int
    {
        $sm = $this->getStateManager();
        if ($sm) {
            return $sm->increment($key, $delta, $ttl);
        }

        // Fallback: return delta (no persistence)
        return $delta;
    }

    /**
     * Decrement a counter atomically
     *
     * @param string $key Counter key
     * @param int $delta Amount to decrement (default 1)
     * @param int|null $ttl Optional TTL in seconds
     * @return int New counter value
     */
    protected function decrementCounter(string $key, int $delta = 1, ?int $ttl = null): int
    {
        $sm = $this->getStateManager();
        if ($sm) {
            return $sm->decrement($key, $delta, $ttl);
        }

        // Fallback: return negative delta
        return -$delta;
    }

    /**
     * Get current counter value
     *
     * @param string $key Counter key
     * @param int $default Default value if counter doesn't exist
     * @return int Counter value
     */
    protected function getCounter(string $key, int $default = 0): int
    {
        $sm = $this->getStateManager();
        if ($sm) {
            return (int) $sm->getState($key, $default);
        }

        return $default;
    }

    // =========================================================================
    // DAILY/WINDOWED COUNTERS
    // =========================================================================

    /**
     * Increment a daily counter (auto-resets at midnight)
     *
     * @param string $metric Metric name
     * @param int $delta Amount to increment
     * @return int New counter value
     */
    protected function incrementDaily(string $metric, int $delta = 1): int
    {
        $key = $metric . '_' . date('Ymd');
        return $this->incrementCounter($key, $delta);
    }

    /**
     * Get daily counter value
     *
     * @param string $metric Metric name
     * @param string|null $date Date in Ymd format (null = today)
     * @return int Counter value
     */
    protected function getDailyCounter(string $metric, ?string $date = null): int
    {
        $date = $date ?? date('Ymd');
        $key = $metric . '_' . $date;
        return $this->getCounter($key, 0);
    }

    /**
     * Increment an hourly counter
     *
     * @param string $metric Metric name
     * @param int $delta Amount to increment
     * @return int New counter value
     */
    protected function incrementHourly(string $metric, int $delta = 1): int
    {
        $key = $metric . '_' . date('YmdH');
        return $this->incrementCounter($key, $delta);
    }

    // =========================================================================
    // DEDUPLICATION
    // =========================================================================

    /**
     * Track a unique item, returning whether it was new
     *
     * Useful for unique visitor counting, etc.
     *
     * @param string $setKey Set key for storing unique items
     * @param string $item Item to track
     * @param int $maxItems Maximum items to track before pruning
     * @return bool True if item was new (not seen before)
     */
    protected function trackUnique(string $setKey, string $item, int $maxItems = 10000): bool
    {
        $sm = $this->getStateManager();
        if (!$sm) {
            return true; // No deduplication without StateManager
        }

        $set = $sm->getState($setKey, []);
        if (!is_array($set)) {
            $set = [];
        }

        // Check if already seen
        if (in_array($item, $set, true)) {
            return false;
        }

        // Add to set
        $set[] = $item;

        // Prune if too large (remove oldest entries)
        if (count($set) > $maxItems) {
            $set = array_slice($set, -$maxItems);
        }

        $sm->setState($setKey, $set, true);
        return true;
    }

    /**
     * Get count of unique items in a set
     *
     * @param string $setKey Set key
     * @return int Number of unique items
     */
    protected function getUniqueCount(string $setKey): int
    {
        $sm = $this->getStateManager();
        if (!$sm) {
            return 0;
        }

        $set = $sm->getState($setKey, []);
        return is_array($set) ? count($set) : 0;
    }

    /**
     * Track unique item with daily window (auto-resets daily)
     *
     * @param string $metric Metric name
     * @param string $item Item to track
     * @return bool True if item was new today
     */
    protected function trackUniqueDaily(string $metric, string $item): bool
    {
        $setKey = $metric . '_unique_' . date('Ymd');
        return $this->trackUnique($setKey, $item);
    }

    // =========================================================================
    // RATE LIMITING
    // =========================================================================

    /**
     * Check and increment rate limit counter
     *
     * @param string $key Rate limit key (e.g., 'api_' . $ip)
     * @param int $limit Maximum requests allowed
     * @param int $window Window size in seconds
     * @return array ['allowed' => bool, 'current' => int, 'limit' => int, 'remaining' => int]
     */
    protected function checkRateLimit(string $key, int $limit, int $window = 60): array
    {
        // Build windowed key
        $windowKey = $key . '_' . floor(time() / $window);

        $current = $this->incrementCounter($windowKey, 1);
        $allowed = $current <= $limit;

        return [
            'allowed' => $allowed,
            'current' => $current,
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
        ];
    }

    // =========================================================================
    // STATE HELPERS
    // =========================================================================

    /**
     * Get state value with default
     *
     * @param string $key State key
     * @param mixed $default Default value
     * @return mixed State value or default
     */
    protected function getDistributedState(string $key, $default = null)
    {
        $sm = $this->getStateManager();
        if ($sm) {
            return $sm->getState($key, $default);
        }
        return $default;
    }

    /**
     * Set state value
     *
     * @param string $key State key
     * @param mixed $value Value to store
     * @param bool $skipValidation Skip validation
     * @return bool Success
     */
    protected function setDistributedState(string $key, $value, bool $skipValidation = false): bool
    {
        $sm = $this->getStateManager();
        if ($sm) {
            return $sm->setState($key, $value, $skipValidation);
        }
        return false;
    }

    /**
     * Check if state key exists
     *
     * @param string $key State key
     * @return bool True if exists
     */
    protected function hasDistributedState(string $key): bool
    {
        $sm = $this->getStateManager();
        if ($sm) {
            return $sm->hasState($key);
        }
        return false;
    }

    // =========================================================================
    // WINDOWED AGGREGATION
    // =========================================================================

    /**
     * Add value to a sliding window array
     *
     * Useful for calculating moving averages, percentiles, etc.
     *
     * @param string $key Window key
     * @param mixed $value Value to add
     * @param int $maxSize Maximum window size
     * @return array Current window values
     */
    protected function addToWindow(string $key, $value, int $maxSize = 100): array
    {
        $sm = $this->getStateManager();
        if (!$sm) {
            return [$value];
        }

        $window = $sm->getState($key, []);
        if (!is_array($window)) {
            $window = [];
        }

        // Add new value
        $window[] = $value;

        // Prune to max size
        if (count($window) > $maxSize) {
            $window = array_slice($window, -$maxSize);
        }

        $sm->setState($key, $window, true);

        return $window;
    }

    /**
     * Get current window values
     *
     * @param string $key Window key
     * @return array Window values
     */
    protected function getWindow(string $key): array
    {
        $sm = $this->getStateManager();
        if (!$sm) {
            return [];
        }

        $window = $sm->getState($key, []);
        return is_array($window) ? $window : [];
    }

    /**
     * Calculate percentile from window
     *
     * @param string $key Window key
     * @param float $percentile Percentile (0-100)
     * @return float|null Percentile value or null if window empty
     */
    protected function getWindowPercentile(string $key, float $percentile): ?float
    {
        $window = $this->getWindow($key);
        if (empty($window)) {
            return null;
        }

        sort($window);
        $count = count($window);
        $index = ($percentile / 100) * ($count - 1);

        // Linear interpolation for non-integer indices
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper || $upper >= $count) {
            return (float) $window[$lower];
        }

        return $window[$lower] + ($window[$upper] - $window[$lower]) * $fraction;
    }
}
