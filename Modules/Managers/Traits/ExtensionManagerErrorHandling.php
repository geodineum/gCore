<?php
declare(strict_types=1);
/**
 * ExtensionManagerErrorHandling Trait
 *
 * Standardized error handling for extension managers.
 * Routes errors through ErrorManager when available, falls back to error_log().
 * Prevents silent error swallowing that makes production debugging impossible.
 *
 * Usage:
 *   use gCore\Modules\Managers\Traits\ExtensionManagerErrorHandling;
 *   class MyManagerPro {
 *       use ExtensionManagerErrorHandling;
 *       // then: $this->logStorageError('methodName', $exception);
 *   }
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Traits
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Traits;

trait ExtensionManagerErrorHandling
{
    /**
     * Log a storage/ValKey operation error with full context.
     *
     * Routes to ErrorManager → Comms alerting when available,
     * falls back to error_log() when ErrorManager is not yet initialized.
     *
     * @param string     $method    The method where the error occurred
     * @param \Throwable $e         The exception or error
     * @param array      $context   Additional context (key, site_id, etc.)
     * @return void
     */
    protected function logStorageError(string $method, \Throwable $e, array $context = []): void
    {
        $managerName = static::class;
        $shortName = substr($managerName, strrpos($managerName, '\\') + 1);
        $message = "[gCore][{$shortName}] {$method} failed: {$e->getMessage()}";

        // Try ErrorManager first (routes to Comms/alerting pipeline)
        try {
            $gCore = \gCore\Modules\Core\gCore::getInstance();
            $errorManager = $gCore->getService('ErrorManager');
            if ($errorManager && method_exists($errorManager, 'trackError')) {
                $errorManager->trackError($message, array_merge([
                    'manager' => $shortName,
                    'method' => $method,
                    'exception_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ], $context));
                return;
            }
        } catch (\Throwable $inner) {
            // ErrorManager not available — fall through to error_log
        }

        // Fallback: direct error_log with context
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        error_log($message . $contextStr);
    }

    /**
     * Log an informational message for extension manager operations.
     *
     * @param string $message The message to log
     * @return void
     */
    protected function logExtensionInfo(string $message): void
    {
        $shortName = substr(static::class, strrpos(static::class, '\\') + 1);
        error_log("[gCore][{$shortName}] {$message}");
    }
}
