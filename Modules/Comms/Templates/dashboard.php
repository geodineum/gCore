<?php
declare(strict_types=1);
/**
 * Geodineum-COMMS Dashboard Template
 *
 * @var array $stats Statistics data
 * @var array $daemonStatus Daemon status
 * @var array $messages Recent messages
 * @var array|null $settings Site settings
 * @var bool $isSuperAdmin Is user a super admin
 * @var string $currentSiteId Current site ID
 * @var string $viewSiteId Site being viewed
 * @var array $accessibleSites Sites the user can access
 * @var bool $isGlobalView Is this a global view
 */

if (!defined('ABSPATH')) {
    exit;
}

$isConfigured = $settings !== null && ($settings['enabled'] ?? false);
?>
<div class="wrap gcore-comms-wrap">
    <h1>
        <?php _e('Notifications', 'gcore'); ?>
        <?php if ($isSuperAdmin): ?>
        <span class="gcore-comms-super-admin-badge"><?php _e('Super Admin', 'gcore'); ?></span>
        <?php endif; ?>
    </h1>
    <?php \gCore\Modules\Comms\Admin\CommsAdmin::getInstance()->renderTabs('messages'); ?>

    <?php if ($isSuperAdmin && count($accessibleSites) > 1): ?>
    <!-- Site Selector for Super Admin -->
    <div class="gcore-comms-site-selector">
        <form method="get" action="">
            <input type="hidden" name="page" value="gcore-comms" />
            <label for="view_site"><strong><?php _e('View Site:', 'gcore'); ?></strong></label>
            <select name="view_site" id="view_site" onchange="this.form.submit()">
                <option value="all" <?php selected($viewSiteId, 'all'); ?>>
                    <?php _e('All Sites (Global View)', 'gcore'); ?>
                </option>
                <?php foreach ($accessibleSites as $site): ?>
                <option value="<?php echo esc_attr($site); ?>" <?php selected($viewSiteId, $site); ?>>
                    <?php echo esc_html($site); ?>
                    <?php if ($site === $currentSiteId): ?>
                    (<?php _e('current', 'gcore'); ?>)
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php _e('Switch', 'gcore'); ?></button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($isGlobalView): ?>
    <!-- Global View Notice -->
    <div class="gcore-comms-global-view-notice">
        <strong><?php _e('Global View', 'gcore'); ?></strong> &mdash;
        <?php printf(
            __('Showing aggregated data across %d sites.', 'gcore'),
            count($accessibleSites)
        ); ?>
    </div>
    <?php elseif (!$isConfigured): ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php _e('Notifications are not configured.', 'gcore'); ?></strong>
            <a href="<?php echo admin_url('admin.php?page=gcore-comms-settings'); ?>">
                <?php _e('Configure notification settings', 'gcore'); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <!-- Daemon Status -->
    <div class="gcore-comms-card">
        <h2><?php _e('Geodineum-COMMS Daemon Status', 'gcore'); ?></h2>
        <p>
            <strong><?php _e('Status:', 'gcore'); ?></strong>
            <span class="gcore-comms-status <?php echo esc_attr($daemonStatus['status']); ?>">
                <?php echo esc_html(ucfirst($daemonStatus['status'])); ?>
            </span>
        </p>
        <?php if ($daemonStatus['status'] === 'active'): ?>
        <p>
            <strong><?php _e('Active Consumers:', 'gcore'); ?></strong>
            <?php echo esc_html($daemonStatus['consumers']); ?>
        </p>
        <p>
            <strong><?php _e('Pending Messages:', 'gcore'); ?></strong>
            <?php echo esc_html($daemonStatus['pending']); ?>
        </p>
        <?php elseif ($daemonStatus['status'] === 'inactive'): ?>
        <p class="description">
            <?php _e('The Geodineum-COMMS daemon is not currently processing messages. Start it with:', 'gcore'); ?>
            <code>sudo systemctl start geodineum-comms</code>
        </p>
        <?php endif; ?>
    </div>

    <!-- Statistics -->
    <div class="gcore-comms-card">
        <h2>
            <?php _e('Statistics', 'gcore'); ?>
            <?php if ($isGlobalView): ?>
            <span class="description">(<?php _e('All Sites', 'gcore'); ?>)</span>
            <?php else: ?>
            <span class="description">(<?php echo esc_html($viewSiteId); ?>)</span>
            <?php endif; ?>
        </h2>

        <?php if ($isGlobalView && isset($stats['by_site'])): ?>
        <!-- Per-Site Statistics for Global View -->
        <div class="gcore-comms-site-stats">
            <?php foreach ($stats['by_site'] as $siteId => $siteStats): ?>
            <div class="gcore-comms-site-stat-card">
                <h4><?php echo esc_html($siteId); ?></h4>
                <p>
                    <strong><?php _e('Total:', 'gcore'); ?></strong>
                    <?php echo esc_html($siteStats['total'] ?? 0); ?>
                </p>
                <p>
                    <strong><?php _e('Sent:', 'gcore'); ?></strong>
                    <?php echo esc_html($siteStats['sent'] ?? 0); ?>
                </p>
                <p>
                    <strong><?php _e('Failed:', 'gcore'); ?></strong>
                    <?php echo esc_html($siteStats['failed'] ?? 0); ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="gcore-comms-stats">
            <div class="gcore-comms-stat">
                <div class="value"><?php echo esc_html($stats['total_messages'] ?? 0); ?></div>
                <div class="label"><?php _e('Total Messages', 'gcore'); ?></div>
            </div>
            <div class="gcore-comms-stat">
                <div class="value"><?php echo esc_html($stats['by_status']['sent'] ?? 0); ?></div>
                <div class="label"><?php _e('Sent', 'gcore'); ?></div>
            </div>
            <div class="gcore-comms-stat">
                <div class="value"><?php echo esc_html($stats['by_status']['pending'] ?? 0); ?></div>
                <div class="label"><?php _e('Pending', 'gcore'); ?></div>
            </div>
            <div class="gcore-comms-stat">
                <div class="value"><?php echo esc_html($stats['by_status']['failed'] ?? 0); ?></div>
                <div class="label"><?php _e('Failed', 'gcore'); ?></div>
            </div>
        </div>

        <!-- Channel breakdown -->
        <h3><?php _e('By Channel', 'gcore'); ?></h3>
        <div class="gcore-comms-stats">
            <div class="gcore-comms-stat" style="background: linear-gradient(135deg, #0073aa, #005177);">
                <div class="value"><?php echo esc_html($stats['by_channel']['email'] ?? 0); ?></div>
                <div class="label"><?php _e('Email', 'gcore'); ?></div>
            </div>
            <div class="gcore-comms-stat" style="background: linear-gradient(135deg, #0088cc, #006699);">
                <div class="value"><?php echo esc_html($stats['by_channel']['telegram'] ?? 0); ?></div>
                <div class="label"><?php _e('Telegram', 'gcore'); ?></div>
            </div>
            <div class="gcore-comms-stat" style="background: linear-gradient(135deg, #25D366, #128C7E);">
                <div class="value"><?php echo esc_html($stats['by_channel']['sms'] ?? 0); ?></div>
                <div class="label"><?php _e('SMS', 'gcore'); ?></div>
            </div>
        </div>
    </div>

    <!-- Recent Messages -->
    <div class="gcore-comms-card">
        <h2>
            <?php _e('Recent Messages', 'gcore'); ?>
            <?php if ($isGlobalView): ?>
            <span class="description">(<?php _e('All Sites', 'gcore'); ?>)</span>
            <?php endif; ?>
        </h2>

        <?php if (empty($messages)): ?>
        <p class="description"><?php _e('No messages in the queue.', 'gcore'); ?></p>
        <?php else: ?>
        <table class="gcore-comms-messages-table">
            <thead>
                <tr>
                    <?php if ($isGlobalView): ?>
                    <th><?php _e('Site', 'gcore'); ?></th>
                    <?php endif; ?>
                    <th><?php _e('Time', 'gcore'); ?></th>
                    <th><?php _e('Type', 'gcore'); ?></th>
                    <th><?php _e('From', 'gcore'); ?></th>
                    <th><?php _e('Subject', 'gcore'); ?></th>
                    <th><?php _e('Channels', 'gcore'); ?></th>
                    <th><?php _e('Status', 'gcore'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($messages as $msg): ?>
                <tr>
                    <?php if ($isGlobalView): ?>
                    <td>
                        <span class="gcore-comms-site-badge">
                            <?php echo esc_html($msg['site_id'] ?? '-'); ?>
                        </span>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php
                        $timestamp = $msg['timestamp'] ?? $msg['received_at'] ?? '';
                        if ($timestamp) {
                            echo esc_html(date('M j, H:i', strtotime($timestamp)));
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td><?php echo esc_html($msg['type'] ?? $msg['message_type'] ?? 'unknown'); ?></td>
                    <td>
                        <?php
                        $sender = $msg['sender'] ?? [];
                        $senderName = $sender['name'] ?? $msg['sender_name'] ?? null;
                        $senderEmail = $sender['email'] ?? $msg['sender_email'] ?? null;
                        echo esc_html($senderName ?? $senderEmail ?? '-');
                        ?>
                    </td>
                    <td>
                        <?php
                        $content = $msg['content'] ?? [];
                        $subject = $content['subject'] ?? $msg['subject'] ?? '';
                        echo esc_html(mb_substr($subject, 0, 40) . (strlen($subject) > 40 ? '...' : ''));
                        ?>
                    </td>
                    <td>
                        <?php
                        $channels = $msg['dispatch']['channels'] ?? [];
                        echo esc_html(implode(', ', $channels) ?: '-');
                        ?>
                    </td>
                    <td>
                        <?php
                        $status = $msg['dispatch']['status'] ?? $msg['status'] ?? 'pending';
                        ?>
                        <span class="gcore-comms-status <?php echo esc_attr($status); ?>">
                            <?php echo esc_html(ucfirst($status)); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var isGlobalView = <?php echo json_encode($isGlobalView); ?>;
    var viewSiteId = <?php echo json_encode($viewSiteId); ?>;
    var isSuperAdmin = <?php echo json_encode($isSuperAdmin); ?>;

    // Auto-refresh stats every 30 seconds
    setInterval(function() {
        var ajaxData = {
            nonce: '<?php echo wp_create_nonce('gcore_comms_nonce'); ?>',
            environment: 'production'
        };

        if (isGlobalView && isSuperAdmin) {
            ajaxData.action = 'gcore_comms_get_global_stats';
        } else {
            ajaxData.action = 'gcore_comms_get_stats';
            ajaxData.site_id = viewSiteId;
        }

        $.post(ajaxurl, ajaxData, function(response) {
            if (response.success) {
                console.log('Stats refreshed:', response.data);
                // Could update stats dynamically here
            }
        });
    }, 30000);
});
</script>
