<?php
declare(strict_types=1);
/**
 * ManifestManager Interface
 *
 * Contract for PWA (Progressive Web App) manifest management.
 * Extension implementations provide full gNode-backed caching and install tracking.
 * Default stubs provide basic manifest generation without caching.
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
 * Interface ManifestManagerInterface
 *
 * Defines the contract for PWA manifest operations.
 * Implementations may use gNode for caching (extension) or
 * provide basic manifest generation (default).
 */
interface ManifestManagerInterface extends ModuleInterface
{
    // =========================================================================
    // CORE MANIFEST OPERATIONS
    // =========================================================================

    /**
     * Get manifest data
     *
     * @return array Manifest data array
     */
    public function getManifestData(): array;

    /**
     * Handle manifest JSON request
     *
     * @return mixed Framework-specific response (WP_REST_Response or array)
     */
    public function getManifestJson();

    /**
     * Register REST API endpoints
     */
    public function registerEndpoints(): void;

    // =========================================================================
    // HEADER OUTPUT
    // =========================================================================

    /**
     * Output manifest link in header
     */
    public function addManifestLink(): void;

    /**
     * Output manifest link and service worker script in header
     */
    public function addManifestLinkWithServiceWorker(): void;

    /**
     * Get service worker registration script
     *
     * @return string JavaScript code for service worker registration
     */
    public function getServiceWorkerScript(): string;

    // =========================================================================
    // CACHE MANAGEMENT
    // =========================================================================

    /**
     * Invalidate manifest cache
     */
    public function invalidateCache(): void;

    // =========================================================================
    // ICON VALIDATION
    // =========================================================================

    /**
     * Validate icon dimensions for PWA installability
     *
     * @return array Validation results with details
     */
    public function validateIconDimensions(): array;

    // =========================================================================
    // PWA INSTALL TRACKING
    // =========================================================================

    /**
     * Track PWA install prompt event
     *
     * @param array $data Event data (event, timestamp, userAgent, choice)
     * @return array Response with tracking status
     */
    public function trackInstallPrompt(array $data): array;

    /**
     * Get PWA install prompt statistics
     *
     * @return array Statistics (prompt_shown, installed, user_choice counts)
     */
    public function getInstallPromptStats(): array;

    // =========================================================================
    // CAPABILITY DISCOVERY
    // =========================================================================

    /**
     * Get capability vector for GeometricTopology service discovery
     *
     * @return array Capability vector with dimension scores (0.0-1.0)
     */
    public function getCapabilityVector(): array;
}
