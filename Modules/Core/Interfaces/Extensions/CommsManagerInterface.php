<?php
declare(strict_types=1);
/**
 * CommsManager Interface
 *
 * Contract for Geodineum-COMMS notification daemon management including:
 * - Multi-tenant site settings management
 * - Message history and statistics
 * - Channel configuration (email, Telegram, SMS)
 * - Daemon status monitoring
 *
 * Extension implementations provide:
 * - Full ValKey stream integration
 * - Multi-channel dispatch
 * - Super admin multi-site access
 * - Daemon health monitoring
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
 * Interface CommsManagerInterface
 *
 * Defines the contract for Geodineum-COMMS notification management.
 */
interface CommsManagerInterface extends ModuleInterface
{
    // =========================================================================
    // ACCESS CONTROL
    // =========================================================================

    /**
     * Get the current site's ID for multi-tenant isolation
     *
     * @return string Site ID (domain with dots replaced by underscores)
     */
    public function getCurrentSiteId(): string;

    /**
     * Check if current user is a super admin (can see all sites)
     *
     * @return bool
     */
    public function isSuperAdmin(): bool;

    /**
     * Check if user can access a specific site's data
     *
     * @param string $siteId Site identifier
     * @return bool
     */
    public function canAccessSite(string $siteId): bool;

    /**
     * Get list of sites the current user can access
     *
     * @return array Site IDs
     */
    public function getAccessibleSites(): array;

    // =========================================================================
    // SITE SETTINGS MANAGEMENT
    // =========================================================================

    /**
     * Get settings for a site (with access check)
     *
     * @param string $siteId Site identifier
     * @return array|null Settings or null if not found/unauthorized
     */
    public function getSiteSettings(string $siteId): ?array;

    /**
     * Save settings for a site (with access check)
     *
     * @param string $siteId Site identifier
     * @param array $settings Settings to save
     * @return bool Success status
     */
    public function saveSiteSettings(string $siteId, array $settings): bool;

    /**
     * Delete settings for a site (super admin only)
     *
     * @param string $siteId Site identifier
     * @return bool Success status
     */
    public function deleteSiteSettings(string $siteId): bool;

    /**
     * List all sites with comms configuration
     *
     * @return array Site IDs
     */
    public function listConfiguredSites(): array;

    /**
     * Create default settings for a site
     *
     * @param string $siteId Site identifier
     * @return array Created settings
     */
    public function createDefaultSettings(string $siteId): array;

    // =========================================================================
    // MESSAGE HISTORY
    // =========================================================================

    /**
     * Get recent messages from comms stream (with access check)
     *
     * @param string $siteId Site identifier
     * @param string $environment Environment (production, staging)
     * @param int $count Number of messages to retrieve
     * @return array Messages
     */
    public function getRecentMessages(string $siteId, string $environment = 'production', int $count = 50): array;

    /**
     * Get messages from ALL accessible sites (for super admin dashboard)
     *
     * @param string $environment Environment
     * @param int $countPerSite Messages per site
     * @return array All messages sorted by timestamp
     */
    public function getAllRecentMessages(string $environment = 'production', int $countPerSite = 20): array;

    /**
     * Get a specific message by ID (with access check)
     *
     * @param string $siteId Site identifier
     * @param string $messageId Message ID
     * @param string $environment Environment
     * @return array|null Message or null if not found
     */
    public function getMessage(string $siteId, string $messageId, string $environment = 'production'): ?array;

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get statistics for a site (with access check)
     *
     * @param string $siteId Site identifier
     * @param string $environment Environment
     * @return array Statistics
     */
    public function getStats(string $siteId, string $environment = 'production'): array;

    /**
     * Get aggregate statistics for all accessible sites (super admin)
     *
     * @param string $environment Environment
     * @return array Aggregate statistics
     */
    public function getGlobalStats(string $environment = 'production'): array;

    // =========================================================================
    // CHANNEL TESTING
    // =========================================================================

    /**
     * Test a notification channel (with access check)
     *
     * @param string $siteId Site identifier
     * @param string $channel Channel name (email, telegram, sms)
     * @return array Result ['success' => bool, 'message' => string]
     */
    public function testChannel(string $siteId, string $channel): array;

    // =========================================================================
    // DAEMON STATUS
    // =========================================================================

    /**
     * Check if the Geodineum-COMMS daemon is processing messages
     *
     * @param string $siteId Site identifier
     * @param string $environment Environment
     * @return array Daemon status ['status' => string, ...]
     */
    public function getDaemonStatus(string $siteId, string $environment = 'production'): array;

    /**
     * Shutdown the module
     */
    public function shutdown(): void;
}
