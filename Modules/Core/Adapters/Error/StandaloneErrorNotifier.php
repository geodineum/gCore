<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Adapters\Error;

use gCore\Modules\Core\Interfaces\Error\ErrorNotificationInterface;
use gCore\Modules\Core\Exceptions\NotificationException;

require_once dirname(__DIR__, 3) . '/Managers/Traits/StateManagerAware.php';
use gCore\Modules\Managers\Traits\StateManagerAware;

class StandaloneErrorNotifier implements ErrorNotificationInterface {
    use StateManagerAware;
    /** @var array Configuration */
    private $config;
    
    /** @var array Capability mapping */
    private $capabilities = [
        'notify_admin' => true,
        'notify_user' => true,
        'notify_team' => true
    ];
    
    /**
     * Constructor
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'from_email' => 'gcore@' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'),
            'from_name' => 'gCore Error Notifier',
            'admin_email' => '',
            'team_emails' => [],
            'notification_level' => 'error',
            'site_id' => 'default',
            'throttling' => [
                'enabled' => true,
                'max_notifications' => 10,
                'period' => 3600
            ]
        ], $config);

        // Set site ID for StateManagerAware trait
        $this->siteId = $this->config['site_id'];

        if (isset($config['capabilities'])) {
            $this->capabilities = array_merge($this->capabilities, $config['capabilities']);
        }
    }
    
    /**
     * Send notification
     */
    public function notify(string $recipient, string $subject, string $message): bool {
        try {
            // Check throttling
            if ($this->config['throttling']['enabled'] && !$this->checkThrottling()) {
                // Log instead of sending if throttled
                error_log("Notification throttled: {$subject}");
                return false;
            }
            
            // Build headers
            $headers = [
                'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8'
            ];
            
            // Send mail
            $sent = mail(
                $recipient,
                '[gCore] ' . $subject,
                $this->formatMessage($message),
                implode("\r\n", $headers)
            );
            
            // Update notification count
            if ($sent) {
                $this->updateNotificationCount();
            }
            
            return $sent;
            
        } catch (\Exception $e) {
            error_log("Notification error: {$e->getMessage()}");
            throw new NotificationException("Failed to send notification: {$e->getMessage()}");
        }
    }
    
    /**
     * Check if user has capability
     */
    public function hasPermission(string $capability): bool {
        return $this->capabilities[$capability] ?? false;
    }
    
    /**
     * Format notification message as HTML
     */
    private function formatMessage(string $message): string {
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>gCore Error Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f2f2f2; padding: 10px; border-bottom: 2px solid #ddd; }
        .content { padding: 15px 0; }
        .footer { font-size: 12px; color: #777; border-top: 1px solid #eee; padding-top: 10px; }
        pre { background: #f9f9f9; padding: 10px; border: 1px solid #ddd; overflow: auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>gCore Error Notification</h2>
        </div>
        <div class="content">
            {$message}
        </div>
        <div class="footer">
            <p>This is an automated message from gCore Error Notifier.</p>
            <p>Time: {$this->getFormattedTime()}</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        return $html;
    }
    
    /**
     * Get formatted time
     */
    private function getFormattedTime(): string {
        return date('Y-m-d H:i:s');
    }
    
    /**
     * Check notification throttling
     *
     * Uses distributed counter via StateManagerAware for cross-worker
     * throttle tracking. When StateManager unavailable, getCounter returns 0
     * so throttle check passes — notifications always sent (correct during errors).
     */
    private function checkThrottling(): bool {
        $max = $this->config['throttling']['max_notifications'];
        $count = $this->getCounter('error_notifications');

        return $count < $max;
    }

    /**
     * Update notification count
     *
     * Atomically increments the distributed counter with TTL matching
     * the throttle period. Counter auto-expires, resetting the window.
     */
    private function updateNotificationCount(): void {
        $period = $this->config['throttling']['period'];
        $this->incrementCounter('error_notifications', 1, $period);
    }
}