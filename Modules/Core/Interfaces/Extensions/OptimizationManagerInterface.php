<?php
declare(strict_types=1);
/**
 * OptimizationManager Interface
 *
 * Contract for performance optimization including asset management, resource hints,
 * header optimization, and database cleanup.
 *
 * Extension implementations provide:
 * - Script/style optimization with exclusion lists
 * - Resource hints (preload, prefetch, preconnect)
 * - HTTP/2 Server Push
 * - Database cleanup with batched queries
 * - HTML output minification
 * - Memory usage optimization
 *
 * Default stubs provide basic no-op implementations.
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
 * Interface OptimizationManagerInterface
 *
 * Defines the contract for performance optimization operations.
 */
interface OptimizationManagerInterface extends ModuleInterface
{
    // =========================================================================
    // OPTIMIZATION PHASES (WordPress-specific hooks)
    // =========================================================================

    /**
     * Early optimization phase (priority 1)
     *
     * Disables emoji, removes query strings, disables XML-RPC.
     */
    public function earlyOptimizations(): void;

    /**
     * Standard optimization phase (priority 10)
     *
     * Adds security headers, removes WordPress version info.
     */
    public function standardOptimizations(): void;

    /**
     * Late optimization phase (priority 999)
     *
     * Database query optimization.
     */
    public function lateOptimizations(): void;

    // =========================================================================
    // ASSET OPTIMIZATION
    // =========================================================================

    /**
     * Optimize assets loading (scripts/styles)
     *
     * Defers scripts and swaps non-critical styles to print media.
     */
    public function optimizeAssets(): void;

    /**
     * Get list of scripts to exclude from optimization
     *
     * @return array Script handles to exclude from deferring
     */
    public function getExcludedScripts(): array;

    /**
     * Get list of styles to exclude from optimization
     *
     * @return array Style handles to exclude from media swap
     */
    public function getExcludedStyles(): array;

    /**
     * Add a script to the exclusion list at runtime
     *
     * @param string $handle Script handle to exclude
     * @return bool Success status
     */
    public function excludeScript(string $handle): bool;

    /**
     * Add a style to the exclusion list at runtime
     *
     * @param string $handle Style handle to exclude
     * @return bool Success status
     */
    public function excludeStyle(string $handle): bool;

    /**
     * Remove a script from the exclusion list at runtime
     *
     * @param string $handle Script handle to include
     * @return bool Success status
     */
    public function includeScript(string $handle): bool;

    /**
     * Remove a style from the exclusion list at runtime
     *
     * @param string $handle Style handle to include
     * @return bool Success status
     */
    public function includeStyle(string $handle): bool;

    // =========================================================================
    // HEADER AND RESOURCE OPTIMIZATION
    // =========================================================================

    /**
     * Optimize HTTP headers
     *
     * @param array $headers Current headers
     * @param mixed $wp WordPress object
     * @return array Modified headers
     */
    public function optimizeHeaders($headers, $wp): array;

    /**
     * Add resource hints (preload, preconnect)
     */
    public function addResourceHints(): void;

    /**
     * Remove query strings from static resources
     *
     * @param string $src Source URL
     * @return string Modified URL
     */
    public function removeQueryStrings($src): string;

    /**
     * Optimize database queries
     *
     * @param string $where WHERE clause
     * @param object $query Query object
     * @return string Modified WHERE clause
     */
    public function optimizeQueries($where, $query): string;

    // =========================================================================
    // ADVANCED OPTIMIZATIONS (from trait)
    // =========================================================================

    /**
     * Manage DNS prefetching
     */
    public function manageDnsPrefetch(): void;

    /**
     * Optimize font loading with font-display property
     *
     * @param string $html HTML tag
     * @param string $handle Style handle
     * @param string $href Stylesheet URL
     * @param string $media Media type
     * @return string Modified HTML
     */
    public function optimizeFontLoading(string $html, string $handle, string $href, string $media): string;

    /**
     * Optimize image loading with lazy loading and srcset
     *
     * @param string $content HTML content
     * @return string Modified content
     */
    public function optimizeImageLoading(string $content): string;

    /**
     * Monitor and warn about large DOM size
     */
    public function monitorDomSize(): void;

    /**
     * Optimize memory usage and clean database
     */
    public function optimizeMemoryUsage(): void;

    /**
     * Force run cleanup regardless of throttle
     *
     * @param int|null $batchSize Override batch size
     * @return array Cleanup results
     */
    public function forceCleanup(?int $batchSize = null): array;

    /**
     * Optimize WordPress queries
     *
     * @param mixed $query Query object
     */
    public function optimizeWpQueries($query): void;

    /**
     * Start output buffering for HTML optimization
     */
    public function startOutputBuffer(): void;

    /**
     * End output buffering
     */
    public function endOutputBuffer(): void;

    /**
     * Optimize HTML output (minification)
     *
     * @param string $buffer HTML buffer
     * @return string Optimized HTML
     */
    public function optimizeHtmlOutput(string $buffer): string;

    /**
     * Setup HTTP/2 Server Push headers
     */
    public function setupHttp2ServerPush(): void;

    /**
     * Optimize media queries in stylesheets
     */
    public function optimizeMediaQueries(): void;
}
