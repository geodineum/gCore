<?php
declare(strict_types=1);
/**
 * Geodineum-COMMS Admin Interface
 *
 * WordPress admin pages for managing Geodineum-COMMS notification settings.
 *
 * @package gCore
 * @subpackage Modules\Comms\Admin
 */

namespace gCore\Modules\Comms\Admin;

use gCore\Modules\Comms\CommsManager;

class CommsAdmin
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var CommsManager */
    private CommsManager $comms;

    /** @var string Current site ID */
    private string $siteId;

    /** @var bool Is user a super admin */
    private bool $isSuperAdmin;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize admin
     */
    private function __construct()
    {
        $this->comms = CommsManager::getInstance();

        // Determine site_id from WordPress (safe — only parses home_url())
        try {
            $this->siteId = $this->comms->getCurrentSiteId();
        } catch (\Throwable $e) {
            $this->siteId = 'unknown';
        }

        // Check super admin status (deferred until WordPress is ready)
        $this->isSuperAdmin = false;

        // Register admin hooks
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'initSuperAdminStatus']);

        // AJAX handlers
        add_action('wp_ajax_gcore_comms_save_settings', [$this, 'ajaxSaveSettings']);
        add_action('wp_ajax_gcore_comms_test_channel', [$this, 'ajaxTestChannel']);
        add_action('wp_ajax_gcore_comms_get_messages', [$this, 'ajaxGetMessages']);
        add_action('wp_ajax_gcore_comms_get_stats', [$this, 'ajaxGetStats']);
        add_action('wp_ajax_gcore_comms_get_global_stats', [$this, 'ajaxGetGlobalStats']);
        add_action('wp_ajax_gcore_comms_get_all_messages', [$this, 'ajaxGetAllMessages']);
        add_action('wp_ajax_gcore_comms_get_sites', [$this, 'ajaxGetSites']);
    }

    /**
     * Initialize super admin status after WordPress is fully loaded
     */
    public function initSuperAdminStatus(): void
    {
        try {
            $this->isSuperAdmin = $this->comms->isSuperAdmin();
        } catch (\Throwable $e) {
            $this->isSuperAdmin = false;
        }
    }

    /**
     * Render a graceful stub when COMMS is unavailable
     */
    private function renderUnavailableStub(string $title, string $reason): void
    {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($title); ?></h1>
            <div class="notice notice-warning" style="padding: 20px; margin-top: 20px;">
                <p style="display: flex; align-items: center; gap: 10px; font-size: 14px;">
                    <span class="dashicons dashicons-email-alt" style="font-size: 24px; width: 24px; height: 24px;"></span>
                    <?php echo esc_html__('Geodineum-COMMS is not currently available.', 'gcore'); ?>
                </p>
                <p><strong><?php echo esc_html__('Reason:', 'gcore'); ?></strong> <?php echo esc_html($reason); ?></p>
                <p><?php echo esc_html__('Check that Geodineum-COMMS is installed and the gnode-daemon is running. Verify that ValKey is reachable.', 'gcore'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-dashboard')); ?>" class="button"><?php echo esc_html__('Back to Dashboard', 'gcore'); ?></a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-diagnostics')); ?>" class="button"><?php echo esc_html__('Run Diagnostics', 'gcore'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Register admin menus
     *
     * Single submenu with tabs (Messages / Settings) to avoid sidebar clutter.
     */
    public function registerMenus(): void
    {
        add_submenu_page(
            'gcore-dashboard',
            __('Notifications', 'gcore'),
            __('Notifications', 'gcore'),
            'manage_options',
            'gcore-comms',
            [$this, 'renderPage']
        );
    }

    /**
     * Tab dispatcher — single entry point that renders the active tab.
     */
    public function renderPage(): void
    {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'messages';
        if ($tab === 'settings') {
            $this->renderSettings();
        } else {
            $this->renderDashboard();
        }
    }

    /**
     * Render shared tab navigation. Templates call this at the top.
     */
    public function renderTabs(string $activeTab = 'messages'): void
    {
        $base = admin_url('admin.php?page=gcore-comms');
        $tabs = [
            'messages' => __('Messages', 'gcore'),
            'settings' => __('Settings', 'gcore'),
        ];
        ?>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $label):
                $url = $slug === 'messages' ? $base : add_query_arg('tab', $slug, $base);
                $active = ($slug === $activeTab) ? ' nav-tab-active' : '';
                ?>
                <a href="<?php echo esc_url($url); ?>" class="nav-tab<?php echo $active; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </h2>
        <?php
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, 'gcore-comms')) {
            return;
        }

        // Enqueue Chart.js for statistics
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Inline styles for comms admin
        wp_add_inline_style('wp-admin', $this->getAdminStyles());
    }

    /**
     * Render the notifications dashboard
     */
    public function renderDashboard(): void
    {
        try {
            if (!$this->comms->isInitialized()) {
                $this->renderUnavailableStub(
                    __('Notifications Dashboard', 'gcore'),
                    __('CommsManager could not connect to ValKey', 'gcore')
                );
                return;
            }

            $isSuperAdmin = $this->comms->isSuperAdmin();
            $currentSiteId = $this->siteId;

            // Get view site from query param (super admin can switch sites)
            $viewSiteId = isset($_GET['view_site']) && $isSuperAdmin
                ? sanitize_text_field($_GET['view_site'])
                : $currentSiteId;

            // Check access
            if (!$this->comms->canAccessSite($viewSiteId)) {
                wp_die(__('You do not have permission to view this site.', 'gcore'));
            }

            // Get accessible sites for super admin site selector
            $accessibleSites = $this->comms->getAccessibleSites();

            if ($isSuperAdmin && $viewSiteId === 'all') {
                // Global view for super admin
                $stats = $this->comms->getGlobalStats('production');
                $daemonStatus = $this->comms->getDaemonStatus($currentSiteId);
                $messages = $this->comms->getAllRecentMessages('production', 10);
                $settings = null;
                $isGlobalView = true;
            } else {
                // Single site view
                $stats = $this->comms->getStats($viewSiteId);
                $daemonStatus = $this->comms->getDaemonStatus($viewSiteId);
                $messages = $this->comms->getRecentMessages($viewSiteId, 'production', 20);
                $settings = $this->comms->getSiteSettings($viewSiteId);
                $isGlobalView = false;
            }

            include __DIR__ . '/../Templates/dashboard.php';
        } catch (\Throwable $e) {
            error_log('[gCore CommsAdmin] dashboard render failed at ' . $e->getFile() . ':' . $e->getLine() . ': ' . $e->getMessage());
            $this->renderUnavailableStub(
                __('Notifications Dashboard', 'gcore'),
                $e->getMessage()
            );
        }
    }

    /**
     * Render the settings page
     */
    public function renderSettings(): void
    {
        try {
            if (!$this->comms->isInitialized()) {
                $this->renderUnavailableStub(
                    __('Notification Settings', 'gcore'),
                    __('CommsManager could not connect to ValKey', 'gcore')
                );
                return;
            }

            $settings = $this->comms->getSiteSettings($this->siteId);

            if (!$settings) {
                $settings = $this->comms->createDefaultSettings($this->siteId);
            }

            include __DIR__ . '/../Templates/settings.php';
        } catch (\Throwable $e) {
            error_log('[gCore CommsAdmin] settings render failed at ' . $e->getFile() . ':' . $e->getLine() . ': ' . $e->getMessage());
            $this->renderUnavailableStub(
                __('Notification Settings', 'gcore'),
                $e->getMessage()
            );
        }
    }

    /**
     * AJAX: Save settings
     */
    public function ajaxSaveSettings(): void
    {
        check_ajax_referer('gcore_comms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // GC-D2.08 (Commit 1.2.d): cap input size + decode depth before
        // json_decode. Pre-fix the only bound on $_POST['settings'] was
        // WordPress's post_max_size (typ. 8-64 MiB); a ~64 KiB crafted
        // deeply-nested JSON document forces quadratic parse work. 64 KiB
        // size + depth 32 is generous for legitimate settings payloads.
        $settingsRaw = (string) ($_POST['settings'] ?? '{}');
        if (strlen($settingsRaw) > 65536) {
            wp_send_json_error(['message' => 'Settings payload too large (max 64 KiB)']);
        }
        $settings = json_decode(stripslashes($settingsRaw), true, 32);

        if (!$settings) {
            wp_send_json_error(['message' => 'Invalid settings data']);
        }

        $siteId = sanitize_text_field($_POST['site_id'] ?? $this->siteId);

        // Enforce access control
        if (!$this->comms->canAccessSite($siteId)) {
            wp_send_json_error(['message' => 'Access denied to this site']);
        }

        if ($this->comms->saveSiteSettings($siteId, $settings)) {
            wp_send_json_success(['message' => 'Settings saved']);
        } else {
            wp_send_json_error(['message' => 'Failed to save settings']);
        }
    }

    /**
     * AJAX: Test channel
     */
    public function ajaxTestChannel(): void
    {
        check_ajax_referer('gcore_comms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $channel = sanitize_text_field($_POST['channel'] ?? '');
        $siteId = sanitize_text_field($_POST['site_id'] ?? $this->siteId);

        // Enforce access control
        if (!$this->comms->canAccessSite($siteId)) {
            wp_send_json_error(['message' => 'Access denied to this site']);
        }

        $result = $this->comms->testChannel($siteId, $channel);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Get messages
     */
    public function ajaxGetMessages(): void
    {
        check_ajax_referer('gcore_comms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $siteId = sanitize_text_field($_POST['site_id'] ?? $this->siteId);
        $environment = sanitize_text_field($_POST['environment'] ?? 'production');
        $count = (int)($_POST['count'] ?? 50);

        // Enforce access control
        if (!$this->comms->canAccessSite($siteId)) {
            wp_send_json_error(['message' => 'Access denied to this site']);
        }

        $messages = $this->comms->getRecentMessages($siteId, $environment, $count);

        wp_send_json_success(['messages' => $messages]);
    }

    /**
     * AJAX: Get stats
     */
    public function ajaxGetStats(): void
    {
        check_ajax_referer('gcore_comms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $siteId = sanitize_text_field($_POST['site_id'] ?? $this->siteId);
        $environment = sanitize_text_field($_POST['environment'] ?? 'production');

        // Enforce access control
        if (!$this->comms->canAccessSite($siteId)) {
            wp_send_json_error(['message' => 'Access denied to this site']);
        }

        $stats = $this->comms->getStats($siteId, $environment);
        $daemonStatus = $this->comms->getDaemonStatus($siteId, $environment);

        wp_send_json_success([
            'stats' => $stats,
            'daemon' => $daemonStatus,
        ]);
    }

    /**
     * AJAX: Get global stats (super admin only)
     */
    public function ajaxGetGlobalStats(): void
    {
        check_ajax_referer('gcore_comms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        if (!$this->comms->isSuperAdmin()) {
            wp_send_json_error(['message' => 'Super admin access required']);
        }

        $environment = sanitize_text_field($_POST['environment'] ?? 'production');

        $stats = $this->comms->getGlobalStats($environment);
        $daemonStatus = $this->comms->getDaemonStatus($this->siteId, $environment);

        wp_send_json_success([
            'stats' => $stats,
            'daemon' => $daemonStatus,
            'is_global' => true,
        ]);
    }

    /**
     * AJAX: Get all messages across sites (super admin only)
     */
    public function ajaxGetAllMessages(): void
    {
        check_ajax_referer('gcore_comms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        if (!$this->comms->isSuperAdmin()) {
            wp_send_json_error(['message' => 'Super admin access required']);
        }

        $environment = sanitize_text_field($_POST['environment'] ?? 'production');
        $countPerSite = (int)($_POST['count_per_site'] ?? 10);

        $messages = $this->comms->getAllRecentMessages($environment, $countPerSite);

        wp_send_json_success(['messages' => $messages, 'is_global' => true]);
    }

    /**
     * AJAX: Get accessible sites list
     */
    public function ajaxGetSites(): void
    {
        check_ajax_referer('gcore_comms_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $sites = $this->comms->getAccessibleSites();
        $isSuperAdmin = $this->comms->isSuperAdmin();

        wp_send_json_success([
            'sites' => $sites,
            'is_super_admin' => $isSuperAdmin,
            'current_site' => $this->siteId,
        ]);
    }

    /**
     * Get admin CSS styles
     */
    private function getAdminStyles(): string
    {
        return <<<CSS
.gcore-comms-wrap {
    max-width: 1200px;
}
.gcore-comms-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}
.gcore-comms-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e5e5;
}
.gcore-comms-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.gcore-comms-stat {
    background: linear-gradient(135deg, #2271b1, #135e96);
    color: #fff;
    padding: 20px;
    border-radius: 4px;
    text-align: center;
}
.gcore-comms-stat .value {
    font-size: 2em;
    font-weight: bold;
}
.gcore-comms-stat .label {
    opacity: 0.9;
}
.gcore-comms-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
}
.gcore-comms-status.active { background: #d4edda; color: #155724; }
.gcore-comms-status.inactive { background: #fff3cd; color: #856404; }
.gcore-comms-status.error { background: #f8d7da; color: #721c24; }
.gcore-comms-status.pending { background: #cce5ff; color: #004085; }
.gcore-comms-status.sent { background: #d4edda; color: #155724; }
.gcore-comms-status.failed { background: #f8d7da; color: #721c24; }
.gcore-comms-channel-card {
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 15px;
}
.gcore-comms-channel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.gcore-comms-messages-table {
    width: 100%;
    border-collapse: collapse;
}
.gcore-comms-messages-table th,
.gcore-comms-messages-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #e5e5e5;
}
.gcore-comms-messages-table th {
    background: #f9f9f9;
}
.gcore-comms-messages-table tr:hover {
    background: #f9f9f9;
}
.gcore-comms-site-selector {
    margin-bottom: 20px;
    padding: 15px;
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}
.gcore-comms-site-selector select {
    min-width: 250px;
}
.gcore-comms-super-admin-badge {
    display: inline-block;
    background: linear-gradient(135deg, #8e44ad, #9b59b6);
    color: #fff;
    padding: 3px 10px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 10px;
    vertical-align: middle;
}
.gcore-comms-global-view-notice {
    background: linear-gradient(135deg, #8e44ad, #9b59b6);
    color: #fff;
    padding: 12px 20px;
    border-radius: 4px;
    margin-bottom: 20px;
}
.gcore-comms-site-badge {
    display: inline-block;
    background: #2271b1;
    color: #fff;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 10px;
    margin-right: 5px;
}
.gcore-comms-site-stats {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 15px;
}
.gcore-comms-site-stat-card {
    background: #fff;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 15px;
    min-width: 200px;
}
.gcore-comms-site-stat-card h4 {
    margin: 0 0 10px 0;
    color: #2271b1;
}
CSS;
    }
}

// Auto-initialize if in WordPress admin
if (is_admin() && defined('ABSPATH')) {
    add_action('plugins_loaded', function() {
        CommsAdmin::getInstance();
    }, 20);
}
