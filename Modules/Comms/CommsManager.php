<?php
declare(strict_types=1);
/**
 * Geodineum-COMMS Manager for gCore
 *
 * Provides admin dashboard and configuration management for the Geodineum-COMMS
 * notification daemon. This module reads/writes to the same ValKey keys
 * that the Rust daemon uses.
 *
 * Multi-tenant isolation:
 * - Regular users only see their own site's messages
 * - Super admins (capability: manage_gnode_comms) see all sites
 *
 * @package gCore
 * @subpackage Modules\Comms
 */

namespace gCore\Modules\Comms;

use gCore\Modules\Core\gCore;
use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\CommsManagerInterface;

class CommsManager implements CommsManagerInterface
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var gCore Core instance */
    private gCore $core;

    /** @var mixed CacheManager instance */
    private $cache;

    /** @var mixed Admin gNode-Client — owns comms contract-key I/O (config) */
    private $gnode = null;

    /** @var string Consumer group name (must match Rust daemon stream_reader.rs) */
    private const CONSUMER_GROUP = 'geodineum_comms_dispatch';

    /** @var string Capability for super admin access */
    private const SUPER_ADMIN_CAP = 'manage_gnode_comms';

    /**
     * Default channel configurations.
     *
     * Keys must match Rust ChannelConfig struct (serde):
     *   enabled: bool, config: HashMap<String, Value>, recipients: Vec<RecipientConfig>
     * RecipientConfig uses serde(flatten) on address, so {email: "..."} becomes top-level.
     */
    private const DEFAULT_CHANNELS = [
        'email' => [
            'enabled' => false,
            'config' => [
                'smtp_host' => 'localhost',
                'smtp_port' => 25,
                'smtp_tls' => false,
                'from_address' => '',
                'from_name' => 'Geodineum',
            ],
            'recipients' => [],
        ],
        'telegram' => [
            'enabled' => false,
            'config' => [
                'bot_token' => '',
                'chat_id' => '',
                'parse_mode' => 'MarkdownV2',
                'disable_notification' => false,
            ],
            'recipients' => [],
        ],
        'sms' => [
            'enabled' => false,
            'config' => [
                'provider' => 'twilio',
                'account_sid' => '',
                'auth_token' => '',
                'from_number' => '',
            ],
            'recipients' => [],
        ],
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        // Lazy-init: ensure cache is wired before any read/write method is called
        if (!self::$instance->initialized) {
            try {
                self::$instance->initialize();
            } catch (\Throwable $e) {
                // Leave uninitialized — methods will check and bail gracefully
            }
        }
        return self::$instance;
    }

    /** @var array Configuration */
    private array $config = [];

    /** @var bool Initialization state */
    private bool $initialized = false;

    /** @var array Capability vector for service discovery */
    private array $capabilityVector = [
        'notifications' => 1.0,
        'email_dispatch' => 0.9,
        'telegram_dispatch' => 0.85,
        'sms_dispatch' => 0.8,
        'message_queue' => 0.95
    ];

    /**
     * Initialize the module
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        $this->config = $config;
        $this->core = gCore::getInstance();
        try {
            // Front-end: gCore is fully initialized → use its CacheManager.
            $this->cache = $this->core->getService('CacheManager');
        } catch (\Throwable $e) {
            // wp-admin: gCore does NOT fully initialize there (it uses a
            // lightweight gNode connection — see gcore-mu/wp-hooks.php), so
            // getService() throws "Core system not initialized" and this page
            // wrongly showed "could not connect to ValKey". Stand up a
            // CacheManager wired to the SAME lightweight admin gNode client the
            // Status page uses, so the Notifications page reads its comms streams
            // without a full front-end-style gCore boot.
            $this->cache = null;
            try {
                $client = function_exists('gcore_get_admin_gnode_client')
                    ? gcore_get_admin_gnode_client() : null;
                $this->gnode = $client;
                if ($client !== null) {
                    $cm = \gCore\Modules\Managers\Base\CacheManager\CacheManager::getInstance();
                    if (!$cm->isInitialized()) {
                        $cm->initialize([
                            'site_id'      => $this->getCurrentSiteId(),
                            'gnode_client' => $client,
                            'use_gnode'    => true,
                        ]);
                    }
                    $this->cache = $cm;
                }
            } catch (\Throwable $e2) {
                $this->cache = null;
            }
        }
        // Initialized only if we actually have a usable cache/connection — keeps
        // isInitialized() an honest connectivity signal for CommsAdmin.
        $this->initialized = ($this->cache !== null);
    }

    /**
     * Get module configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update module configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if module is initialized
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Get capability vector for service discovery
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    /**
     * Shutdown the module
     */
    public function shutdown(): void
    {
        // Nothing to clean up
    }

    /**
     * Get module status
     */
    public function getStatus(): array
    {
        return [
            'initialized' => true,
            'sites_configured' => count($this->listConfiguredSites()),
            'is_super_admin' => $this->isSuperAdmin(),
        ];
    }

    // =========================================================================
    // Access Control
    // =========================================================================

    /**
     * Get the current site's ID for multi-tenant isolation
     *
     * Converts domain to site_id format (e.g., staging.example.com -> staging_example_com)
     */
    public function getCurrentSiteId(): string
    {
        // Get the site domain from WordPress
        $domain = parse_url(home_url(), PHP_URL_HOST);

        // Convert to site_id format: replace dots with underscores
        return str_replace('.', '_', $domain);
    }

    /**
     * Check if current user is a super admin (can see all sites)
     */
    public function isSuperAdmin(): bool
    {
        // Check for our custom capability
        if (current_user_can(self::SUPER_ADMIN_CAP)) {
            return true;
        }

        // Network admins on multisite are always super admins
        if (is_multisite() && is_super_admin()) {
            return true;
        }

        // Allow configuration via constant
        if (defined('GNODE_COMMS_SUPER_ADMINS')) {
            $superAdmins = GNODE_COMMS_SUPER_ADMINS;
            if (is_array($superAdmins)) {
                $currentUser = wp_get_current_user();
                return in_array($currentUser->user_login, $superAdmins, true)
                    || in_array($currentUser->user_email, $superAdmins, true);
            }
        }

        return false;
    }

    /**
     * Check if user can access a specific site's data
     */
    public function canAccessSite(string $siteId): bool
    {
        // Super admins can access any site
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Regular users can only access their own site
        return $siteId === $this->getCurrentSiteId();
    }

    /**
     * Get list of sites the current user can access
     */
    public function getAccessibleSites(): array
    {
        if ($this->isSuperAdmin()) {
            return $this->listConfiguredSites();
        }

        // Regular users only get their own site
        $currentSite = $this->getCurrentSiteId();
        $configured = $this->listConfiguredSites();

        if (in_array($currentSite, $configured, true)) {
            return [$currentSite];
        }

        // Site not configured yet, but they can still access it
        return [$currentSite];
    }

    // =========================================================================
    // Site Settings Management
    // =========================================================================

    /**
     * Get settings for a site (with access check).
     *
     * Delegates to the gNode-Client comms contract API, which owns the
     * canonical {site}:comms:config key + encoding. gCore never constructs the
     * key or picks a serialization — that ownership is what keeps a save from
     * landing on a "ghost" key the COMMS daemon never reads.
     */
    public function getSiteSettings(string $siteId): ?array
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return null;
        }

        $client = $this->gnodeClient();
        if ($client === null) {
            error_log('[gCore CommsManager] getSiteSettings: no gNode-Client for ' . $siteId);
            return null;
        }
        try {
            return $client->getCommsSettings();
        } catch (\Throwable $e) {
            error_log('[gCore CommsManager] getSiteSettings failed for ' . $siteId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Save settings for a site (with access check).
     *
     * Merges channel defaults, stamps site_id, then hands off to the contract
     * API — which writes the canonical key and signals the daemon to reload its
     * settings cache so the change takes effect without a restart.
     */
    public function saveSiteSettings(string $siteId, array $settings): bool
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return false;
        }

        // Ensure site_id is set
        $settings['site_id'] = $siteId;

        // Merge with defaults for any missing channel configs
        foreach (self::DEFAULT_CHANNELS as $channel => $defaults) {
            if (!isset($settings['channels'][$channel])) {
                $settings['channels'][$channel] = $defaults;
            }
        }

        $client = $this->gnodeClient();
        if ($client === null) {
            error_log('[gCore CommsManager] saveSiteSettings: no gNode-Client for ' . $siteId);
            return false;
        }
        try {
            return $client->saveCommsSettings($settings);
        } catch (\Throwable $e) {
            error_log('[gCore CommsManager] saveSiteSettings failed for ' . $siteId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete settings for a site (super admin only).
     */
    public function deleteSiteSettings(string $siteId): bool
    {
        // Only super admins can delete
        if (!$this->isSuperAdmin()) {
            return false;
        }

        $client = $this->gnodeClient();
        if ($client === null) {
            return false;
        }
        try {
            return $client->deleteCommsSettings();
        } catch (\Throwable $e) {
            error_log('[gCore CommsManager] deleteSiteSettings failed for ' . $siteId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The admin gNode-Client used for comms contract-key I/O. Resolved once in
     * the constructor; falls back to the global accessor if unset.
     *
     * @return mixed gNode-Client instance or null
     */
    private function gnodeClient()
    {
        if ($this->gnode !== null) {
            return $this->gnode;
        }
        if (function_exists('gcore_get_admin_gnode_client')) {
            $this->gnode = gcore_get_admin_gnode_client();
        }
        return $this->gnode;
    }

    /**
     * List all sites with comms configuration (filtered by access)
     */
    public function listConfiguredSites(): array
    {
        $keys = $this->cache->keys('*:comms:config');
        $sites = [];

        foreach ($keys as $key) {
            // Extract site_id from key like "{staging_example_com}:comms:config".
            // Braces are key-side hash-tag syntax, never part of the site_id;
            // tolerate legacy unbraced keys during migration.
            if (preg_match('/^\{?(.+?)\}?:comms:config$/', $key, $matches)) {
                $sites[] = $matches[1];
            }
        }

        return $sites;
    }

    /**
     * Create default settings for a site
     */
    public function createDefaultSettings(string $siteId): array
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return [];
        }

        $domain = str_replace('_', '.', $siteId);

        // Build defaults that match Rust SiteSettings struct (serde deserialization)
        $channels = self::DEFAULT_CHANNELS;
        $channels['email']['config']['from_address'] = "noreply@{$domain}";

        $settings = [
            'site_id' => $siteId,
            'enabled' => false, // Disabled by default until configured
            'channels' => $channels,
            'routing_rules' => [
                ['type' => 'all', 'channels' => ['email']],
            ],
            'rate_limits' => (object)[], // empty object — matches Rust HashMap default
            'filters' => [
                'spam_enabled' => false,
            ],
            'retry' => [
                'max_attempts' => 5,
                'base_delay_secs' => 30,
                'max_delay_secs' => 3600,
            ],
        ];

        $this->saveSiteSettings($siteId, $settings);

        return $settings;
    }

    // =========================================================================
    // Message History
    // =========================================================================

    /**
     * Get recent messages from comms stream (with access check)
     */
    public function getRecentMessages(string $siteId, string $environment = 'production', int $count = 50): array
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return [];
        }

        $streamKey = "{{$siteId}}:gnode:comms:{$environment}";

        // Use XREVRANGE to get most recent messages
        $redis = $this->cache->getConnection();
        if (!$redis) {
            return [];
        }

        try {
            $entries = $this->replyToArray($redis->xRevRange($streamKey, '+', '-', $count));

            $messages = [];
            foreach ($entries as $id => $fields) {
                $message = $this->parseStreamEntry((string) $id, is_array($fields) ? $fields : []);
                if ($message) {
                    // Overlay the daemon's REAL dispatch status. Stream entries
                    // are immutable, so the daemon mirrors terminal status into a
                    // per-message hash keyed by the stream id; without this every
                    // row reads the frozen "pending" from the original XADD.
                    try {
                        $st = $this->replyToArray($redis->hGetAll("{{$siteId}}:gnode:comms:status:" . $message['stream_id']));
                        if (!empty($st) && !empty($st['status'])) {
                            if (!isset($message['dispatch']) || !is_array($message['dispatch'])) {
                                $message['dispatch'] = [];
                            }
                            $message['dispatch']['status'] = (string) $st['status'];
                            if (isset($st['attempts'])) {
                                $message['dispatch']['attempts'] = (int) $st['attempts'];
                            }
                            if (!empty($st['error'])) {
                                $message['dispatch']['error'] = (string) $st['error'];
                            }
                        }
                    } catch (\Throwable $e) {
                        // status overlay is best-effort; fall back to stream value
                    }
                    $messages[] = $message;
                }
            }

            return $messages;
        } catch (\Throwable $e) {
            error_log('[gCore CommsManager] getRecentMessages failed for ' . $streamKey . ' at ' . $e->getFile() . ':' . $e->getLine() . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get messages from ALL accessible sites (for super admin dashboard)
     */
    public function getAllRecentMessages(string $environment = 'production', int $countPerSite = 20): array
    {
        if (!$this->isSuperAdmin()) {
            // Regular users get their own site only
            $currentSite = $this->getCurrentSiteId();
            return $this->getRecentMessages($currentSite, $environment, $countPerSite);
        }

        $allMessages = [];
        $sites = $this->listConfiguredSites();

        foreach ($sites as $siteId) {
            $messages = $this->getRecentMessages($siteId, $environment, $countPerSite);
            foreach ($messages as $msg) {
                $msg['_site_id'] = $siteId; // Ensure site is tagged
                $allMessages[] = $msg;
            }
        }

        // Sort by timestamp descending
        usort($allMessages, function ($a, $b) {
            $tsA = $a['timestamp'] ?? $a['stream_id'] ?? '';
            $tsB = $b['timestamp'] ?? $b['stream_id'] ?? '';
            return strcmp($tsB, $tsA);
        });

        return $allMessages;
    }

    /**
     * Get a specific message by ID (with access check)
     */
    public function getMessage(string $siteId, string $messageId, string $environment = 'production'): ?array
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return null;
        }

        $streamKey = "{{$siteId}}:gnode:comms:{$environment}";

        $redis = $this->cache->getConnection();
        if (!$redis) {
            return null;
        }

        try {
            // Search for message in stream
            $entries = $this->replyToArray($redis->xRange($streamKey, '-', '+', 1000));

            foreach ($entries as $id => $fields) {
                $fields = is_array($fields) ? $fields : [];
                $msgId = $fields['id'] ?? $id;
                if ($msgId === $messageId) {
                    return $this->parseStreamEntry((string) $id, $fields);
                }
            }

            return null;
        } catch (\Throwable $e) {
            error_log('[gCore CommsManager] getMessage failed for ' . $streamKey . ' id=' . $messageId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse a stream entry into a message array
     */
    private function parseStreamEntry(string $streamId, array $fields): ?array
    {
        // Skip stream initialization markers (both the '_init' field and the
        // plain 'init' marker the Lua stream-creation scripts seed). These lack
        // a type/sender and otherwise render as spurious "unknown / -" rows.
        if (isset($fields['_init']) || isset($fields['init'])) {
            return null;
        }

        $message = [
            'stream_id' => $streamId,
            'id' => $fields['id'] ?? $streamId,
            'type' => $fields['type'] ?? 'unknown',
            'timestamp' => $fields['timestamp'] ?? null,
            'site_id' => $fields['site_id'] ?? null,
            'priority' => (int)($fields['priority'] ?? 3),
        ];

        // Parse JSON fields
        if (isset($fields['sender'])) {
            $message['sender'] = json_decode($fields['sender'], true) ?? [];
        }
        if (isset($fields['content'])) {
            $message['content'] = json_decode($fields['content'], true) ?? [];
        }
        if (isset($fields['metadata'])) {
            $message['metadata'] = json_decode($fields['metadata'], true) ?? [];
        }
        if (isset($fields['dispatch'])) {
            $message['dispatch'] = json_decode($fields['dispatch'], true) ?? [];
        }

        // Handle simple message format
        if (isset($fields['message']) && !isset($message['content'])) {
            $message['content'] = ['body' => $fields['message']];
        }

        return $message;
    }

    // =========================================================================
    // Statistics
    // =========================================================================

    /**
     * Get statistics for a site (with access check)
     */
    public function getStats(string $siteId, string $environment = 'production'): array
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return $this->emptyStats();
        }

        $streamKey = "{{$siteId}}:gnode:comms:{$environment}";

        $redis = $this->cache->getConnection();
        if (!$redis) {
            return $this->emptyStats();
        }

        try {
            // Get stream info (phpredis 6 may hand back a stdClass — normalise)
            $info = $this->replyToArray($redis->xInfo('STREAM', $streamKey));

            // Get consumer group info
            $groups = [];
            try {
                $groups = $this->replyToArray($redis->xInfo('GROUPS', $streamKey));
            } catch (\Throwable $e) {
                // Group may not exist
            }

            $pending = 0;
            foreach ($groups as $group) {
                if (($group['name'] ?? '') === self::CONSUMER_GROUP) {
                    $pending = $group['pending'] ?? 0;
                    break;
                }
            }

            // Count messages by status (sample last 100)
            $messages = $this->getRecentMessages($siteId, $environment, 100);
            $byStatus = ['pending' => 0, 'sent' => 0, 'failed' => 0];
            $byChannel = ['email' => 0, 'telegram' => 0, 'sms' => 0];

            foreach ($messages as $msg) {
                $status = $msg['dispatch']['status'] ?? 'pending';
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

                foreach ($msg['dispatch']['channels'] ?? [] as $channel) {
                    $byChannel[$channel] = ($byChannel[$channel] ?? 0) + 1;
                }
            }

            return [
                'site_id' => $siteId,
                'total_messages' => $info['length'] ?? 0,
                'pending_dispatch' => $pending,
                'by_status' => $byStatus,
                'by_channel' => $byChannel,
                'first_entry' => $info['first-entry'][0] ?? null,
                'last_entry' => $info['last-entry'][0] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log('[gCore CommsManager] getStats failed for ' . $streamKey . ' at ' . $e->getFile() . ':' . $e->getLine() . ': ' . $e->getMessage());
            return $this->emptyStats();
        }
    }

    /**
     * Get aggregate statistics for all accessible sites (super admin)
     */
    public function getGlobalStats(string $environment = 'production'): array
    {
        $sites = $this->getAccessibleSites();

        $aggregate = [
            'sites_count' => count($sites),
            'total_messages' => 0,
            'pending_dispatch' => 0,
            'by_status' => ['pending' => 0, 'sent' => 0, 'failed' => 0],
            'by_channel' => ['email' => 0, 'telegram' => 0, 'sms' => 0],
            'by_site' => [],
        ];

        foreach ($sites as $siteId) {
            $stats = $this->getStats($siteId, $environment);

            $aggregate['total_messages'] += $stats['total_messages'] ?? 0;
            $aggregate['pending_dispatch'] += $stats['pending_dispatch'] ?? 0;

            foreach (['pending', 'sent', 'failed'] as $status) {
                $aggregate['by_status'][$status] += $stats['by_status'][$status] ?? 0;
            }

            foreach (['email', 'telegram', 'sms'] as $channel) {
                $aggregate['by_channel'][$channel] += $stats['by_channel'][$channel] ?? 0;
            }

            $aggregate['by_site'][] = [
                'site_id' => $siteId,
                'total' => $stats['total_messages'] ?? 0,
                'pending' => $stats['pending_dispatch'] ?? 0,
            ];
        }

        return $aggregate;
    }

    /**
     * Normalise a phpredis reply to a deep associative array.
     *
     * phpredis 6 (RESP3-capable) can return a stdClass for aggregate replies
     * such as xInfo where earlier versions returned associative arrays. The
     * admin surfaces index these as arrays, so a raw stdClass fatals with
     * "Cannot use object of type stdClass as array". Normalise once, here.
     */
    private function replyToArray($reply): array
    {
        $out = json_decode(json_encode($reply), true);
        return is_array($out) ? $out : [];
    }

    /**
     * Empty stats structure
     */
    private function emptyStats(): array
    {
        return [
            'total_messages' => 0,
            'pending_dispatch' => 0,
            'by_status' => ['pending' => 0, 'sent' => 0, 'failed' => 0],
            'by_channel' => ['email' => 0, 'telegram' => 0, 'sms' => 0],
            'first_entry' => null,
            'last_entry' => null,
        ];
    }

    // =========================================================================
    // Channel Testing
    // =========================================================================

    /**
     * Test a notification channel (with access check)
     *
     * Validates config, then XADDs a test message to the COMMS stream.
     * The COMMS daemon picks it up and dispatches through the specified channel.
     */
    public function testChannel(string $siteId, string $channel): array
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return ['success' => false, 'message' => 'Access denied'];
        }

        $settings = $this->getSiteSettings($siteId);

        if (!$settings) {
            return ['success' => false, 'message' => 'Site settings not found'];
        }

        $channelConfig = $settings['channels'][$channel] ?? null;
        if (!$channelConfig) {
            return ['success' => false, 'message' => 'Channel not configured'];
        }

        if (!($channelConfig['enabled'] ?? false)) {
            return ['success' => false, 'message' => 'Channel is disabled'];
        }

        // Validate configuration first
        $validation = match ($channel) {
            'email' => $this->validateEmailConfig($channelConfig['config'] ?? []),
            'telegram' => $this->validateTelegramConfig($channelConfig['config'] ?? []),
            'sms' => $this->validateSmsConfig($channelConfig['config'] ?? []),
            default => ['success' => false, 'message' => 'Unknown channel'],
        };

        if (!$validation['success']) {
            return $validation;
        }

        // XADD test message to COMMS stream for the daemon to dispatch.
        // type is NOT 'test': the daemon drops test messages outright when it
        // runs --environment production, ACKs them and logs nothing, so this
        // button reported success for mail that was never sent.
        $environment = $this->config['environment'] ?? 'production';
        $streamKey = "{{$siteId}}:gnode:comms:{$environment}";
        $testId = 'test-' . time();
        $domain = str_replace('_', '.', $siteId);

        $redis = $this->cache->getConnection();
        if (!$redis) {
            return ['success' => false, 'message' => 'Cannot connect to ValKey'];
        }

        try {
            $redis->xAdd($streamKey, '*', [
                'id' => $testId,
                'type' => 'alert',
                'site_id' => $siteId,
                // Top-level DTAP environment for the COMMS daemon's non-prod
                // gate (matches the stream env; read as a flat field).
                'environment' => $environment,
                'priority' => '3',
                'timestamp' => date('c'),
                'sender' => json_encode([
                    'name' => 'gCore Admin',
                    'email' => "noreply@{$domain}",
                ]),
                'content' => json_encode([
                    'subject' => 'Geodineum COMMS Test',
                    'body' => "Test notification from gCore admin panel.\nChannel: {$channel}\nTime: " . date('Y-m-d H:i:s'),
                ]),
                'metadata' => json_encode([
                    'source' => 'gcore-admin-test',
                    'channel_override' => $channel,
                ]),
            ]);

            return [
                'success' => true,
                'message' => $environment === 'production'
                    ? "Test message dispatched to COMMS stream ({$channel}). Check your inbox."
                    : "Test message queued ({$channel}), but {$environment} is not production — "
                        . "the daemon dry-runs it and sends nothing. Only production delivers.",
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to add test message: ' . $e->getMessage()];
        }
    }

    private function validateEmailConfig(array $config): array
    {
        // smtp_host and from_address are required; auth fields are optional (localhost relay)
        $required = ['smtp_host', 'from_address'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                return ['success' => false, 'message' => "Missing required field: {$field}"];
            }
        }
        return ['success' => true, 'message' => 'Email configuration valid'];
    }

    private function validateTelegramConfig(array $config): array
    {
        if (empty($config['bot_token'])) {
            return ['success' => false, 'message' => 'Missing bot_token'];
        }
        if (empty($config['chat_id'])) {
            return ['success' => false, 'message' => 'Missing chat_id'];
        }
        return ['success' => true, 'message' => 'Telegram configuration valid'];
    }

    private function validateSmsConfig(array $config): array
    {
        $required = ['account_sid', 'auth_token', 'from_number'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                return ['success' => false, 'message' => "Missing required field: {$field}"];
            }
        }
        return ['success' => true, 'message' => 'SMS configuration valid'];
    }

    // =========================================================================
    // Daemon Status
    // =========================================================================

    /**
     * Check if the Geodineum-COMMS daemon is processing messages
     *
     * We detect this by checking if the consumer group has active consumers
     */
    public function getDaemonStatus(string $siteId, string $environment = 'production'): array
    {
        // Access check
        if (!$this->canAccessSite($siteId)) {
            return ['status' => 'access_denied', 'message' => 'Access denied'];
        }

        $streamKey = "{{$siteId}}:gnode:comms:{$environment}";

        $redis = $this->cache->getConnection();
        if (!$redis) {
            return ['status' => 'unknown', 'message' => 'Cannot connect to ValKey'];
        }

        try {
            $groups = $this->replyToArray($redis->xInfo('GROUPS', $streamKey));

            foreach ($groups as $group) {
                if (($group['name'] ?? '') === self::CONSUMER_GROUP) {
                    $consumers = $group['consumers'] ?? 0;
                    $pending = $group['pending'] ?? 0;
                    $lastDelivered = $group['last-delivered-id'] ?? '0-0';

                    return [
                        'status' => $consumers > 0 ? 'active' : 'inactive',
                        'consumers' => $consumers,
                        'pending' => $pending,
                        'last_delivered_id' => $lastDelivered,
                    ];
                }
            }

            return ['status' => 'not_initialized', 'message' => 'Consumer group not found'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
