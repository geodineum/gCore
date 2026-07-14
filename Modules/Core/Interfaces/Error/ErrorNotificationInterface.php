<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Error Notification Interface
 * 
 * Defines the contract for error notification systems.
 */
interface ErrorNotificationInterface {
    /**
     * Send notification to admin
     * 
     * @param string $subject Notification subject
     * @param string $message Notification message
     * @param array $details Additional details
     * @return bool Success status
     */
    public function notifyAdmin(string $subject, string $message, array $details = []): bool;
}