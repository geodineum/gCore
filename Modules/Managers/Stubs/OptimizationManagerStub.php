<?php
declare(strict_types=1);
/**
 * OptimizationManager Stub
 *
 * Graceful no-op implementation for default tier.
 * Provides all OptimizationManagerInterface methods but performs no actual optimization.
 * Basic string-through passthrough for filters.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\OptimizationManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class OptimizationManagerStub
 *
 * Free-tier stub implementation of OptimizationManagerInterface.
 * All optimization methods are no-ops, filters return input unchanged.
 */
class OptimizationManagerStub implements OptimizationManagerInterface
{
    /** @var OptimizationManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged */
    private static $upgradeNoticeLogged = false;

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => false,
        'stub_mode' => true,
        'site_id' => 'default',
        'node_id' => 'stub',
        'defer_scripts' => false,
        'optimize_styles' => false,
        'remove_query_strings' => false,
        'optimize_database' => false,
        'excluded_scripts' => [],
        'excluded_styles' => [],
    ];

    /** @var array Capability vector (minimal for stub) */
    private $capabilityVector = [
        'optimization' => 0.0,
        'performance' => 0.0,
        'caching' => 0.0,
        'assets' => 0.0,
        'resources' => 0.0
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
        $this->initialized = true;

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
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] OptimizationManager stub active - the gcore-optimization extension provides performance features'); }
        }
    }

    // =========================================================================
    // OPTIMIZATION PHASES (all no-op)
    // =========================================================================

    public function earlyOptimizations(): void
    {
        // Stub - no-op
    }

    public function standardOptimizations(): void
    {
        // Stub - no-op
    }

    public function lateOptimizations(): void
    {
        // Stub - no-op
    }

    // =========================================================================
    // ASSET OPTIMIZATION (no-op, return defaults)
    // =========================================================================

    public function optimizeAssets(): void
    {
        // Stub - no-op
    }

    public function getExcludedScripts(): array
    {
        return $this->config['excluded_scripts'] ?? [];
    }

    public function getExcludedStyles(): array
    {
        return $this->config['excluded_styles'] ?? [];
    }

    public function excludeScript(string $handle): bool
    {
        if (!in_array($handle, $this->config['excluded_scripts'])) {
            $this->config['excluded_scripts'][] = $handle;
            return true;
        }
        return false;
    }

    public function excludeStyle(string $handle): bool
    {
        if (!in_array($handle, $this->config['excluded_styles'])) {
            $this->config['excluded_styles'][] = $handle;
            return true;
        }
        return false;
    }

    public function includeScript(string $handle): bool
    {
        $key = array_search($handle, $this->config['excluded_scripts']);
        if ($key !== false) {
            unset($this->config['excluded_scripts'][$key]);
            return true;
        }
        return false;
    }

    public function includeStyle(string $handle): bool
    {
        $key = array_search($handle, $this->config['excluded_styles']);
        if ($key !== false) {
            unset($this->config['excluded_styles'][$key]);
            return true;
        }
        return false;
    }

    // =========================================================================
    // HEADER AND RESOURCE OPTIMIZATION (passthrough/no-op)
    // =========================================================================

    public function optimizeHeaders($headers, $wp): array
    {
        // Stub - return unchanged
        return is_array($headers) ? $headers : [];
    }

    public function addResourceHints(): void
    {
        // Stub - no-op
    }

    public function removeQueryStrings($src): string
    {
        // Stub - return unchanged
        return $src;
    }

    public function optimizeQueries($where, $query): string
    {
        // Stub - return unchanged
        return $where;
    }

    // =========================================================================
    // ADVANCED OPTIMIZATIONS (all no-op or passthrough)
    // =========================================================================

    public function manageDnsPrefetch(): void
    {
        // Stub - no-op
    }

    public function optimizeFontLoading(string $html, string $handle, string $href, string $media): string
    {
        // Stub - return unchanged
        return $html;
    }

    public function optimizeImageLoading(string $content): string
    {
        // Stub - return unchanged
        return $content;
    }

    public function monitorDomSize(): void
    {
        // Stub - no-op
    }

    public function optimizeMemoryUsage(): void
    {
        // Stub - no-op
    }

    public function forceCleanup(?int $batchSize = null): array
    {
        return [
            'auto_drafts_deleted' => 0,
            'transients_deleted' => 0,
            'batch_size' => $batchSize ?? 500,
            'timestamp' => time(),
            'stub_mode' => true,
            'message' => 'Cleanup requires the matching extension'
        ];
    }

    public function optimizeWpQueries($query): void
    {
        // Stub - no-op
    }

    public function startOutputBuffer(): void
    {
        // Stub - no-op (don't actually start buffering in stub)
    }

    public function endOutputBuffer(): void
    {
        // Stub - no-op
    }

    public function optimizeHtmlOutput(string $buffer): string
    {
        // Stub - return unchanged
        return $buffer;
    }

    public function setupHttp2ServerPush(): void
    {
        // Stub - no-op
    }

    public function optimizeMediaQueries(): void
    {
        // Stub - no-op
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
            'stub_mode' => true,
            'mode' => 'stub',
            'enabled' => false,
            'defer_scripts' => false,
            'optimize_styles' => false,
            'remove_query_strings' => false,
            'optimize_database' => false,
            'metrics_available' => false,
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'framework' => defined('ABSPATH') ? 'WordPress' : 'Generic PHP',
            'upgrade_message' => 'The gcore-optimization extension provides performance optimization features',
        ];
    }

    /**
     * Get capability vector (minimal for stub)
     *
     * @return array Capability vector
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
