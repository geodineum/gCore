# ErrorManager Documentation

## Overview

ErrorManager provides site-isolated error management for the gCore framework. It handles error tracking, logging, notifications, and recovery with complete isolation through site_id/node_id prefixing. Built with a focus on preventing key avalanche and enabling error deduplication.

**Namespace**: `gCore\Modules\Managers\Base\ErrorManager`
**Implements**: `ModuleInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)

## Architecture

ErrorManager operates in two modes:

1. **gNode-enhanced Mode**: Uses ValKey sorted sets for efficient error tracking with deduplication, occurrence counting, and automatic cleanup
2. **Default Tier Mode**: Uses StorageInterface adapters with array-based error lists

### Error Flow

```
PHP Error/Exception
    ├── handleError() / handleException() / handleShutdown()
    ├── trackError() with deduplication
    │   ├── Generate stable error ID (hash of message + context)
    │   ├── Rate limit check (60s between updates)
    │   └── Store/update error data
    ├── Check error threshold
    └── Notify admin if threshold exceeded
```

## Initialization

```php
// Get ErrorManager instance via gCore
$errorManager = gCore::getService('ErrorManager');

// Or using helper function
$errorManager = gcore_get_error_manager();

// Configuration (passed during gCore initialization)
$config = [
    'storage' => [
        'host' => '127.0.0.1',
        'port' => 47445,
        'timeout' => 2.0,
        'prefix' => 'error_',
    ],
    'default_ttl' => 604800,           // 1 week for errors
    'ttl_by_level' => [
        'debug' => 3600,               // 1 hour
        'info' => 3600,                // 1 hour
        'notice' => 21600,             // 6 hours
        'warning' => 86400,            // 1 day
        'error' => 604800,             // 1 week
        'critical' => 2592000,         // 30 days
        'alert' => 2592000,            // 30 days
        'emergency' => 2592000         // 30 days
    ],
    'skip_info_storage' => true,       // Prevent key avalanche
    'max_errors' => 1000,
    'error_threshold' => 50,
    'capture_backtraces' => true,
    'notification_channels' => ['email'],
    'notification_rate_limit' => 60,   // 1 minute
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'gnode_client' => $gNodeClient
];
```

## Public API Reference

### Singleton & Lifecycle

#### `getInstance(): ModuleInterface`
Returns the singleton instance of ErrorManager.

#### `initialize(array $config = []): void`
Initializes the error system with configuration, registers error handlers.
- **Throws**: `InitializationException` if initialization fails

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getStatus(): array`
Get full status including error statistics and storage info.

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

### Error Handling (Auto-registered)

These handlers are automatically registered during initialization:

#### `handleError(int $level, string $message, string $file, int $line, array $context = []): bool`
Handle PHP errors.
- Automatically registered via `set_error_handler()`
- Maps PHP error levels to log levels (error, warning, notice, info)
- **Returns**: `true` to prevent PHP's internal handler

#### `handleException(\Throwable $exception): void`
Handle uncaught exceptions.
- Automatically registered via `set_exception_handler()`
- Uses 'warning' level for ValidationException, 'error' for others

#### `handleShutdown(): void`
Capture fatal errors on script shutdown.
- Automatically registered via `register_shutdown_function()`
- Catches E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR

### Error Tracking

#### `trackError(string $level, string $message, array $context = []): bool`
Track error with context and deduplication.
- **Parameters**:
  - `$level`: Error level (debug, info, notice, warning, error, critical, alert, emergency)
  - `$message`: Error message
  - `$context`: Additional context (file, line, trace, etc.)
- **Returns**: `true` if tracked successfully
- **Features**:
  - Generates stable error ID for deduplication (hash of message + context)
  - Rate limiting: 60 seconds between updates for same error
  - Occurrence counting: increments count instead of creating duplicates
  - Level-based TTL: different retention periods per severity
  - Skips INFO-level system events to prevent key avalanche

#### `trackSystemEvent(string $level, string $message, array $context = []): bool`
Track system event (adds `_system` flag to context).
- Used for initialization messages and system state changes
- Skipped from storage when `skip_info_storage` is enabled

### Error Retrieval

#### `getRecentErrors(int $limit = 10, int $offset = 0): array`
Get recent errors with pagination.
- **Returns**: Array of error objects with message, context, occurrence_count, first_seen, last_seen

#### `getErrorStats(): array`
Get error statistics by level.
- **Returns**: `['total' => int, 'by_level' => ['error' => int, 'warning' => int, ...]]`

#### `clearErrorHistory(): bool`
Clear all error history for current site/node.
- Resets counters and deletes all stored errors

### Notifications

#### `notifyAdmin(string $subject, string $message, array $details = []): bool`
Send notification to admin.
- **Parameters**:
  - `$subject`: Notification subject
  - `$message`: Notification body
  - `$details`: Additional details for email
- **Features**:
  - Rate limiting: Configurable minimum interval between notifications
  - Email channel support (configurable)
- **Returns**: `true` if notification sent
- **Throws**: `NotificationException` in debug mode

### Logging

#### `log(string $level, string $message, array $context = []): bool`
Simple logging interface.
- Writes to PHP's error_log

#### `writeErrorToLog(string $level, string $message, array $context = []): bool`
Write error to PHP error_log.
- Direct access to error_log without storage

### Broadcast Operations (gNode-enhanced)

#### `broadcastErrorAlert(string $severity, string $message, array $context = []): string|false`
Broadcast error alert to all nodes.
- **Parameters**:
  - `$severity`: Error severity (critical, error, warning)
- **Returns**: Message ID on success

#### `listenForErrorAlerts(int $count = 10, int $blockMs = 100): array`
Listen for error alerts from other nodes.
- **Returns**: Array of processed alerts

#### `logCriticalError(string $message, array $context = [], bool $broadcast = true): bool`
Log critical error locally and optionally broadcast.

#### `broadcastHealthIssue(string $issueType, array $metrics): string|false`
Broadcast system health issue to all nodes.

#### `broadcastRecovery(string $recoveryType, array $details): string|false`
Broadcast node recovery notification.

#### `getErrorAlertStatistics(): array`
Get statistics about error broadcasts.

#### `startErrorAlertMonitoring(callable $callback, int $interval = 5): bool`
Set up automatic error alert monitoring.

### Capability Discovery

#### `getCapabilityVector(): array`
Get capability vector for geometric service discovery.

## Usage Examples

### Manual Error Tracking

```php
$errorManager = gCore::getService('ErrorManager');

// Track a warning
$errorManager->trackError('warning', 'API rate limit approaching', [
    'endpoint' => '/api/users',
    'current_rate' => 95,
    'limit' => 100
]);

// Track a critical error
$errorManager->trackError('critical', 'Database connection failed', [
    'host' => 'db.example.com',
    'error_code' => 'ETIMEDOUT'
]);

// Log system event
$errorManager->trackSystemEvent('info', 'Cache cleared', [
    'trigger' => 'manual',
    'keys_removed' => 150
]);
```

### Retrieving Errors

```php
// Get recent errors
$errors = $errorManager->getRecentErrors(20, 0);

foreach ($errors as $error) {
    echo "{$error['level']}: {$error['message']}\n";
    echo "  Occurrences: {$error['occurrence_count']}\n";
    echo "  First seen: " . date('Y-m-d H:i:s', $error['first_seen']) . "\n";
}

// Get statistics
$stats = $errorManager->getErrorStats();
echo "Total errors: {$stats['total']}\n";
echo "Critical: {$stats['by_level']['critical']}\n";
```

### Broadcast Error Alerts

```php
// Broadcast critical error to cluster
$errorManager->logCriticalError(
    'Database failover triggered',
    ['old_master' => 'db1', 'new_master' => 'db2'],
    true  // broadcast to other nodes
);

// Listen for alerts from other nodes
$alerts = $errorManager->listenForErrorAlerts(10, 100);
foreach ($alerts as $alert) {
    echo "Alert from {$alert['source_node']}: {$alert['message']}\n";
}
```

### Admin Notifications

```php
// Notify admin of threshold breach
$errorManager->notifyAdmin(
    'Critical error threshold reached',
    'The application has logged 50+ critical errors in the past hour.',
    [
        'environment' => 'production',
        'server' => gethostname(),
        'action_required' => true
    ]
);
```

## Error Deduplication

ErrorManager uses hash-based deduplication to prevent key avalanche:

1. **Stable ID Generation**: `md5(message + json_encode(context))`
2. **Rate Limiting**: Minimum 60 seconds between updates for same error
3. **Occurrence Tracking**: Increments `occurrence_count` instead of creating new entries
4. **Auto-Trim**: Removes oldest errors when `max_errors` exceeded

## Key Naming Convention

Keys are namespaced for multi-tenant isolation:

- **Error Data**: `error:{site_id}:{node_id}:{error_id}`
- **Error List**: `errors:{site_id}:{node_id}` (sorted set or array)
- **Error Count**: `error_count:{site_id}:{node_id}:{level}`
- **Rate Limit**: `error_rate:{site_id}:{node_id}:{error_id}`

## Capability Vector

```php
[
    'errors' => 1.0,   // Primary capability
    'logging' => 0.9,  // Logging operations
    'security' => 0.2, // Security event tracking
    'cache' => 0.1     // Minimal cache dependency
]
```

## Error Level Mapping

| PHP Level | Log Level | TTL (default) |
|-----------|-----------|---------------|
| E_ERROR, E_CORE_ERROR | error | 1 week |
| E_WARNING, E_CORE_WARNING | warning | 1 day |
| E_NOTICE, E_USER_NOTICE | notice | 6 hours |
| E_STRICT, E_DEPRECATED | info | 1 hour |
| E_PARSE, E_COMPILE_ERROR | critical | 30 days |

---

*Last Updated: January 2026*
