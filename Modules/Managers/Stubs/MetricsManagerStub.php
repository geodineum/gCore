<?php
declare(strict_types=1);
/**
 * MetricsManager Stub
 *
 * Graceful implementation for default tier with StateManager integration.
 * When StateManager is available, provides basic metrics tracking via
 * distributed counters. Without StateManager, returns default values.
 *
 * Upgrade to gcore-metrics for advanced features like:
 * - Prometheus/Grafana integration
 * - Custom dashboards
 * - Alert rules
 * - Extended retention
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.1.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\MetricsManagerInterface;

require_once dirname(__DIR__) . '/Traits/StateManagerAware.php';
use gCore\Modules\Managers\Traits\StateManagerAware;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class MetricsManagerStub
 *
 * Free-tier stub implementation of MetricsManagerInterface with StateManager support.
 * Uses StateManager for basic metric tracking when available.
 *
 * When the full MetricsManager is installed, it replaces this stub
 * and provides full observability features.
 */
class MetricsManagerStub implements MetricsManagerInterface
{
    use StateManagerAware;

    /** @var MetricsManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged this request */
    private static $upgradeNoticeLogged = false;

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => true,
        'site_id' => 'default',
        'stub_mode' => true,
        'latency_window_size' => 100,    // Keep last 100 latency samples
        'rate_limit_window' => 60,       // 60 second rate limit windows
    ];

    /** @var array Capability vector (enhanced when StateManager available) */
    private $capabilityVector = [
        'metrics' => 0.3,          // Basic capability with StateManager
        'monitoring' => 0.1,
        'observability' => 0.1,
        'analytics' => 0.1
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): ModuleInterface
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Initialize stub
     *
     * @param array $config Configuration options
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        $this->config = array_merge($this->defaultConfig, $config);
        $this->siteId = $this->config['site_id'];
        $this->initialized = true;

        // Update capability vector based on StateManager availability
        if ($this->hasStateManager()) {
            $this->capabilityVector['metrics'] = 0.5;
            $this->capabilityVector['monitoring'] = 0.3;
        }

        // Log upgrade notice once per request
        $this->logUpgradeNotice();
    }

    /**
     * Log upgrade notice (once per request)
     */
    private function logUpgradeNotice(): void
    {
        if (self::$upgradeNoticeLogged) {
            return;
        }

        self::$upgradeNoticeLogged = true;

        // Only log in debug mode to avoid noise
        if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
            $mode = $this->hasStateManager() ? 'StateManager-backed' : 'no-op';
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log("[gCore] MetricsManager stub active ({$mode}) - the gcore-metrics extension provides full observability"); }
        }
    }

    // =========================================================================
    // CORE METRICS READING
    // =========================================================================

    /**
     * Get all metrics counters
     *
     * With StateManager: Returns tracked metrics
     * Without: Returns empty array
     */
    public function getAllMetrics(): array
    {
        if (!$this->hasStateManager()) {
            return [];
        }

        $metrics = [];
        $sm = $this->getStateManager();

        // Get common metric types
        $types = ['cache_hits', 'cache_misses', 'requests', 'errors', 'warnings'];
        foreach ($types as $type) {
            $value = $this->getCounter("metric_{$type}");
            if ($value > 0) {
                $metrics[$type] = $value;
            }
        }

        // Add daily metrics
        $today = date('Ymd');
        foreach ($types as $type) {
            $dailyValue = $this->getCounter("metric_{$type}_{$today}");
            if ($dailyValue > 0) {
                $metrics["{$type}_today"] = $dailyValue;
            }
        }

        return $metrics;
    }

    /**
     * Get a specific metric counter
     *
     * With StateManager: Returns actual counter value
     * Without: Returns 0
     */
    public function getMetric(string $metricType): int
    {
        if (!$this->hasStateManager()) {
            return 0;
        }

        return $this->getCounter("metric_{$metricType}");
    }

    /**
     * Get detailed entries (stub: returns empty array)
     *
     * Extension feature - requires full MetricsManager for detailed logging
     */
    public function getMetricDetails(string $metricType, ?int $limit = null): array
    {
        return [];
    }

    /**
     * Get latency statistics
     *
     * With StateManager: Calculates from window of latency samples
     * Without: Returns null values
     */
    public function getLatencyStats(?int $windowSeconds = null): array
    {
        $windowSeconds = $windowSeconds ?? 3600;

        if (!$this->hasStateManager()) {
            return [
                'count' => 0,
                'window_seconds' => $windowSeconds,
                'min' => null,
                'max' => null,
                'avg' => null,
                'p50' => null,
                'p95' => null,
                'p99' => null,
                'stub_mode' => true,
            ];
        }

        $latencies = $this->getWindow('latency_samples');

        if (empty($latencies)) {
            return [
                'count' => 0,
                'window_seconds' => $windowSeconds,
                'min' => null,
                'max' => null,
                'avg' => null,
                'p50' => null,
                'p95' => null,
                'p99' => null,
                'stub_mode' => true,
            ];
        }

        sort($latencies);
        $count = count($latencies);

        return [
            'count' => $count,
            'window_seconds' => $windowSeconds,
            'min' => min($latencies),
            'max' => max($latencies),
            'avg' => array_sum($latencies) / $count,
            'p50' => $this->getWindowPercentile('latency_samples', 50),
            'p95' => $this->getWindowPercentile('latency_samples', 95),
            'p99' => $this->getWindowPercentile('latency_samples', 99),
            'stub_mode' => true,
        ];
    }

    /**
     * Get global metrics (stub: returns basic metrics)
     */
    public function getGlobalMetrics(): array
    {
        return $this->getAllMetrics();
    }

    // =========================================================================
    // AGGREGATED VIEWS
    // =========================================================================

    /**
     * Get metrics summary
     *
     * With StateManager: Returns actual tracked data
     * Without: Returns minimal structure
     */
    public function getSummary(): array
    {
        $metrics = $this->getAllMetrics();

        return [
            'site_id' => $this->config['site_id'],
            'timestamp' => time(),
            'total_metrics' => count($metrics),
            'categories' => $this->categorizeMetrics($metrics),
            'latency' => $this->getLatencyStats(),
            'cache_hit_ratio' => $this->getCacheHitRatio(),
            'stub_mode' => true,
            'has_state_manager' => $this->hasStateManager(),
            'upgrade_message' => 'The gcore-metrics extension provides Prometheus/Grafana integration',
        ];
    }

    /**
     * Get cache hit ratio
     *
     * With StateManager: Calculates from hit/miss counters
     * Without: Returns null
     */
    public function getCacheHitRatio(): ?float
    {
        if (!$this->hasStateManager()) {
            return null;
        }

        $hits = $this->getCounter('metric_cache_hits');
        $misses = $this->getCounter('metric_cache_misses');
        $total = $hits + $misses;

        if ($total === 0) {
            return null;
        }

        return round($hits / $total, 4);
    }

    /**
     * Get operations per second
     *
     * With StateManager: Estimates from recent request counter
     * Without: Returns 0.0
     */
    public function getOpsPerSecond(string $metricType, int $windowSeconds = 60): float
    {
        if (!$this->hasStateManager()) {
            return 0.0;
        }

        // Get current minute's count
        $windowKey = "metric_{$metricType}_" . floor(time() / $windowSeconds);
        $count = $this->getCounter($windowKey);

        return $count / $windowSeconds;
    }

    // =========================================================================
    // METRIC RECORDING
    // =========================================================================

    /**
     * Track a metric
     *
     * With StateManager: Increments distributed counter
     * Without: No-op, returns false
     */
    public function trackMetric(string $metricType, int $value = 1, ?array $extra = null): bool
    {
        if (!$this->hasStateManager()) {
            return false;
        }

        // Increment all-time counter
        $this->incrementCounter("metric_{$metricType}", $value);

        // Increment daily counter
        $this->incrementDaily("metric_{$metricType}", $value);

        // Track latency samples specially
        if ($metricType === 'latency' && isset($extra['duration'])) {
            $windowSize = $this->config['latency_window_size'];
            $this->addToWindow('latency_samples', $extra['duration'], $windowSize);
        }

        return true;
    }

    /**
     * Record a metric (backward compatibility alias)
     */
    public function recordMetric(string $metricType, array $data = []): bool
    {
        $value = $data['value'] ?? 1;
        return $this->trackMetric($metricType, $value, $data);
    }

    /**
     * Record request latency
     *
     * @param float $durationMs Duration in milliseconds
     * @return bool Success
     */
    public function recordLatency(float $durationMs): bool
    {
        return $this->trackMetric('latency', 1, ['duration' => $durationMs]);
    }

    /**
     * Record cache hit
     */
    public function recordCacheHit(): bool
    {
        return $this->trackMetric('cache_hits', 1);
    }

    /**
     * Record cache miss
     */
    public function recordCacheMiss(): bool
    {
        return $this->trackMetric('cache_misses', 1);
    }

    // =========================================================================
    // RATE LIMITING
    // =========================================================================

    /**
     * Check rate limit for an operation
     *
     * @param string $operation Operation identifier
     * @param int $limit Maximum operations per window
     * @param int|null $windowSeconds Window size in seconds
     * @return array ['allowed' => bool, 'current' => int, 'limit' => int, 'remaining' => int]
     */
    public function checkOperationRateLimit(string $operation, int $limit, ?int $windowSeconds = null): array
    {
        $windowSeconds = $windowSeconds ?? $this->config['rate_limit_window'];
        $key = "ratelimit_{$operation}";

        return $this->checkRateLimit($key, $limit, $windowSeconds);
    }

    // =========================================================================
    // CAPABILITY DISCOVERY
    // =========================================================================

    /**
     * Get capability vector (reflects StateManager availability)
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Categorize metrics by type
     */
    private function categorizeMetrics(array $metrics): array
    {
        $categories = [
            'cache' => [],
            'requests' => [],
            'errors' => [],
            'other' => [],
        ];

        foreach ($metrics as $key => $value) {
            if (strpos($key, 'cache') !== false) {
                $categories['cache'][$key] = $value;
            } elseif (strpos($key, 'request') !== false) {
                $categories['requests'][$key] = $value;
            } elseif (strpos($key, 'error') !== false || strpos($key, 'warning') !== false) {
                $categories['errors'][$key] = $value;
            } else {
                $categories['other'][$key] = $value;
            }
        }

        return array_filter($categories, fn($cat) => !empty($cat));
    }

    // =========================================================================
    // MODULE INTERFACE METHODS
    // =========================================================================

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->hasStateManager(),
            'stub_mode' => true,
            'site_id' => $this->config['site_id'],
            'has_state_manager' => $this->hasStateManager(),
            'mode' => $this->hasStateManager() ? 'state_backed' : 'no_op',
            'tracked_metrics' => count($this->getAllMetrics()),
            'upgrade_message' => 'The gcore-metrics extension provides full observability',
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
