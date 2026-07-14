<?php
declare(strict_types=1);
/**
 * AnalyticsManager Interface
 *
 * Contract for privacy-first visitor analytics.
 * Extension implementations provide full ValKey-backed analytics with
 * GDPR-compliant tracking and journey analysis.
 * Default stubs provide graceful no-op degradation.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

/**
 * Interface AnalyticsManagerInterface
 *
 * Defines the contract for privacy-first analytics operations.
 * Implementations may use ValKey for storage (extension) or
 * provide no-op stubs for graceful degradation (default).
 */
interface AnalyticsManagerInterface extends ModuleInterface
{
    // =========================================================================
    // TRACKING OPERATIONS
    // =========================================================================

    /**
     * Track a page visit (usually called automatically via hook)
     *
     * GDPR-compliant: Only tracks if consent is granted.
     */
    public function trackVisit(): void;

    /**
     * Track resource costs for a visitor
     *
     * @param string $visitorHash Hashed visitor identifier
     * @param int $requestCount Number of requests
     * @param int $bytesTransferred Bytes transferred
     * @param bool $fromCache Whether served from cache
     */
    public function trackResourceCosts(string $visitorHash, int $requestCount, int $bytesTransferred, bool $fromCache = false): void;

    /**
     * Record visitor journey step
     *
     * @param string $visitorHash Visitor identifier
     * @param string $uri Current page URI
     * @param int $timestamp Unix timestamp
     */
    public function recordJourneyStep(string $visitorHash, string $uri, int $timestamp): void;

    // =========================================================================
    // CORE ANALYTICS QUERIES
    // =========================================================================

    /**
     * Get unique visitors count for a date range
     *
     * @param string $startDate Start date (YYYYMMDD)
     * @param string $endDate End date (YYYYMMDD)
     * @return int Unique visitor count
     */
    public function getUniqueVisitors(string $startDate, string $endDate): int;

    /**
     * Get total pageviews for a date range
     *
     * @param string $startDate Start date (YYYYMMDD)
     * @param string $endDate End date (YYYYMMDD)
     * @return int Total pageview count
     */
    public function getPageviews(string $startDate, string $endDate): int;

    /**
     * Get top pages for a date range
     *
     * @param string $startDate Start date (YYYYMMDD)
     * @param string $endDate End date (YYYYMMDD)
     * @param int $limit Maximum results (default 10, max 100)
     * @param int $offset Pagination offset
     * @return array Top pages with view counts ['/' => 1234, ...]
     */
    public function getTopPages(string $startDate, string $endDate, int $limit = 10, int $offset = 0): array;

    // =========================================================================
    // SUMMARY QUERIES
    // =========================================================================

    /**
     * Get analytics summary for today
     *
     * @return array Summary data (date, unique_visitors, pageviews, top_pages)
     */
    public function getTodaySummary(): array;

    /**
     * Get analytics summary for last N days
     *
     * @param int $days Number of days
     * @return array Summary data
     */
    public function getSummary(int $days = 7): array;

    /**
     * Get daily statistics for a specific date
     *
     * @param string $date Date (YYYYMMDD)
     * @return array Daily stats
     */
    public function getDailyStats(string $date): array;

    // =========================================================================
    // JOURNEY ANALYSIS
    // =========================================================================

    /**
     * Get visitor journey patterns
     *
     * @param string $date Date (YYYYMMDD)
     * @param int $limit Maximum patterns (default 20, max 100)
     * @param int $offset Pagination offset
     * @return array Journey patterns with counts
     */
    public function getVisitorJourneys(string $date, int $limit = 20, int $offset = 0): array;

    // =========================================================================
    // RESOURCE TRACKING
    // =========================================================================

    /**
     * Get visitor resource costs for a date
     *
     * @param string $date Date (YYYYMMDD)
     * @return array Visitor costs data
     */
    public function getVisitorResourceCosts(string $date): array;

    /**
     * Get cache efficiency metrics for a date
     *
     * @param string $date Date (YYYYMMDD)
     * @return array Cache efficiency data
     */
    public function getCacheEfficiency(string $date): array;

    /**
     * Get metric history
     *
     * @param string $metricName Metric name
     * @param int $count Number of entries
     * @return array Metric history
     */
    public function getMetricHistory(string $metricName, int $count = 10): array;
}
