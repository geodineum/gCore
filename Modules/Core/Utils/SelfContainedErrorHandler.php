<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Utils;

use gCore\Modules\Core\Adapters\Shared\ValKeyStorage;

/**
 * Self-Contained Error Handler
 * 
 * Provides error handling functionality that can be used independently by managers
 * to avoid circular dependencies between managers. Each manager can use this
 * utility to handle errors without depending on ErrorManager.
 */
class SelfContainedErrorHandler {
    /**
     * Get the site ID for log directory isolation.
     * Uses HTTP_HOST to derive a safe site identifier.
     *
     * @return string Site ID (e.g., "example_com", "staging_example_com")
     */
    private static function getSiteId(): string {
        // Check if already defined as a constant
        if (defined('GCORE_SITE_ID')) {
            return preg_replace('/[^a-zA-Z0-9_]/', '_', GCORE_SITE_ID);
        }

        // Detect from HTTP_HOST (same logic as gcore_early_cache_get_site_id)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($host)) {
            return 'default';
        }

        // Clean domain: remove www. and port
        $host = preg_replace('/^www\./', '', $host);
        $host = preg_replace('/:[0-9]+$/', '', $host);

        // Convert to safe ID (dots and hyphens become underscores)
        return str_replace(['.', '-'], '_', $host);
    }

    /**
     * Get the log directory path.
     * Uses centralized geodineum logging if GEODINEUM_LOG_DIR is defined,
     * otherwise falls back to WP_CONTENT_DIR/logs or ABSPATH/logs.
     *
     * @return string|null Log directory path, or null if unavailable
     */
    private static function getLogDirectory(): ?string {
        // Priority 1: Centralized geodineum logging
        if (defined('GEODINEUM_LOG_DIR')) {
            $siteId = self::getSiteId();
            $logDir = GEODINEUM_LOG_DIR . '/gcore/sites/' . $siteId;

            // Directory should already exist (created by setup-centralized-logging.sh)
            // but create if missing for robustness
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0750, true);
            }

            return $logDir;
        }

        // Priority 2: WordPress content directory
        if (defined('ABSPATH')) {
            $logDir = defined('WP_CONTENT_DIR') ?
                WP_CONTENT_DIR . '/logs' :
                ABSPATH . '/logs';

            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }

            return $logDir;
        }

        return null;
    }

    /**
     * Log error to file system
     *
     * @param string $managerName Name of the manager reporting the error
     * @param string $operation Operation that failed
     * @param \Throwable $error The exception that occurred
     * @param array $context Additional context about the error
     * @return void
     */
    public static function logError(
        string $managerName,
        string $operation,
        \Throwable $error,
        array $context = []
    ): void {
        // Ensure no path traversal in manager name
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $managerName);
        
        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');
        
        // Format error message
        $message = sprintf(
            "[%s] [%s] [%s] Error: %s in %s on line %d\nContext: %s\n",
            $timestamp,
            $safeName,
            $operation,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
            json_encode($context, JSON_PRETTY_PRINT)
        );
        
        // Log to error_log (usually Apache/PHP error log)
        error_log($message);

        // Also log to a file if available
        $logDir = self::getLogDirectory();
        if ($logDir !== null) {
            // Consolidated: single file per manager (all levels)
            $logFile = $logDir . '/' . $safeName . '.log';
            @file_put_contents($logFile, $message, FILE_APPEND);
        }
    }

    /**
     * Log error message directly without an exception
     * 
     * @param string $managerName Name of the manager reporting the error
     * @param string $operation Operation that failed
     * @param string $message Error message
     * @param array $context Additional context about the error
     * @return void
     */
    public static function logErrorMessage(
        string $managerName,
        string $operation,
        string $message,
        array $context = []
    ): void {
        // Ensure no path traversal in manager name
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $managerName);
        
        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');
        
        // Format error message
        $formattedMessage = sprintf(
            "[%s] [%s] [%s] Error: %s\nContext: %s\n",
            $timestamp,
            $safeName,
            $operation,
            $message,
            json_encode($context, JSON_PRETTY_PRINT)
        );
        
        // Log to error_log (usually Apache/PHP error log)
        error_log($formattedMessage);

        // Also log to a file if available
        $logDir = self::getLogDirectory();
        if ($logDir !== null) {
            // Consolidated: single file per manager (all levels)
            $logFile = $logDir . '/' . $safeName . '.log';
            @file_put_contents($logFile, $formattedMessage, FILE_APPEND);
        }
    }

    /**
     * Log a message with configurable severity level
     *
     * @param string $managerName Name of the manager reporting
     * @param string $operation Operation being performed
     * @param string $level Log level: 'debug'|'info'|'warning'|'error'
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    /**
     * Flood-control severity gate (the single logging threshold authority for
     * gCore's self-contained logger AND the stub/resolver notices). Returns
     * false for events below the active threshold so they are never written.
     *
     * Deliberately NOT tied to WP_DEBUG: prod sites here run with WP_DEBUG on,
     * so gating on it would never suppress anything. Threshold order:
     *   1. GCORE_LOG_LEVEL env       (debug|info|warning|error)
     *   2. GCORE_LOG_LEVEL constant   (settable in wp-config)
     *   3. default 'warning'          (production stays quiet: warn+error only)
     */
    public static function shouldLog(string $level): bool
    {
        static $rank = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        $min = getenv('GCORE_LOG_LEVEL');
        if (($min === false || $min === '') && defined('GCORE_LOG_LEVEL')) {
            $min = (string) constant('GCORE_LOG_LEVEL');
        }
        if ($min === false || $min === '') {
            $min = 'warning';
        }
        $min = strtolower((string) $min);
        return ($rank[strtolower($level)] ?? 1) >= ($rank[$min] ?? 2);
    }

    public static function logMessage(
        string $managerName,
        string $operation,
        string $level,
        string $message,
        array $context = []
    ): void {
        // Ensure no path traversal in manager name
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $managerName);

        // Normalize and validate level
        $level = strtolower($level);
        $validLevels = ['debug', 'info', 'warning', 'error'];
        if (!in_array($level, $validLevels)) {
            $level = 'info';
        }

        // Severity gate (flood control). In a stateless PHP request gCore
        // re-initialises every manager, so per-request INFO ("using gNode
        // integration", "Successfully initialized", …) floods the log on every
        // connection. Drop sub-threshold events entirely. Default threshold =
        // 'warning'; lower it for diagnostics via WP_DEBUG or GCORE_LOG_LEVEL
        // (debug|info|warning|error).
        if (!self::shouldLog($level)) {
            return;
        }
        $levelLabel = ucfirst($level);

        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');

        // Format message
        $formattedMessage = sprintf(
            "[%s] [%s] [%s] %s: %s\nContext: %s\n",
            $timestamp,
            $safeName,
            $operation,
            $levelLabel,
            $message,
            json_encode($context, JSON_PRETTY_PRINT)
        );

        // Log to error_log (usually Apache/PHP error log)
        error_log($formattedMessage);

        // Also log to a file if available
        $logDir = self::getLogDirectory();
        if ($logDir !== null) {
            // Consolidated: single file per manager (all levels in one file)
            // Level is included in the message itself
            $logFile = $logDir . '/' . $safeName . '.log';
            @file_put_contents($logFile, $formattedMessage, FILE_APPEND);
        }
    }

    /**
     * Log an informational message (convenience wrapper)
     *
     * @param string $managerName Name of the manager reporting
     * @param string $operation Operation being performed
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public static function logInfo(
        string $managerName,
        string $operation,
        string $message,
        array $context = []
    ): void {
        self::logMessage($managerName, $operation, 'info', $message, $context);
    }

    /**
     * Log a warning message (convenience wrapper)
     *
     * @param string $managerName Name of the manager reporting
     * @param string $operation Operation being performed
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public static function logWarning(
        string $managerName,
        string $operation,
        string $message,
        array $context = []
    ): void {
        self::logMessage($managerName, $operation, 'warning', $message, $context);
    }

    /**
     * Log a debug message (convenience wrapper)
     *
     * @param string $managerName Name of the manager reporting
     * @param string $operation Operation being performed
     * @param string $message Log message
     * @param array $context Additional context
     * @return void
     */
    public static function logDebug(
        string $managerName,
        string $operation,
        string $message,
        array $context = []
    ): void {
        self::logMessage($managerName, $operation, 'debug', $message, $context);
    }

    /**
     * Track error metrics in ValKey if available
     * 
     * @param \Redis $redis Redis connection
     * @param string $managerName Name of the manager reporting the error
     * @param string $operation Operation that failed
     * @param string $message Error message
     * @param string $prefix Key prefix to use
     * @return bool Success status
     */
    public static function trackErrorMetric(
        \Redis $redis,
        string $managerName,
        string $operation,
        string $message,
        string $prefix = 'gcore_'
    ): bool {
        try {
            if (!$redis || !($redis instanceof \Redis)) {
                return false;
            }
            
            // Update error count
            $redis->hIncrBy(
                $prefix . 'error_metrics',
                $managerName . ':' . $operation,
                1
            );
            
            // Store most recent error
            $redis->hSet(
                $prefix . 'last_errors',
                $managerName . ':' . $operation,
                json_encode([
                    'message' => $message,
                    'timestamp' => time()
                ])
            );
            
            return true;
        } catch (\Exception $e) {
            // Fail silently - this is the error handler itself
            return false;
        }
    }
    
    /** @var int Maximum errors to keep in sorted set per site/node */
    private const MAX_ERROR_LIST_SIZE = 1000;

    /** @var int Rate limit: minimum seconds between updates for same error */
    private const ERROR_RATE_LIMIT_SECONDS = 60;

    /**
     * Track error with ValKeyStorage adapter
     *
     * Uses deduplication to prevent error accumulation:
     * - Same error (by hash) updates occurrence count instead of creating new keys
     * - Rate limiting prevents flooding (max 1 update per error per 60s)
     * - Auto-trims sorted set to MAX_ERROR_LIST_SIZE entries
     *
     * @param ValKeyStorage $storage ValKeyStorage instance
     * @param string $managerName Name of the manager reporting the error
     * @param string $level Error level (error, warning, info, etc)
     * @param string $message Error message
     * @param array $context Additional context about the error
     * @param array $nodeMetadata Site and node identifiers for isolation
     * @return bool Success status
     */
    public static function trackErrorWithStorage(
        ValKeyStorage $storage,
        string $managerName,
        string $level,
        string $message,
        array $context = [],
        array $nodeMetadata = ['site_id' => 'default', 'node_id' => 'node1']
    ): bool {
        try {
            if (!$storage->isConnected()) {
                return false;
            }

            $siteId = $nodeMetadata['site_id'] ?? 'default';
            $nodeId = $nodeMetadata['node_id'] ?? 'node1';
            $now = time();

            // Generate stable error ID (hash only - no timestamp for deduplication)
            $errorId = md5($message . json_encode($context));

            // Rate limit key - prevents flooding for same error
            $rateLimitKey = "error_rate:{$siteId}:{$nodeId}:{$errorId}";

            // Check rate limit (returns remaining TTL or -2 if key doesn't exist)
            $rateLimitTtl = $storage->ttl($rateLimitKey);
            if ($rateLimitTtl > 0) {
                // Rate limited - skip this occurrence but it's not an error
                return true;
            }

            // Set rate limit (expires after RATE_LIMIT_SECONDS)
            $storage->set($rateLimitKey, '1', self::ERROR_RATE_LIMIT_SECONDS);

            // Storage keys
            $errorKey = "error:{$siteId}:{$nodeId}:{$errorId}";
            $listKey = "errors:{$siteId}:{$nodeId}";
            $countKey = "error_count:{$siteId}:{$nodeId}:{$level}";

            // Check if error already exists
            $existingData = $storage->get($errorKey);

            if ($existingData) {
                // Update existing error - increment occurrence count, update last_seen
                $errorData = json_decode($existingData, true);
                if (is_array($errorData)) {
                    $errorData['occurrence_count'] = ($errorData['occurrence_count'] ?? 1) + 1;
                    $errorData['last_seen'] = $now;
                    // Update context if it changed (might have new stack trace info)
                    if (!empty($context)) {
                        $errorData['context'] = $context;
                    }
                    $storage->set($errorKey, json_encode($errorData), 604800); // Refresh TTL

                    // Update sorted set score to latest timestamp
                    $storage->zAdd($listKey, $now, $errorId);

                    return true;
                }
            }

            // New error - create full record
            $errorData = [
                'level' => strtolower($level),
                'message' => $message,
                'context' => $context,
                'first_seen' => $now,
                'last_seen' => $now,
                'occurrence_count' => 1,
                'site_id' => $siteId,
                'node_id' => $nodeId,
                'manager' => $managerName
            ];

            // Store error (1 week TTL)
            $storage->set($errorKey, json_encode($errorData), 604800);

            // Add to sorted set (by timestamp for ordering)
            $storage->zAdd($listKey, $now, $errorId);

            // Increment error count
            $storage->incr($countKey);

            // Auto-trim: remove oldest entries if over limit
            // zCard returns count, zRemRangeByRank removes by index
            $listSize = $storage->zCard($listKey);
            if ($listSize > self::MAX_ERROR_LIST_SIZE) {
                // Remove oldest entries (lowest scores = oldest timestamps)
                $removeCount = $listSize - self::MAX_ERROR_LIST_SIZE;
                $storage->zRemRangeByRank($listKey, 0, $removeCount - 1);
            }

            return true;
        } catch (\Exception $e) {
            // Fail silently - log to error_log as fallback
            error_log("Failed to track error with ValKey: " . $e->getMessage());
            error_log("Original error: [{$level}] {$message}");
            return false;
        }
    }
    
    /**
     * Determine if an error is critical
     * 
     * @param \Throwable $error The exception to check
     * @return bool True if error is critical
     */
    public static function isCriticalError(\Throwable $error): bool {
        // Check error class to determine if it's critical
        $criticalClasses = [
            'InitializationException',
            'ConnectionException',
            'StorageException',
            'AuthorizationException',
            'SecurityException'
        ];
        
        foreach ($criticalClasses as $class) {
            if (strpos(get_class($error), $class) !== false) {
                return true;
            }
        }
        
        // Also check message for critical keywords
        $criticalKeywords = [
            'connection failed',
            'unable to connect',
            'authentication failed',
            'security violation',
            'critical'
        ];
        
        foreach ($criticalKeywords as $keyword) {
            if (stripos($error->getMessage(), $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Safely serialize data
     * 
     * @param mixed $data Data to serialize
     * @return string Serialized data
     */
    public static function safeSerialize($data): string {
        try {
            return serialize($data);
        } catch (\Exception $e) {
            return serialize('Serialization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Safely unserialize data
     * 
     * @param string $data Data to unserialize
     * @param mixed $default Default value if unserialization fails
     * @return mixed Unserialized data or default
     */
    public static function safeUnserialize(string $data, $default = null) {
        try {
            $result = @unserialize($data, ['allowed_classes' => false]);
            return $result !== false ? $result : $default;
        } catch (\Exception $e) {
            error_log("[gCore] SelfContainedErrorHandler::safeUnserialize failed: " . $e->getMessage());
            return $default;
        }
    }
    
    /**
     * Safely encode JSON
     * 
     * @param mixed $data Data to encode
     * @param int $options JSON encoding options
     * @return string JSON-encoded string
     */
    public static function safeJsonEncode($data, int $options = 0): string {
        try {
            $result = json_encode($data, $options);
            if ($result === false) {
                return json_encode([
                    'error' => 'JSON encoding failed: ' . json_last_error_msg(),
                    'data_type' => gettype($data)
                ]);
            }
            return $result;
        } catch (\Exception $e) {
            return json_encode([
                'error' => 'JSON encoding failed with exception: ' . $e->getMessage(),
                'data_type' => gettype($data)
            ]);
        }
    }
    
    /**
     * Safely decode JSON
     * 
     * @param string $json JSON string to decode
     * @param bool $assoc Return as associative array instead of object
     * @param mixed $default Default value if decoding fails
     * @return mixed Decoded data or default
     */
    public static function safeJsonDecode(string $json, bool $assoc = true, $default = null) {
        try {
            $result = json_decode($json, $assoc);
            if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
                return $default;
            }
            return $result;
        } catch (\Exception $e) {
            error_log("[gCore] SelfContainedErrorHandler::safeJsonDecode failed: " . $e->getMessage());
            return $default;
        }
    }
}