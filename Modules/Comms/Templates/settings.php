<?php
declare(strict_types=1);
/**
 * Geodineum-COMMS Settings Template
 *
 * @var array $settings Site notification settings
 */

if (!defined('ABSPATH')) {
    exit;
}

$siteId = $settings['site_id'] ?? '';
$enabled = $settings['enabled'] ?? false;
$channels = $settings['channels'] ?? [];
$routingRules = $settings['routing_rules'] ?? [];
$rateLimits = $settings['rate_limits'] ?? [];
$filters = $settings['filters'] ?? [];
$retry = $settings['retry'] ?? [];

// Channel configs
$emailConfig = $channels['email'] ?? [];
$telegramConfig = $channels['telegram'] ?? [];
$smsConfig = $channels['sms'] ?? [];
?>
<div class="wrap gcore-comms-wrap">
    <h1><?php _e('Notifications', 'gcore'); ?></h1>
    <?php \gCore\Modules\Comms\Admin\CommsAdmin::getInstance()->renderTabs('settings'); ?>

    <form id="gcore-comms-settings-form">
        <input type="hidden" name="site_id" value="<?php echo esc_attr($siteId); ?>">
        <?php wp_nonce_field('gcore_comms_nonce', 'gcore_comms_nonce'); ?>

        <!-- Global Settings -->
        <div class="gcore-comms-card">
            <h2><?php _e('Global Settings', 'gcore'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Notifications', 'gcore'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>>
                            <?php _e('Enable notification processing for this site', 'gcore'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When disabled, messages will queue but not be dispatched.', 'gcore'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Site ID', 'gcore'); ?></th>
                    <td>
                        <code><?php echo esc_html($siteId); ?></code>
                        <p class="description">
                            <?php _e('This identifies your site in the Geodineum-COMMS system.', 'gcore'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Email Channel -->
        <div class="gcore-comms-card gcore-comms-channel-card">
            <div class="gcore-comms-channel-header">
                <h2><?php _e('Email Channel', 'gcore'); ?></h2>
                <label class="gcore-comms-toggle">
                    <input type="checkbox" name="channels[email][enabled]" value="1"
                           <?php checked($emailConfig['enabled'] ?? false); ?>>
                    <span class="gcore-comms-toggle-slider"></span>
                </label>
            </div>

            <div class="gcore-comms-channel-content" data-channel="email">
                <p class="description" style="margin: 0 0 12px;">
                    <?php _e('Email is delivered by the Geodineum-COMMS daemon, not WordPress: your site queues the message to the comms stream, and COMMS connects to this SMTP relay to send it. "localhost:25" hands the mail to the local Postfix relay.', 'gcore'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('SMTP Host', 'gcore'); ?></th>
                        <td>
                            <input type="text" name="channels[email][config][smtp_host]"
                                   value="<?php echo esc_attr($emailConfig['config']['smtp_host'] ?? 'localhost'); ?>"
                                   class="regular-text" placeholder="localhost">
                            <p class="description">
                                <?php _e('Use "localhost" for local Postfix relay (recommended).', 'gcore'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('SMTP Port', 'gcore'); ?></th>
                        <td>
                            <input type="number" name="channels[email][config][smtp_port]"
                                   value="<?php echo esc_attr($emailConfig['config']['smtp_port'] ?? 25); ?>"
                                   class="small-text">
                            <p class="description">
                                <?php _e('Common ports: 25 (local relay), 587 (TLS), 465 (SSL)', 'gcore'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('TLS', 'gcore'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="channels[email][config][smtp_tls]" value="1"
                                       <?php checked($emailConfig['config']['smtp_tls'] ?? false); ?>>
                                <?php _e('Enable TLS encryption (not needed for localhost relay)', 'gcore'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('From Address', 'gcore'); ?></th>
                        <td>
                            <input type="email" name="channels[email][config][from_address]"
                                   value="<?php echo esc_attr($emailConfig['config']['from_address'] ?? ''); ?>"
                                   class="regular-text" placeholder="noreply@example.com">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('From Name', 'gcore'); ?></th>
                        <td>
                            <input type="text" name="channels[email][config][from_name]"
                                   value="<?php echo esc_attr($emailConfig['config']['from_name'] ?? 'Geodineum'); ?>"
                                   class="regular-text" placeholder="Geodineum">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Recipients', 'gcore'); ?></th>
                        <td>
                            <?php
                            // Recipients are RecipientConfig objects: {email: "...", types: [...], min_priority: N}
                            $recipientEmails = [];
                            foreach ($emailConfig['recipients'] ?? [] as $r) {
                                if (is_array($r)) {
                                    $recipientEmails[] = $r['email'] ?? '';
                                } else {
                                    $recipientEmails[] = $r; // backwards compat: plain string
                                }
                            }
                            ?>
                            <textarea name="channels[email][recipients]" rows="3" class="large-text"
                                      placeholder="admin@example.com&#10;support@example.com"><?php
                                echo esc_textarea(implode("\n", array_filter($recipientEmails)));
                            ?></textarea>
                            <p class="description">
                                <?php _e('One email address per line. These receive all notifications for this channel.', 'gcore'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button gcore-comms-test-channel" data-channel="email">
                        <?php _e('Test Email Channel', 'gcore'); ?>
                    </button>
                    <span class="gcore-comms-test-result"></span>
                </p>
            </div>
        </div>

        <!-- Telegram Channel -->
        <div class="gcore-comms-card gcore-comms-channel-card">
            <div class="gcore-comms-channel-header">
                <h2><?php _e('Telegram Channel', 'gcore'); ?></h2>
                <label class="gcore-comms-toggle">
                    <input type="checkbox" name="channels[telegram][enabled]" value="1"
                           <?php checked($telegramConfig['enabled'] ?? false); ?>>
                    <span class="gcore-comms-toggle-slider"></span>
                </label>
            </div>

            <div class="gcore-comms-channel-content" data-channel="telegram">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Bot Token', 'gcore'); ?></th>
                        <td>
                            <input type="password" name="channels[telegram][config][bot_token]"
                                   value="<?php echo esc_attr($telegramConfig['config']['bot_token'] ?? ''); ?>"
                                   class="regular-text" autocomplete="new-password"
                                   placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                            <p class="description">
                                <?php _e('Get this from @BotFather on Telegram.', 'gcore'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Chat ID', 'gcore'); ?></th>
                        <td>
                            <input type="text" name="channels[telegram][config][chat_id]"
                                   value="<?php echo esc_attr($telegramConfig['config']['chat_id'] ?? ''); ?>"
                                   class="regular-text" placeholder="-1001234567890">
                            <p class="description">
                                <?php _e('Group/channel ID. Use @getidsbot to find it.', 'gcore'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Parse Mode', 'gcore'); ?></th>
                        <td>
                            <select name="channels[telegram][config][parse_mode]">
                                <option value="MarkdownV2" <?php selected($telegramConfig['config']['parse_mode'] ?? 'MarkdownV2', 'MarkdownV2'); ?>>
                                    MarkdownV2
                                </option>
                                <option value="HTML" <?php selected($telegramConfig['config']['parse_mode'] ?? '', 'HTML'); ?>>
                                    HTML
                                </option>
                                <option value="" <?php selected($telegramConfig['config']['parse_mode'] ?? '', ''); ?>>
                                    <?php _e('Plain Text', 'gcore'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Silent Notifications', 'gcore'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="channels[telegram][config][disable_notification]" value="1"
                                       <?php checked($telegramConfig['config']['disable_notification'] ?? false); ?>>
                                <?php _e('Send notifications silently (no sound)', 'gcore'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button gcore-comms-test-channel" data-channel="telegram">
                        <?php _e('Test Telegram Channel', 'gcore'); ?>
                    </button>
                    <span class="gcore-comms-test-result"></span>
                </p>
            </div>
        </div>

        <!-- SMS Channel -->
        <div class="gcore-comms-card gcore-comms-channel-card">
            <div class="gcore-comms-channel-header">
                <h2><?php _e('SMS Channel', 'gcore'); ?></h2>
                <label class="gcore-comms-toggle">
                    <input type="checkbox" name="channels[sms][enabled]" value="1"
                           <?php checked($smsConfig['enabled'] ?? false); ?>>
                    <span class="gcore-comms-toggle-slider"></span>
                </label>
            </div>

            <div class="gcore-comms-channel-content" data-channel="sms">
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Provider', 'gcore'); ?></th>
                        <td>
                            <select name="channels[sms][config][provider]">
                                <option value="twilio" <?php selected($smsConfig['config']['provider'] ?? 'twilio', 'twilio'); ?>>
                                    Twilio
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Account SID', 'gcore'); ?></th>
                        <td>
                            <input type="text" name="channels[sms][config][account_sid]"
                                   value="<?php echo esc_attr($smsConfig['config']['account_sid'] ?? ''); ?>"
                                   class="regular-text" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Auth Token', 'gcore'); ?></th>
                        <td>
                            <input type="password" name="channels[sms][config][auth_token]"
                                   value="<?php echo esc_attr($smsConfig['config']['auth_token'] ?? ''); ?>"
                                   class="regular-text" autocomplete="new-password">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('From Number', 'gcore'); ?></th>
                        <td>
                            <input type="tel" name="channels[sms][config][from_number]"
                                   value="<?php echo esc_attr($smsConfig['config']['from_number'] ?? ''); ?>"
                                   class="regular-text" placeholder="+15551234567">
                            <p class="description">
                                <?php _e('Your Twilio phone number in E.164 format.', 'gcore'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Recipients', 'gcore'); ?></th>
                        <td>
                            <textarea name="channels[sms][recipients]" rows="3" class="large-text"
                                      placeholder="+15551234567&#10;+15559876543"><?php
                                echo esc_textarea(implode("\n", $smsConfig['recipients'] ?? []));
                            ?></textarea>
                            <p class="description">
                                <?php _e('One phone number per line in E.164 format (+country code).', 'gcore'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="button" class="button gcore-comms-test-channel" data-channel="sms">
                        <?php _e('Test SMS Channel', 'gcore'); ?>
                    </button>
                    <span class="gcore-comms-test-result"></span>
                </p>
            </div>
        </div>

        <!-- Routing Rules -->
        <div class="gcore-comms-card">
            <h2><?php _e('Routing Rules', 'gcore'); ?></h2>
            <p class="description">
                <?php _e('Configure which channels receive notifications based on message type.', 'gcore'); ?>
            </p>

            <table class="widefat gcore-comms-routing-table">
                <thead>
                    <tr>
                        <th><?php _e('Message Type', 'gcore'); ?></th>
                        <th><?php _e('Email', 'gcore'); ?></th>
                        <th><?php _e('Telegram', 'gcore'); ?></th>
                        <th><?php _e('SMS', 'gcore'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $messageTypes = ['contact', 'contact-form', 'alert', 'error', 'system', 'test'];
                    $existingRules = [];
                    foreach ($routingRules as $rule) {
                        $existingRules[$rule['type']] = $rule['channels'] ?? [];
                    }

                    foreach ($messageTypes as $type):
                        $typeChannels = $existingRules[$type] ?? [];
                    ?>
                    <tr>
                        <td><code><?php echo esc_html($type); ?></code></td>
                        <td>
                            <input type="checkbox" name="routing_rules[<?php echo esc_attr($type); ?>][]"
                                   value="email" <?php checked(in_array('email', $typeChannels)); ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="routing_rules[<?php echo esc_attr($type); ?>][]"
                                   value="telegram" <?php checked(in_array('telegram', $typeChannels)); ?>>
                        </td>
                        <td>
                            <input type="checkbox" name="routing_rules[<?php echo esc_attr($type); ?>][]"
                                   value="sms" <?php checked(in_array('sms', $typeChannels)); ?>>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Rate Limits -->
        <div class="gcore-comms-card">
            <h2><?php _e('Rate Limits', 'gcore'); ?></h2>
            <p class="description">
                <?php _e('Prevent notification flooding by limiting messages per time window.', 'gcore'); ?>
            </p>

            <table class="form-table">
                <?php foreach (['email', 'telegram', 'sms'] as $channel):
                    $limit = $rateLimits[$channel] ?? [];
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html(ucfirst($channel)); ?></th>
                    <td>
                        <input type="number" name="rate_limits[<?php echo esc_attr($channel); ?>][max_requests]"
                               value="<?php echo esc_attr($limit['max_requests'] ?? 100); ?>"
                               class="small-text" min="1">
                        <?php _e('messages per', 'gcore'); ?>
                        <input type="number" name="rate_limits[<?php echo esc_attr($channel); ?>][window_secs]"
                               value="<?php echo esc_attr($limit['window_secs'] ?? 3600); ?>"
                               class="small-text" min="60">
                        <?php _e('seconds', 'gcore'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- Spam Filter -->
        <div class="gcore-comms-card">
            <h2><?php _e('Spam Filter', 'gcore'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Spam Filter', 'gcore'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="filters[spam_enabled]" value="1"
                                   <?php checked($filters['spam_enabled'] ?? false); ?>>
                            <?php _e('Filter suspected spam messages', 'gcore'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Spam Action', 'gcore'); ?></th>
                    <td>
                        <select name="filters[spam_action]">
                            <option value="flag" <?php selected($filters['spam_action'] ?? 'flag', 'flag'); ?>>
                                <?php _e('Flag for review', 'gcore'); ?>
                            </option>
                            <option value="quarantine" <?php selected($filters['spam_action'] ?? '', 'quarantine'); ?>>
                                <?php _e('Quarantine (do not dispatch)', 'gcore'); ?>
                            </option>
                            <option value="drop" <?php selected($filters['spam_action'] ?? '', 'drop'); ?>>
                                <?php _e('Drop silently', 'gcore'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Keyword Blocklist', 'gcore'); ?></th>
                    <td>
                        <textarea name="filters[keywords_blocklist]" rows="3" class="large-text"
                                  placeholder="viagra&#10;casino&#10;crypto"><?php
                            echo esc_textarea(implode("\n", $filters['keywords_blocklist'] ?? []));
                        ?></textarea>
                        <p class="description">
                            <?php _e('One keyword per line. Messages containing these words will be filtered.', 'gcore'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('IP Blocklist', 'gcore'); ?></th>
                    <td>
                        <textarea name="filters[ip_blocklist]" rows="3" class="large-text"
                                  placeholder="192.168.1.100&#10;10.0.0.0/8"><?php
                            echo esc_textarea(implode("\n", $filters['ip_blocklist'] ?? []));
                        ?></textarea>
                        <p class="description">
                            <?php _e('One IP or CIDR range per line.', 'gcore'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Retry Settings -->
        <div class="gcore-comms-card">
            <h2><?php _e('Retry Settings', 'gcore'); ?></h2>
            <p class="description">
                <?php _e('Configure how failed notifications are retried.', 'gcore'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Max Attempts', 'gcore'); ?></th>
                    <td>
                        <input type="number" name="retry[max_attempts]"
                               value="<?php echo esc_attr($retry['max_attempts'] ?? 5); ?>"
                               class="small-text" min="1" max="10">
                        <p class="description">
                            <?php _e('Maximum number of delivery attempts before marking as failed.', 'gcore'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Base Delay', 'gcore'); ?></th>
                    <td>
                        <input type="number" name="retry[base_delay_secs]"
                               value="<?php echo esc_attr($retry['base_delay_secs'] ?? 30); ?>"
                               class="small-text" min="5">
                        <?php _e('seconds', 'gcore'); ?>
                        <p class="description">
                            <?php _e('Initial delay before first retry. Uses exponential backoff.', 'gcore'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Max Delay', 'gcore'); ?></th>
                    <td>
                        <input type="number" name="retry[max_delay_secs]"
                               value="<?php echo esc_attr($retry['max_delay_secs'] ?? 3600); ?>"
                               class="small-text" min="60">
                        <?php _e('seconds', 'gcore'); ?>
                        <p class="description">
                            <?php _e('Maximum delay between retries (caps exponential backoff).', 'gcore'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <p class="submit gcore-comms-autosave-bar">
            <span class="gcore-comms-save-status" data-state="idle">
                <span class="dashicons dashicons-yes-alt"></span>
                <span class="gcore-comms-save-text"><?php _e('Changes save automatically', 'gcore'); ?></span>
            </span>
            <button type="button" class="button gcore-comms-save-now" hidden>
                <?php _e('Retry save', 'gcore'); ?>
            </button>
        </p>
    </form>
</div>

<div class="gcore-comms-modal-overlay" id="gcore-comms-confirm" hidden>
    <div class="gcore-comms-modal" role="dialog" aria-modal="true" aria-labelledby="gcore-comms-modal-title">
        <h2 id="gcore-comms-modal-title"></h2>
        <p class="gcore-comms-modal-body"></p>
        <p class="gcore-comms-modal-actions">
            <button type="button" class="button gcore-comms-modal-cancel"><?php _e('Cancel', 'gcore'); ?></button>
            <button type="button" class="button button-primary gcore-comms-modal-confirm"></button>
        </p>
    </div>
</div>

<style>
.gcore-comms-toggle {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}
.gcore-comms-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.gcore-comms-toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 24px;
}
.gcore-comms-toggle-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
.gcore-comms-toggle input:checked + .gcore-comms-toggle-slider {
    background-color: #2271b1;
}
.gcore-comms-toggle input:checked + .gcore-comms-toggle-slider:before {
    transform: translateX(26px);
}
.gcore-comms-routing-table {
    margin-top: 15px;
}
.gcore-comms-routing-table th,
.gcore-comms-routing-table td {
    text-align: center;
    padding: 10px;
}
.gcore-comms-routing-table td:first-child {
    text-align: left;
}
.gcore-comms-test-result {
    margin-left: 10px;
}
.gcore-comms-test-result.success {
    color: #00a32a;
}
.gcore-comms-test-result.error {
    color: #d63638;
}
/* Autosave status bar */
.gcore-comms-autosave-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    position: sticky;
    bottom: 0;
    background: #fff;
    padding: 12px 0;
    border-top: 1px solid #dcdcde;
    z-index: 5;
}
.gcore-comms-save-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}
.gcore-comms-save-status .dashicons { font-size: 18px; width: 18px; height: 18px; }
.gcore-comms-save-status[data-state="idle"]   { color: #646970; }
.gcore-comms-save-status[data-state="saved"]  { color: #00a32a; }
.gcore-comms-save-status[data-state="saving"],
.gcore-comms-save-status[data-state="pending"]{ color: #2271b1; }
.gcore-comms-save-status[data-state="error"]  { color: #d63638; }
.gcore-comms-save-status[data-state="invalid"]{ color: #b26200; }
.gcore-comms-save-status[data-state="saving"] .dashicons,
.gcore-comms-save-status[data-state="pending"] .dashicons {
    animation: gcore-comms-spin 1.2s linear infinite;
}
@keyframes gcore-comms-spin { 100% { transform: rotate(360deg); } }
/* Confirmation modal */
.gcore-comms-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}
/* A class rule would otherwise beat the UA [hidden] display:none on source order. */
.gcore-comms-modal-overlay[hidden] { display: none; }
.gcore-comms-modal {
    background: #fff;
    border-radius: 4px;
    max-width: 460px;
    width: calc(100% - 40px);
    padding: 20px 24px;
    box-shadow: 0 6px 32px rgba(0,0,0,0.3);
}
.gcore-comms-modal h2 { margin-top: 0; }
.gcore-comms-modal-body { color: #3c434a; }
.gcore-comms-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-bottom: 0;
}
.gcore-comms-modal.is-danger .gcore-comms-modal-confirm {
    background: #d63638;
    border-color: #d63638;
}
.gcore-comms-modal.is-danger .gcore-comms-modal-confirm:hover {
    background: #b32d2e;
    border-color: #b32d2e;
}
</style>

<script>
jQuery(document).ready(function($) {
    // ---- Autosave engine ----
    var $form = $('#gcore-comms-settings-form');
    var $status = $('.gcore-comms-save-status');
    var $statusText = $status.find('.gcore-comms-save-text');
    var $retryBtn = $('.gcore-comms-save-now');
    var autosaveTimer = null, inFlight = false, pendingAgain = false;

    var STATUS = {
        idle:    { icon: 'yes-alt', text: 'Changes save automatically' },
        pending: { icon: 'update',  text: 'Saving…' },
        saving:  { icon: 'update',  text: 'Saving…' },
        saved:   { icon: 'yes-alt', text: 'All changes saved' },
        error:   { icon: 'warning', text: 'Save failed' },
        invalid: { icon: 'info',    text: '' }
    };

    function setStatus(state, msg) {
        var s = STATUS[state] || STATUS.idle;
        $status.attr('data-state', state);
        $status.find('.dashicons').attr('class', 'dashicons dashicons-' + s.icon);
        $statusText.text(msg || s.text);
        $retryBtn.prop('hidden', state !== 'error');
    }

    function ucfirst(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }
    function fieldVal(name) { return ($form.find('[name="' + name + '"]').val() || '').trim(); }
    function recipientsFor(ch) {
        return ($form.find('[name="channels[' + ch + '][recipients]"]').val() || '')
            .split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    }

    // Required-field guard for ENABLING a channel — a channel must never be
    // switched on without the config it needs to dispatch.
    function enableBlockReason(ch) {
        if (ch === 'email') {
            if (!fieldVal('channels[email][config][smtp_host]')) return 'Set an SMTP host before enabling email.';
            if (!fieldVal('channels[email][config][from_address]')) return 'Set a From address before enabling email.';
            if (recipientsFor('email').length === 0) return 'Add at least one recipient before enabling email.';
        } else if (ch === 'telegram') {
            if (!fieldVal('channels[telegram][config][bot_token]')) return 'Set a bot token before enabling Telegram.';
            if (!fieldVal('channels[telegram][config][chat_id]')) return 'Set a chat ID before enabling Telegram.';
        } else if (ch === 'sms') {
            if (!fieldVal('channels[sms][config][account_sid]')) return 'Set an account SID before enabling SMS.';
            if (!fieldVal('channels[sms][config][auth_token]')) return 'Set an auth token before enabling SMS.';
            if (!fieldVal('channels[sms][config][from_number]')) return 'Set a From number before enabling SMS.';
            if (recipientsFor('sms').length === 0) return 'Add at least one recipient before enabling SMS.';
        }
        return null;
    }

    // Whole-form save (single {site}:comms:config blob); the server also fires
    // the daemon reload signal so the change takes effect without a restart.
    function save() {
        if (inFlight) { pendingAgain = true; return; }
        inFlight = true;
        setStatus('saving');
        $.post(ajaxurl, {
            action: 'gcore_comms_save_settings',
            nonce: $('#gcore_comms_nonce').val(),
            site_id: $('input[name="site_id"]').val(),
            settings: JSON.stringify(buildSettingsObject($form.serializeArray()))
        }, function (response) {
            inFlight = false;
            if (response && response.success) {
                setStatus('saved');
            } else {
                setStatus('error', 'Save failed: ' + ((response && response.data && response.data.message) || 'unknown error'));
            }
            if (pendingAgain) { pendingAgain = false; save(); }
        }).fail(function () {
            inFlight = false;
            setStatus('error', 'Network error — not saved.');
        });
    }

    function scheduleSave(delay) {
        setStatus('pending');
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(save, delay == null ? 900 : delay);
    }
    function flushSave() { clearTimeout(autosaveTimer); save(); }

    // ---- Confirmation modal (promise-style) ----
    var $modal = $('#gcore-comms-confirm');
    var modalResolve = null;
    function confirmAction(opts) {
        $('#gcore-comms-modal-title').text(opts.title);
        $modal.find('.gcore-comms-modal-body').text(opts.body);
        $modal.find('.gcore-comms-modal-confirm').text(opts.confirmLabel || 'Confirm');
        $modal.find('.gcore-comms-modal').toggleClass('is-danger', !!opts.danger);
        $modal.prop('hidden', false);
        return new Promise(function (resolve) { modalResolve = resolve; });
    }
    function closeModal(result) {
        $modal.prop('hidden', true);
        if (modalResolve) { var r = modalResolve; modalResolve = null; r(result); }
    }
    $modal.find('.gcore-comms-modal-confirm').on('click', function () { closeModal(true); });
    $modal.find('.gcore-comms-modal-cancel').on('click', function () { closeModal(false); });
    $modal.on('click', function (e) { if (e.target === this) closeModal(false); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$modal.prop('hidden')) closeModal(false); });

    // ---- Wire interactions ----

    // Text-like inputs: debounce while typing, flush on blur.
    var textSel = 'input[type=text], input[type=email], input[type=tel], input[type=number], input[type=password], textarea';
    $form.on('input', textSel, function () { scheduleSave(900); });
    $form.on('focusin', 'textarea[name$="[recipients]"]', function () { $(this).data('prev', this.value); });
    $form.on('focusout', textSel, function () {
        // Recipients emptied on an enabled channel = subtractive → confirm.
        var m = this.name && this.name.match(/^channels\[(\w+)\]\[recipients\]$/);
        if (m && this.value.trim() === '' &&
            $form.find('[name="channels[' + m[1] + '][enabled]"]').is(':checked')) {
            var $ta = $(this), prev = $ta.data('prev') || '', ch = m[1];
            confirmAction({
                title: 'Remove all recipients?',
                body: ucfirst(ch) + ' is enabled. With no recipients its messages have nowhere to go.',
                confirmLabel: 'Remove recipients', danger: true
            }).then(function (ok) { if (ok) { flushSave(); } else { $ta.val(prev); } });
            return;
        }
        flushSave();
    });

    // Discrete controls (selects, checkboxes): save immediately, with guards
    // on the enable/disable switches.
    $form.on('change', 'select, input[type=checkbox]', function () {
        var name = this.name || '';
        var chMatch = name.match(/^channels\[(\w+)\]\[enabled\]$/);
        var isGlobal = (name === 'enabled');
        var el = this;

        if (chMatch || isGlobal) {
            if (el.checked) { // turning ON
                if (chMatch) {
                    var reason = enableBlockReason(chMatch[1]);
                    if (reason) { el.checked = false; setStatus('invalid', reason); return; }
                }
                flushSave();
                return;
            }
            // turning OFF an enabled switch = subtractive → confirm
            var noun = isGlobal ? 'notifications' : ucfirst(chMatch[1]);
            var scope = isGlobal ? 'all notifications for this site' : ucfirst(chMatch[1]) + ' notifications';
            confirmAction({
                title: 'Turn off ' + noun + '?',
                body: 'This stops ' + scope + ' until re-enabled. Queued messages will not be delivered.',
                confirmLabel: 'Turn off', danger: true
            }).then(function (ok) { if (ok) { flushSave(); } else { el.checked = true; } });
            return;
        }
        flushSave();
    });

    // Enter key / retry → flush.
    $form.on('submit', function (e) { e.preventDefault(); flushSave(); });
    $retryBtn.on('click', flushSave);

    // Test channel buttons
    $('.gcore-comms-test-channel').on('click', function() {
        var $btn = $(this);
        var channel = $btn.data('channel');
        var $result = $btn.siblings('.gcore-comms-test-result');

        $btn.prop('disabled', true);
        $result.text('Testing...').removeClass('success error');

        $.post(ajaxurl, {
            action: 'gcore_comms_test_channel',
            nonce: $('#gcore_comms_nonce').val(),
            site_id: $('input[name="site_id"]').val(),
            channel: channel
        }, function(response) {
            $btn.prop('disabled', false);

            if (response.success) {
                $result.text('Success: ' + response.data.message).addClass('success');
            } else {
                $result.text('Error: ' + (response.data?.message || 'Test failed')).addClass('error');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $result.text('Network error').addClass('error');
        });
    });

    // Build nested settings object matching Rust SiteSettings struct
    function buildSettingsObject(formData) {
        var settings = {
            site_id: $('input[name="site_id"]').val(),
            enabled: false,
            channels: {
                email: { enabled: false, config: {}, recipients: [] },
                telegram: { enabled: false, config: {}, recipients: [] },
                sms: { enabled: false, config: {}, recipients: [] }
            },
            routing_rules: [],
            rate_limits: {},
            filters: {
                spam_enabled: false
            },
            retry: {}
        };

        var routingTemp = {};

        formData.forEach(function(item) {
            var name = item.name;
            var value = item.value;

            if (name === 'enabled') {
                settings.enabled = true;
            } else if (name.match(/^channels\[(\w+)\]\[enabled\]$/)) {
                var ch = name.match(/^channels\[(\w+)\]/)[1];
                settings.channels[ch].enabled = true;
            } else if (name.match(/^channels\[(\w+)\]\[config\]\[(\w+)\]$/)) {
                var matches = name.match(/^channels\[(\w+)\]\[config\]\[(\w+)\]$/);
                var ch = matches[1];
                var key = matches[2];
                // Coerce numeric values (smtp_port)
                if (key === 'smtp_port') {
                    settings.channels[ch].config[key] = parseInt(value, 10);
                // Coerce boolean checkbox values (smtp_tls, disable_notification)
                } else if (key === 'smtp_tls' || key === 'disable_notification') {
                    settings.channels[ch].config[key] = true;
                } else {
                    settings.channels[ch].config[key] = value;
                }
            } else if (name.match(/^channels\[(\w+)\]\[recipients\]$/)) {
                var ch = name.match(/^channels\[(\w+)\]/)[1];
                var emails = value.split('\n').map(s => s.trim()).filter(s => s);
                // Build Rust RecipientConfig objects: {email: "...", types: ["all"], min_priority: 5}
                if (ch === 'email') {
                    settings.channels[ch].recipients = emails.map(function(e) {
                        return {email: e, types: ['all'], min_priority: 5};
                    });
                } else if (ch === 'sms') {
                    settings.channels[ch].recipients = emails.map(function(e) {
                        return {phone: e, types: ['all'], min_priority: 5};
                    });
                } else {
                    settings.channels[ch].recipients = emails.map(function(e) {
                        return {id: e, types: ['all'], min_priority: 5};
                    });
                }
            } else if (name.match(/^routing_rules\[([^\]]+)\]\[\]$/)) {
                var type = name.match(/^routing_rules\[([^\]]+)\]/)[1];
                if (!routingTemp[type]) routingTemp[type] = [];
                routingTemp[type].push(value);
            } else if (name.match(/^rate_limits\[(\w+)\]\[(\w+)\]$/)) {
                var matches = name.match(/^rate_limits\[(\w+)\]\[(\w+)\]$/);
                var ch = matches[1];
                var key = matches[2];
                if (!settings.rate_limits[ch]) settings.rate_limits[ch] = {};
                settings.rate_limits[ch][key] = parseInt(value, 10);
            } else if (name === 'filters[spam_enabled]') {
                settings.filters.spam_enabled = true;
            } else if (name === 'filters[spam_action]') {
                settings.filters.spam_action = value;
            } else if (name === 'filters[keywords_blocklist]') {
                settings.filters.keywords_blocklist = value.split('\n').map(s => s.trim()).filter(s => s);
            } else if (name === 'filters[ip_blocklist]') {
                settings.filters.ip_blocklist = value.split('\n').map(s => s.trim()).filter(s => s);
            } else if (name.match(/^retry\[(\w+)\]$/)) {
                var key = name.match(/^retry\[(\w+)\]$/)[1];
                settings.retry[key] = parseInt(value, 10);
            }
        });

        // Ensure smtp_tls defaults to false if checkbox unchecked
        if (!settings.channels.email.config.smtp_tls) {
            settings.channels.email.config.smtp_tls = false;
        }

        // Convert routing temp to array format
        Object.keys(routingTemp).forEach(function(type) {
            settings.routing_rules.push({
                type: type,
                channels: routingTemp[type]
            });
        });

        return settings;
    }

    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.gcore-comms-wrap h1').after($notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() { $(this).remove(); });
        }, 5000);
    }
});
</script>
