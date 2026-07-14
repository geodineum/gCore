<?php
declare(strict_types=1);
/**
 * ManifestManager Stub
 *
 * Graceful basic implementation for default tier.
 * Provides minimal PWA manifest without gNode caching or install tracking.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\ManifestManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class ManifestManagerStub
 *
 * Free-tier stub implementation of ManifestManagerInterface.
 * Provides basic manifest generation without:
 * - gNode caching (uses WordPress transients if available)
 * - PWA install tracking
 * - Advanced icon validation
 */
class ManifestManagerStub implements ManifestManagerInterface
{
    /** @var ManifestManagerStub Singleton instance */
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
        'name' => 'gCore App',
        'short_name' => 'gCore',
        'theme_color' => '#000000',
        'background_color' => '#ffffff',
        'display' => 'standalone',
        'start_url' => '/',
    ];

    /** @var array Capability vector (reduced for stub) */
    private $capabilityVector = [
        'pwa' => 0.3,
        'manifest' => 0.5,
        'cache' => 0.0,
        'icons' => 0.2,
        'service_worker' => 0.3
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
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] ManifestManager stub active - the gcore-manifest extension provides full PWA features'); }
        }
    }

    // =========================================================================
    // CORE MANIFEST OPERATIONS
    // =========================================================================

    /**
     * Get manifest data (basic)
     */
    public function getManifestData(): array
    {
        return [
            'name' => $this->config['name'] ?? 'gCore App',
            'short_name' => $this->config['short_name'] ?? 'gCore',
            'description' => $this->config['description'] ?? 'Progressive Web Application',
            'start_url' => $this->config['start_url'] ?? '/',
            'display' => $this->config['display'] ?? 'standalone',
            'background_color' => $this->config['background_color'] ?? '#ffffff',
            'theme_color' => $this->config['theme_color'] ?? '#000000',
            'icons' => [],
            'stub_mode' => true,
            'upgrade_message' => 'The gcore-manifest extension provides full PWA support',
        ];
    }

    /**
     * Handle manifest JSON request
     */
    public function getManifestJson()
    {
        $manifest = $this->getManifestData();

        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response($manifest, 200);
        }

        header('Content-Type: application/json');
        echo json_encode($manifest);
        return $manifest;
    }

    /**
     * Register REST API endpoints (basic).
     *
     * /manifest is intentionally public (`__return_true`) because PWA
     * manifests are fetched anonymously by the browser before any
     * authentication context exists.
     */
    public function registerEndpoints(): void
    {
        if (function_exists('register_rest_route')) {
            register_rest_route('gCore/v1', '/manifest', [
                'methods' => 'GET',
                'callback' => [$this, 'getManifestJson'],
                'permission_callback' => '__return_true',
            ]);
        }
    }

    // =========================================================================
    // HEADER OUTPUT
    // =========================================================================

    /**
     * Output manifest link (minimal)
     */
    public function addManifestLink(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $manifestUrl = function_exists('rest_url')
            ? rest_url('gCore/v1/manifest')
            : '/api/manifest';

        $themeColor = $this->config['theme_color'] ?? '#000000';

        echo '<link rel="manifest" href="' . esc_url($manifestUrl) . '">' . "\n";
        echo '<meta name="theme-color" content="' . esc_attr($themeColor) . '">' . "\n";
        echo "<!-- ManifestManager stub - the gcore-manifest extension provides full PWA -->\n";
    }

    /**
     * Output manifest link with service worker (minimal)
     */
    public function addManifestLinkWithServiceWorker(): void
    {
        $this->addManifestLink();
        echo $this->getServiceWorkerScript();
    }

    /**
     * Get service worker script (basic)
     */
    public function getServiceWorkerScript(): string
    {
        return <<<'JS'
<script>
// ManifestManager stub - basic service worker registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/service-worker.js')
            .then(function(r) { console.log('SW registered'); })
            .catch(function(e) { console.log('SW failed:', e); });
    });
}
</script>
JS;
    }

    // =========================================================================
    // CACHE MANAGEMENT (no-op)
    // =========================================================================

    /**
     * Invalidate cache (no-op in stub)
     */
    public function invalidateCache(): void
    {
        // Stub has no caching
    }

    // =========================================================================
    // ICON VALIDATION (minimal)
    // =========================================================================

    /**
     * Validate icon dimensions (stub returns basic result)
     */
    public function validateIconDimensions(): array
    {
        return [
            'valid' => false,
            'icons' => [],
            'missing_required' => ['192x192', '512x512'],
            'warnings' => ['ManifestManager stub - icon validation not available'],
            'stub_mode' => true,
        ];
    }

    // =========================================================================
    // PWA INSTALL TRACKING (no-op)
    // =========================================================================

    /**
     * Track install prompt (no-op in stub)
     */
    public function trackInstallPrompt(array $data): array
    {
        return [
            'tracked' => false,
            'reason' => 'stub_mode',
            'message' => 'Install tracking requires the matching extension',
        ];
    }

    /**
     * Get install prompt stats (empty in stub)
     */
    public function getInstallPromptStats(): array
    {
        return [
            'stub_mode' => true,
            'message' => 'Install stats require the matching extension',
        ];
    }

    // =========================================================================
    // CAPABILITY DISCOVERY
    // =========================================================================

    /**
     * Get capability vector (reduced for stub)
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
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
            'enabled' => $this->config['enabled'] ?? false,
            'stub_mode' => true,
            'mode' => 'stub',
            'cache_enabled' => false,
            'icons_configured' => false,
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'upgrade_message' => 'The gcore-manifest extension provides full PWA manifest management',
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
