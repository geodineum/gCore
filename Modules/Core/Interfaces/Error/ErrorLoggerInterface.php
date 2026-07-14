<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Error Logger Interface
 * 
 * Defines the contract for error logging systems.
 */
interface ErrorLoggerInterface {
    /**
     * Log an error message
     * 
     * @param string $level Log level (debug, info, notice, warning, error, critical, alert, emergency)
     * @param string $message Error message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function log(string $level, string $message, array $context = []): bool;
    
    /**
     * Track an error with context
     * 
     * @param string $level Error level
     * @param string $message Error message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function trackError(string $level, string $message, array $context = []): bool;
    
    /**
     * Write error to log file
     * 
     * @param string $level Error level
     * @param string $message Error message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public function writeErrorToLog(string $level, string $message, array $context = []): bool;
}