<?php
declare(strict_types=1);
/**
 * CommsManager Stub
 *
 * Graceful no-op implementation for default tier.
 * Provides all CommsManagerInterface methods but returns empty/stub responses.
 * No actual Geodineum-COMMS daemon integration without the matching extension.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\CommsManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class CommsManagerStub
 *
 * Free-tier stub implementation of CommsManagerInterface.
 * All comms methods return empty arrays or stub status.
 */
class CommsManagerStub implements CommsManagerInterface
{
    /** @var CommsManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged */
    private static $upgradeNoticeLogged = false;

    /** @var array In-memory settings storage */
    private $settingsStorage = [];

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => false,
        'stub_mode' => true,
        'site_id' => 'default',
        'node_id' => 'stub',
    ];

    /** @var array Capability vector (minimal for stub) */
    private $capabilityVector = [
        'notifications' => 0.0,
        'email_dispatch' => 0.0,
        'telegram_dispatch' => 0.0,
        'sms_dispatch' => 0.0,
        'message_queue' => 0.0
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
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] CommsManager stub active - the gcore-comms extension provides notification dispatch'); }
        }
    }

    /**
     * Shutdown stub
     */
    public function shutdown(): void
    {
        // Nothing to clean up
    }

    // =========================================================================
    // ACCESS CONTROL (return current site only)
    // =========================================================================

    /**
     * Get current site ID
     */
    public function getCurrentSiteId(): string
    {
        if (function_exists('home_url')) {
            $domain = parse_url(home_url(), PHP_URL_HOST);
            return str_replace('.', '_', $domain);
        }

        return $this->config['site_id'] ?? 'default';
    }

    /**
     * Is super admin (stub: always false)
     */
    public function isSuperAdmin(): bool
    {
        return false;
    }

    /**
     * Can access site (stub: only current site)
     */
    public function canAccessSite(string $siteId): bool
    {
        return $siteId === $this->getCurrentSiteId();
    }

    /**
     * Get accessible sites (stub: only current site)
     */
    public function getAccessibleSites(): array
    {
        return [$this->getCurrentSiteId()];
    }

    // =========================================================================
    // SITE SETTINGS (in-memory storage)
    // =========================================================================

    /**
     * Get site settings (in-memory)
     */
    public function getSiteSettings(string $siteId): ?array
    {
        if (!$this->canAccessSite($siteId)) {
            return null;
        }

        return $this->settingsStorage[$siteId] ?? null;
    }

    /**
     * Save site settings (in-memory)
     */
    public function saveSiteSettings(string $siteId, array $settings): bool
    {
        if (!$this->canAccessSite($siteId)) {
            return false;
        }

        $settings['site_id'] = $siteId;
        $this->settingsStorage[$siteId] = $settings;

        return true;
    }

    /**
     * Delete site settings (stub: not permitted)
     */
    public function deleteSiteSettings(string $siteId): bool
    {
        // Only super admins can delete, and stub is never super admin
        return false;
    }

    /**
     * List configured sites (stub: return stored sites)
     */
    public function listConfiguredSites(): array
    {
        return array_keys($this->settingsStorage);
    }

    /**
     * Create default settings
     */
    public function createDefaultSettings(string $siteId): array
    {
        if (!$this->canAccessSite($siteId)) {
            return [];
        }

        $settings = [
            'site_id' => $siteId,
            'enabled' => false,
            'stub_mode' => true,
            'channels' => [
                'email' => ['enabled' => false, 'config' => [], 'recipients' => []],
                'telegram' => ['enabled' => false, 'config' => [], 'recipients' => []],
                'sms' => ['enabled' => false, 'config' => [], 'recipients' => []],
            ],
            'routing_rules' => [],
            'rate_limits' => [],
            'filters' => [],
            'retry' => ['max_attempts' => 3, 'base_delay_secs' => 30],
        ];

        $this->settingsStorage[$siteId] = $settings;

        return $settings;
    }

    // =========================================================================
    // MESSAGE HISTORY (all return empty)
    // =========================================================================

    /**
     * Get recent messages (stub: empty)
     */
    public function getRecentMessages(string $siteId, string $environment = 'production', int $count = 50): array
    {
        return [];
    }

    /**
     * Get all recent messages (stub: empty)
     */
    public function getAllRecentMessages(string $environment = 'production', int $countPerSite = 20): array
    {
        return [];
    }

    /**
     * Get message (stub: null)
     */
    public function getMessage(string $siteId, string $messageId, string $environment = 'production'): ?array
    {
        return null;
    }

    // =========================================================================
    // STATISTICS (all return empty)
    // =========================================================================

    /**
     * Get stats (stub: empty stats)
     */
    public function getStats(string $siteId, string $environment = 'production'): array
    {
        return [
            'site_id' => $siteId,
            'total_messages' => 0,
            'pending_dispatch' => 0,
            'by_status' => ['pending' => 0, 'sent' => 0, 'failed' => 0],
            'by_channel' => ['email' => 0, 'telegram' => 0, 'sms' => 0],
            'first_entry' => null,
            'last_entry' => null,
            'stub_mode' => true,
        ];
    }

    /**
     * Get global stats (stub: empty)
     */
    public function getGlobalStats(string $environment = 'production'): array
    {
        return [
            'sites_count' => 0,
            'total_messages' => 0,
            'pending_dispatch' => 0,
            'by_status' => ['pending' => 0, 'sent' => 0, 'failed' => 0],
            'by_channel' => ['email' => 0, 'telegram' => 0, 'sms' => 0],
            'by_site' => [],
            'stub_mode' => true,
        ];
    }

    // =========================================================================
    // CHANNEL TESTING (stub: not available)
    // =========================================================================

    /**
     * Test channel (stub: not available)
     */
    public function testChannel(string $siteId, string $channel): array
    {
        return [
            'success' => false,
            'message' => 'Channel testing requires gcore-comms full with Geodineum-COMMS daemon',
            'stub_mode' => true
        ];
    }

    // =========================================================================
    // DAEMON STATUS (stub: not available)
    // =========================================================================

    /**
     * Get daemon status (stub: not available)
     */
    public function getDaemonStatus(string $siteId, string $environment = 'production'): array
    {
        return [
            'status' => 'not_available',
            'message' => 'Geodineum-COMMS daemon requires the matching extension',
            'stub_mode' => true
        ];
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
            'sites_configured' => count($this->settingsStorage),
            'is_super_admin' => false,
            'gnode_comms_enabled' => false,
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'upgrade_message' => 'The gcore-comms extension provides multi-channel notification dispatch',
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
