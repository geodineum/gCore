# ErrorManager Documentation

## Overview

The ErrorManager provides error handling, logging, tracking, and notification capabilities for the gCore framework. It enables perfect domain isolation with zero-coordination scaling across distributed systems, while maintaining high performance and reliability even in failure scenarios.

## Key Features

- **Domain Isolation**: Perfect multi-tenant isolation with shared infrastructure
- **Zero-Coordination Scaling**: Distributed operation without coordination overhead
- **Rich Context Tracking**: error context collection
- **Automatic Categorization**: Smart error classification and correlation
- **Multi-Channel Notifications**: Flexible notification routing with rate limiting
- **Self-Contained Mode**: Operation without dependencies for dependency-free initialization
- **Stream-Based Processing**: High-throughput error processing with backpressure control
- **Circuit Breaking**: Automatic failure detection and service protection
- **Trait-Based Extensibility**: Modular functionality through trait composition

## Architecture

The ErrorManager follows gCore's modular trait-based architecture:

### Core Components
- `ErrorManager.php`: Base manager with error handling
- `SelfContainedErrorHandler`: Standalone error handler for bootstrap phase
- `ValKeyManagerHelper`: Connection management with ValKey/Redis

### Traits
- `AdvancedLoggingTrait`: Enhanced logging capabilities
- `NotificationTrait`: Multi-channel notifications with rate limiting
- `ScriptHandlingTrait`: Script-based error handling and automation

### Integration with gCore
- Implements `ModuleInterface` for framework integration
- Provides global PHP error and exception handlers
- Supports standalone operation for framework initialization

## Getting Started

### Basic Usage

```php
// Get ErrorManager instance
$errorManager = gcore_get_error_manager();

// Track simple error
$errorManager->trackError('connection_failed', [
    'service' => 'database',
    'host' => 'db.example.com',
    'latency' => 1250
]);

// Log error with severity
$errorManager->logError(
    'validation_failed',
    [
        'form' => 'registration',
        'field' => 'email',
        'value' => $sanitizedEmail
    ],
    LOG_WARNING, // Severity level
    'VALIDATION' // Error category
);

// Send notifications
$errorManager->notify(
    'security_breach',
    [
        'user_id' => $userId,
        'ip' => $requestIp,
        'attempt_count' => $attempts
    ],
    ['email', 'slack'] // Notification channels
);

// Register global error handlers
$errorManager->registerErrorHandler();
$errorManager->registerExceptionHandler();
$errorManager->registerShutdownHandler();
```

### Error Context Collection

The ErrorManager automatically collects rich context for errors:

```php
try {
    // Potentially failing code
    processSomething();
} catch (\Exception $e) {
    // Log exception with automatic context collection
    $errorManager->logException($e, [
        'operation' => 'data_processing',
        'custom_context' => 'Additional information'
    ]);
}
```

The collected context includes:
- Exception class, message, code, and file location
- Stack trace with line numbers
- Previous exceptions (nested)
- Memory usage and execution time
- Request information (in web context)
- System environment details
- Custom context provided by the caller

## Advanced Features

### Multi-Channel Notifications

Configure and use multiple notification channels:

```php
// Initialize notification channels
$errorManager->setupNotificationChannels([
    'email' => [
        'recipients' => ['admin@example.com', 'ops@example.com'],
        'threshold' => 'WARNING' // Minimum level to notify
    ],
    'slack' => [
        'webhook' => 'https://hooks.slack.com/services/XXX/YYY/ZZZ',
        'channel' => '#alerts',
        'threshold' => 'ERROR'
    ],
    'sms' => [
        'numbers' => ['+1234567890'],
        'threshold' => 'CRITICAL',
        'rate_limit' => ['max' => 5, 'period' => 3600] // Max 5 per hour
    ]
]);

// Send to specific channel
$errorManager->notifyChannel('slack', 'Database connection failed', [
    'server' => 'db-primary',
    'error' => 'Connection timeout'
]);

// Notify administrators
$errorManager->notifyAdmin(
    'Critical system error',
    ['error' => 'Memory limit exceeded'],
    ['urgent' => true] // Options
);

// Send to all channels (respecting thresholds)
$errorManager->notifyAll(
    'System restart required',
    ['reason' => 'Scheduled maintenance']
);
```

### Automated Error Recovery

Implement automatic recovery strategies for known errors:

```php
// Register recovery script for specific error
$errorManager->registerErrorScript('connection_failed', '
    // Reset connection pool
    $pool = gcore_get_cache_manager()->getConnectionPool();
    $pool->clearPool();
    
    // Try to establish new connection
    return $pool->getConnection() !== null;
');

// Attempt recovery
if ($errorManager->isRecoverable('connection_failed')) {
    $success = $errorManager->attemptRecovery('connection_failed', [
        'service' => 'database'
    ]);
    
    if ($success) {
        // Recovery succeeded, continue operation
    } else {
        // Recovery failed, fallback to plan B
    }
}
```

### Error Pattern Analysis

Analyze error patterns to identify systemic issues:

```php
// Get error frequency
$frequency = $errorManager->getErrorFrequency('api_timeout', 3600); // Last hour

// Analyze error patterns
$patterns = $errorManager->analyzeErrorPattern('validation_failed', 86400); // Last day

// Get most frequent errors
$topErrors = $errorManager->getTopErrors(10, 86400); // Top 10 in last day
```

### Self-Contained Error Handling

Use the standalone error handler for framework initialization and dependency-free operation:

```php
// Get standalone error handler
$handler = \gCore\Modules\Core\Utils\SelfContainedErrorHandler::getInstance();

// Initialize with options
$handler->initialize([
    'log_path' => '/var/log/my-app/errors.log',
    'capture_stacktrace' => true,
    'log_level' => LOG_WARNING
]);

// Register as global handler
$handler->register();

try {
    // Critical initialization code
    initializeFramework();
} catch (\Throwable $e) {
    // Handle initialization failure
    $handler->handleException($e);
    exit(1);
}
```

## API Digest

### Main ErrorManager Class

- `getInstance(): ErrorManager` - Returns the singleton instance of the ErrorManager.
- `initialize(array $config = []): void` - Initializes the error management system with the given configuration.
- `trackError(string $code, mixed $context = null): bool` - Tracks an error with optional context data.
- `logError(string $code, mixed $context = null, int $level = LOG_ERR): bool` - Logs an error with specified severity level.
- `logException(\Throwable $exception, array $additionalContext = []): bool` - Logs an exception with stack trace.
- `notify(string $code, mixed $context = null, array $channels = []): bool` - Notifies about an error through specified channels.
- `throwException(string $code, mixed $context = null, string $exceptionClass = 'ErrorException'): never` - Throws an exception for an error.
- `renderErrorUI(string $code, mixed $context = null, array $options = []): string` - Renders a user-friendly error interface.
- `formatErrorMessage(string $code, mixed $context = null, string $template = null): string` - Formats an error message using a template.
- `registerErrorHandler(): bool` - Registers global PHP error handlers to intercept all errors.
- `registerExceptionHandler(): bool` - Registers global PHP exception handler to intercept all exceptions.
- `registerShutdownHandler(): bool` - Registers shutdown handler to catch fatal errors.
- `handleError(int $code, string $message, string $file, int $line): bool` - Handles a PHP error.
- `handleException(\Throwable $exception): bool` - Handles an uncaught exception.
- `handleShutdown(): void` - Handles PHP shutdown and checks for fatal errors.
- `getErrorContext(\Throwable $exception = null): array` - Gets context for an error or exception.
- `analyzeErrorPattern(string $code, int $timeframe = 3600): array` - Analyzes error patterns over a time period.
- `getErrorFrequency(string $code, int $timeframe = 3600): int` - Gets the frequency of a specific error.
- `getTopErrors(int $limit = 10, int $timeframe = 86400): array` - Gets the most frequent errors.
- `clearErrorHistory(string $code = null): bool` - Clears error history for a specific or all error types.
- `getSeverityName(int $level): string` - Gets human-readable name for an error severity level.
- `getExceptionChain(\Throwable $exception): array` - Gets the full chain of nested exceptions.
- `isRecoverable(string $code): bool` - Determines if an error is automatically recoverable.
- `attemptRecovery(string $code, mixed $context = null): bool` - Attempts to recover from an error.
- `getStatus(): array` - Returns the current status of the error management system.

### AdvancedLoggingTrait

- `setupLogger(array $config = []): bool` - Sets up the advanced logging system.
- `logWithContext(string $message, array $context = [], int $level = LOG_INFO): bool` - Logs a message with rich context.
- `getLogLevels(): array` - Gets all available log levels with their names.
- `setLogLevel(int $level): void` - Sets the minimum level for logging.
- `getLogLevel(): int` - Gets the current minimum log level.
- `getLoggers(): array` - Gets all configured loggers.
- `addLogger(string $name, callable $logger): bool` - Adds a custom logger.
- `removeLogger(string $name): bool` - Removes a logger.
- `rotateLogs(): bool` - Rotates log files to prevent excessive size.
- `getLogHistory(int $limit = 100, int $level = null): array` - Gets recent log entries.
- `clearLogs(): bool` - Clears all logs.
- `getLogStats(): array` - Gets statistics about logging activity.
- `exportLogs(array $options = []): string` - Exports logs in a specified format.

### NotificationTrait

- `setupNotificationChannels(array $channels = []): bool` - Sets up notification channels.
- `notifyAdmin(string $message, array $context = [], array $options = []): bool` - Sends notification to administrators.
- `notifyChannel(string $channel, string $message, array $context = []): bool` - Sends notification to a specific channel.
- `notifyAll(string $message, array $context = []): array` - Sends notification to all active channels.
- `notifyWithTemplate(string $templateName, array $data, array $channels = []): bool` - Sends notification using a template.
- `registerNotificationChannel(string $name, callable $handler): bool` - Registers a custom notification channel.
- `unregisterNotificationChannel(string $name): bool` - Unregisters a notification channel.
- `getActiveChannels(): array` - Gets all active notification channels.
- `isChannelActive(string $channel): bool` - Checks if a notification channel is active.
- `getNotificationHistory(array $filters = []): array` - Gets notification history with filters.
- `rateLimit(string $channel, string $key, int $max = 10, int $period = 3600): bool` - Applies rate limiting to notifications.
- `getRateLimitStatus(string $channel, string $key): array` - Gets rate limit status for a notification.
- `clearRateLimits(): bool` - Clears all notification rate limits.

### ScriptHandlingTrait

- `registerErrorScript(string $errorCode, string $script): bool` - Registers a script to handle a specific error.
- `executeErrorScript(string $errorCode, array $context = []): mixed` - Executes a script for a specific error.
- `hasErrorScript(string $errorCode): bool` - Checks if a script exists for a specific error.
- `removeErrorScript(string $errorCode): bool` - Removes a script for a specific error.
- `getErrorScripts(): array` - Gets all registered error scripts.
- `registerGlobalErrorScript(string $name, string $script): bool` - Registers a global error handling script.
- `executeGlobalErrorScript(string $name, array $context = []): mixed` - Executes a global error handling script.
- `compileScriptTemplate(string $template, array $data): string` - Compiles a script template with data.
- `validateScript(string $script): bool` - Validates a script for syntax and security.
- `getScriptExecutionStats(): array` - Gets statistics about script executions.

### SelfContainedErrorHandler

- `getInstance(): SelfContainedErrorHandler` - Gets the singleton instance of the self-contained error handler.
- `initialize(array $options = []): void` - Initializes the error handler with options.
- `register(): void` - Registers this handler as the global error handler.
- `handleError(int $level, string $message, string $file, int $line): bool` - Handles a PHP error.
- `handleException(\Throwable $exception): void` - Handles an uncaught exception.
- `handleShutdown(): void` - Handles PHP shutdown and captures fatal errors.
- `captureError(string $code, array $context = []): void` - Captures an error with context.
- `formatErrorMessage(string $message, array $context = []): string` - Formats an error message.
- `formatExceptionMessage(\Throwable $exception): string` - Formats an exception message.
- `getBacktrace(int $limit = 10, int $skip = 0): array` - Gets a backtrace with specified depth.
- `getErrorContext(): array` - Gets the current error context.
- `getCapabilities(): array` - Gets the error handling capabilities available.
- `isRegistered(): bool` - Checks if this handler is registered as the global handler.
- `unregister(): void` - Unregisters this handler from being the global handler.

## Domain Isolation and Scaling

The ErrorManager ensures perfect isolation in multi-tenant environments:

```php
// Site-specific key generation ensures proper isolation
private function buildStateKey(string $type, ?string $subKey = null): string
{
    $key = sprintf(
        '{%s}:node:%s:errors:%s',
        $this->siteId,
        $this->nodeId,
        $type
    );
    
    if ($subKey !== null) {
        $key .= ':' . $subKey;
    }
    
    return $key;
}
```

This design enables:
1. **Zero-Coordination Scaling**: Multiple nodes operate independently
2. **Perfect Domain Isolation**: Errors from one tenant never leak to another
3. **Shared Infrastructure**: All tenants use the same ValKey/Redis backend
4. **Hierarchical Aggregation**: Cross-site metrics with proper isolation

## Error Stream Processing

High-throughput error processing with backpressure control:

```php
// Error processing happens through queues, not direct handling
$errorManager->processErrorQueue(100); // Process up to 100 errors

// Stream-based error processing provides backpressure
$errorId = $valKey->xadd(
    $this->buildStateKey('stream'),
    '*', // Auto-generate ID
    [
        'code' => $errorCode,
        'context' => $this->serializeContext($context),
        'time' => microtime(true),
        'level' => $level
    ]
);

// Automatic stream trimming prevents unbounded growth
$valKey->xtrim(
    $this->buildStateKey('stream'),
    'MAXLEN', 
    '~', // Approximate trimming for performance
    $this->config['max_error_stream_size'] ?? 10000
);
```

## Performance Optimization

Optimized for high-throughput error handling:

1. **Batch Processing**: Process multiple errors in batches
2. **Deferred Handling**: Non-critical errors are processed asynchronously 
3. **Rate Limiting**: Throttling for high-frequency errors
4. **Stream-Based Architecture**: Efficient error queueing
5. **Context Sampling**: Selective context for high-volume errors

```php
// Rate limiting prevents error storms
if ($this->isRateLimited($errorCode)) {
    // Track but don't process fully
    $this->incrementCounter('rate_limited', 1);
    return false;
}

// Context sampling reduces storage for high-volume errors
$sampleRate = $this->getSampleRate($errorCode);
if (rand(1, 100) > $sampleRate) {
    // Store minimal context
    $context = ['sampled' => true];
}
```

## Integration with Other Managers

The ErrorManager integrates with:

1. **CacheManager**: For distributed error storage
2. **SecurityManager**: For security-related events
3. **APIManager**: For API error handling

It's also designed to function independently during initialization to avoid circular dependencies.

## Best Practices

1. **Use Domain-Specific Error Codes**
   - Create meaningful error codes
   - Use prefix for subsystems (DB_, API_, AUTH_)
   - Include error type in code (NOT_FOUND, TIMEOUT)

2. **Provide Rich Context**
   - Include relevant data for diagnosis
   - Avoid sensitive information (credentials, personal data)
   - Use sanitized values for context

3. **Set Appropriate Severity Levels**
   - Use LOG_DEBUG for verbose information
   - Use LOG_INFO for normal operations
   - Use LOG_WARNING for concerning but non-critical issues
   - Use LOG_ERROR for failures requiring attention
   - Use LOG_CRITICAL for system-threatening issues

4. **Configure Notification Channels Wisely**
   - Set appropriate thresholds for each channel
   - Use rate limiting to prevent notification fatigue
   - Include sufficient context for action

5. **Monitor Error Patterns**
   - Track error frequency and patterns
   - Set up alerts for abnormal error rates
   - Analyze for correlations between errors

## Troubleshooting

### Common Issues

1. **High Error Volumes**
   - Implement rate limiting
   - Use sampling for high-frequency errors
   - Check for loops generating errors

2. **Missing Context**
   - Ensure context is provided with all error calls
   - Verify context serialization works correctly
   - Check context size limits

3. **Notification Failures**
   - Verify notification channel configuration
   - Check rate limiting status
   - Ensure notification handlers are working

4. **Performance Issues**
   - Use batch processing for errors
   - Implement appropriate sampling
   - Optimize context size

## Conclusion

The ErrorManager provides error handling, logging, and notification capabilities for gCore applications. Its domain isolation, stream-based architecture, and multi-channel notifications make it ideal for distributed, multi-tenant environments where reliability and performance are critical.

---

*Updated: March 2025*