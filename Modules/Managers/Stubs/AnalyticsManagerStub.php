<?php
declare(strict_types=1);
/**
 * AnalyticsManager Stub
 *
 * Graceful implementation for default tier with StateManager integration.
 * When StateManager is available, provides basic visitor/pageview tracking
 * via distributed counters and unique tracking.
 *
 * Upgrade to gcore-analytics for advanced features like:
 * - Journey analysis and visualization
 * - Detailed resource cost tracking
 * - Multi-day retention and trends
 * - Export and reporting
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.1.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\AnalyticsManagerInterface;

require_once dirname(__DIR__) . '/Traits/StateManagerAware.php';
use gCore\Modules\Managers\Traits\StateManagerAware;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class AnalyticsManagerStub
 *
 * Free-tier stub implementation of AnalyticsManagerInterface with StateManager support.
 * Uses StateManager for basic visitor and pageview tracking when available.
 */
class AnalyticsManagerStub implements AnalyticsManagerInterface
{
    use StateManagerAware;

    /** @var AnalyticsManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged */
    private static $upgradeNoticeLogged = false;

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => true,
        'stub_mode' => true,
        'site_id' => 'default',
        'node_id' => 'stub',
        'retention_days' => 90,
        'max_unique_visitors' => 10000,  // Max visitors to track per day
    ];

    /** @var array Capability vector (enhanced when StateManager available) */
    private $capabilityVector = [
        'analytics' => 0.3,
        'tracking' => 0.2,
        'metrics' => 0.2,
        'privacy' => 1.0  // Privacy-first - uses hashed identifiers only
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
            $this->capabilityVector['analytics'] = 0.5;
            $this->capabilityVector['tracking'] = 0.4;
            $this->capabilityVector['metrics'] = 0.4;
        }

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

        if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
            $mode = $this->hasStateManager() ? 'StateManager-backed' : 'no-op';
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log("[gCore] AnalyticsManager stub active ({$mode}) - the gcore-analytics extension provides full analytics"); }
        }
    }

    // =========================================================================
    // TRACKING OPERATIONS
    // =========================================================================

    /**
     * Track visit
     *
     * With StateManager: Tracks unique visitors and pageviews
     * Without: No-op
     */
    public function trackVisit(): void
    {
        if (!$this->hasStateManager()) {
            return;
        }

        // Generate visitor hash (privacy-first approach)
        $visitorHash = $this->generateVisitorHash();

        // Track unique visitor for today
        $isNew = $this->trackUniqueDaily('visitors', $visitorHash);

        if ($isNew) {
            // Increment unique visitor count for today
            $this->incrementDaily('unique_visitors', 1);
        }

        // Always increment pageview counter
        $this->incrementDaily('pageviews', 1);
    }

    /**
     * Track resource costs
     *
     * With StateManager: Aggregates bandwidth and request metrics
     * Without: No-op
     */
    public function trackResourceCosts(string $visitorHash, int $requestCount, int $bytesTransferred, bool $fromCache = false): void
    {
        if (!$this->hasStateManager()) {
            return;
        }

        // Track total requests
        $this->incrementDaily('total_requests', $requestCount);

        // Track cached vs network
        if ($fromCache) {
            $this->incrementDaily('cached_requests', $requestCount);
            $this->incrementDaily('bytes_saved', $bytesTransferred);
        } else {
            $this->incrementDaily('network_requests', $requestCount);
            $this->incrementDaily('bytes_transferred', $bytesTransferred);
        }
    }

    /**
     * Record journey step
     *
     * With StateManager: Records page path in visitor's journey
     * Without: No-op
     *
     * Note: Full journey analysis requires full version
     */
    public function recordJourneyStep(string $visitorHash, string $uri, int $timestamp): void
    {
        if (!$this->hasStateManager()) {
            return;
        }

        // Track most visited pages (simple counter)
        $pageKey = 'page_' . md5($uri);
        $this->incrementDaily($pageKey, 1);

        // Store the mapping of page key to URI
        $this->setDistributedState("page_uri_{$pageKey}", $uri, true);
    }

    // =========================================================================
    // CORE ANALYTICS QUERIES
    // =========================================================================

    /**
     * Get unique visitors
     *
     * With StateManager: Returns tracked count
     * Without: Returns 0
     */
    public function getUniqueVisitors(string $startDate, string $endDate): int
    {
        if (!$this->hasStateManager()) {
            return 0;
        }

        // Simple implementation: only returns today's count if in range
        $today = date('Ymd');
        if ($startDate <= $today && $endDate >= $today) {
            return $this->getDailyCounter('unique_visitors', $today);
        }

        return 0;
    }

    /**
     * Get pageviews
     *
     * With StateManager: Returns tracked count
     * Without: Returns 0
     */
    public function getPageviews(string $startDate, string $endDate): int
    {
        if (!$this->hasStateManager()) {
            return 0;
        }

        // Simple implementation: only returns today's count if in range
        $today = date('Ymd');
        if ($startDate <= $today && $endDate >= $today) {
            return $this->getDailyCounter('pageviews', $today);
        }

        return 0;
    }

    /**
     * Get top pages
     *
     * Note: Full top pages analysis requires full version
     */
    public function getTopPages(string $startDate, string $endDate, int $limit = 10, int $offset = 0): array
    {
        // Top pages aggregation requires full - too complex for stub
        return [];
    }

    // =========================================================================
    // SUMMARY QUERIES
    // =========================================================================

    /**
     * Get today summary
     *
     * With StateManager: Returns actual tracked data
     * Without: Returns zeros with stub indicator
     */
    public function getTodaySummary(): array
    {
        $date = date('Ymd');

        if (!$this->hasStateManager()) {
            return [
                'date' => $date,
                'unique_visitors' => 0,
                'pageviews' => 0,
                'top_pages' => [],
                'stub_mode' => true,
                'has_state_manager' => false,
                'message' => 'StateManager not available for tracking',
            ];
        }

        return [
            'date' => $date,
            'unique_visitors' => $this->getDailyCounter('unique_visitors', $date),
            'pageviews' => $this->getDailyCounter('pageviews', $date),
            'total_requests' => $this->getDailyCounter('total_requests', $date),
            'cached_requests' => $this->getDailyCounter('cached_requests', $date),
            'top_pages' => [], // Requires the matching extension
            'stub_mode' => true,
            'has_state_manager' => true,
            'message' => 'Basic tracking active. Upgrade to gcore-analytics for full features.',
        ];
    }

    /**
     * Get summary
     *
     * With StateManager: Returns today's data (multi-day requires the extension)
     * Without: Returns zeros
     */
    public function getSummary(int $days = 7): array
    {
        $endDate = date('Ymd');
        $startDate = date('Ymd', strtotime("-{$days} days"));

        if (!$this->hasStateManager()) {
            return [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
                'unique_visitors' => 0,
                'pageviews' => 0,
                'top_pages' => [],
                'stub_mode' => true,
                'message' => 'Analytics requires StateManager or the Analytics extension',
            ];
        }

        // Stub only has today's data
        $todayData = $this->getTodaySummary();

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days' => $days,
            'unique_visitors' => $todayData['unique_visitors'],
            'pageviews' => $todayData['pageviews'],
            'top_pages' => [],
            'stub_mode' => true,
            'note' => 'Multi-day retention requires the matching extension',
        ];
    }

    /**
     * Get daily stats
     */
    public function getDailyStats(string $date): array
    {
        if (!$this->hasStateManager()) {
            return [
                'date' => $date,
                'unique_visitors' => 0,
                'pageviews' => 0,
                'stub_mode' => true,
            ];
        }

        return [
            'date' => $date,
            'unique_visitors' => $this->getDailyCounter('unique_visitors', $date),
            'pageviews' => $this->getDailyCounter('pageviews', $date),
            'total_requests' => $this->getDailyCounter('total_requests', $date),
            'cached_requests' => $this->getDailyCounter('cached_requests', $date),
            'stub_mode' => true,
        ];
    }

    // =========================================================================
    // JOURNEY ANALYSIS (requires the matching extension)
    // =========================================================================

    /**
     * Get visitor journeys (full feature)
     */
    public function getVisitorJourneys(string $date, int $limit = 20, int $offset = 0): array
    {
        return [];
    }

    // =========================================================================
    // RESOURCE TRACKING
    // =========================================================================

    /**
     * Get visitor resource costs (full feature)
     */
    public function getVisitorResourceCosts(string $date): array
    {
        return [];
    }

    /**
     * Get cache efficiency
     *
     * With StateManager: Calculates from tracked metrics
     * Without: Returns zeros
     */
    public function getCacheEfficiency(string $date): array
    {
        if (!$this->hasStateManager()) {
            return [
                'date' => $date,
                'total_requests' => 0,
                'cached_requests' => 0,
                'network_requests' => 0,
                'cache_hit_rate' => 0,
                'bytes_saved' => 0,
                'bytes_transferred' => 0,
                'total_bytes' => 0,
                'bandwidth_savings_percent' => 0,
                'stub_mode' => true,
            ];
        }

        $totalRequests = $this->getDailyCounter('total_requests', $date);
        $cachedRequests = $this->getDailyCounter('cached_requests', $date);
        $networkRequests = $this->getDailyCounter('network_requests', $date);
        $bytesSaved = $this->getDailyCounter('bytes_saved', $date);
        $bytesTransferred = $this->getDailyCounter('bytes_transferred', $date);
        $totalBytes = $bytesSaved + $bytesTransferred;

        $cacheHitRate = $totalRequests > 0 ? round($cachedRequests / $totalRequests, 4) : 0;
        $bandwidthSavings = $totalBytes > 0 ? round($bytesSaved / $totalBytes * 100, 2) : 0;

        return [
            'date' => $date,
            'total_requests' => $totalRequests,
            'cached_requests' => $cachedRequests,
            'network_requests' => $networkRequests,
            'cache_hit_rate' => $cacheHitRate,
            'bytes_saved' => $bytesSaved,
            'bytes_transferred' => $bytesTransferred,
            'total_bytes' => $totalBytes,
            'bandwidth_savings_percent' => $bandwidthSavings,
            'stub_mode' => true,
        ];
    }

    /**
     * Get metric history (full feature)
     */
    public function getMetricHistory(string $metricName, int $count = 10): array
    {
        return [];
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Generate privacy-first visitor hash
     *
     * Uses IP + User-Agent + Date to create a daily-rotating hash.
     * No PII is stored, only the hash.
     */
    private function generateVisitorHash(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $date = date('Ymd');
        $salt = $this->config['site_id'];

        return hash('sha256', "{$ip}|{$ua}|{$date}|{$salt}");
    }

    // =========================================================================
    // MODULE INTERFACE
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
            'mode' => $this->hasStateManager() ? 'state_backed' : 'no_op',
            'has_state_manager' => $this->hasStateManager(),
            'site_id' => $this->config['site_id'],
            'node_id' => $this->config['node_id'],
            'retention_days' => $this->config['retention_days'],
            'today_stats' => $this->getTodaySummary(),
            'upgrade_message' => 'The gcore-analytics extension provides journey analysis, trends, and reporting',
        ];
    }

    /**
     * Get capability vector (reflects StateManager availability)
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
