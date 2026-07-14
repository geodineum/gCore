# StateManager

**Version:** 2.0.0
**Namespace:** `gCore\Modules\Managers\Base\StateManager`
**Implements:** `ModuleInterface`, `ArrayAccess`

## Overview

StateManager provides centralized state management with real-time synchronization, history tracking, and performance optimization. It follows a framework-agnostic design with conditional WordPress support.

### Key Features

- **Immutable state transitions** - State changes are tracked and observable
- **Observable state patterns** - Register observers for state change notifications
- **Transactional integrity** - Support for transaction-based state modifications
- **History-aware operations** - Configurable state history depth
- **Multi-tenant isolation** - State isolated via site_id/node_id key prefixing
- **ArrayAccess interface** - Direct array-style access to state values

## Architecture

StateManager follows gCore's "stateless yet stateful" architecture:

1. **In-Memory State**: Current request state stored in `$state` array
2. **Persistent State**: Optional persistence to ValKey via CacheManager
3. **Observer Pattern**: Callbacks notified on state changes
4. **Transaction Support**: Batch state modifications with rollback capability

### Capability Vector

```php
[
    'state_management' => 1.0,
    'transactions' => 0.95,
    'observability' => 0.9,
    'history' => 0.85,
    'persistence' => 0.9
]
```

## Installation & Initialization

### Via gCore (Recommended)

```php
$core = gCore::getInstance();
$stateManager = $core->getService('StateManager');
```

### Direct Initialization

```php
$stateManager = StateManager::getInstance();
$stateManager->initialize([
    'history_depth' => 50,
    'sync_interval' => 100,
    'persistence_driver' => 'cache',
    'site_id' => 'mysite',
    'node_id' => 'node1'
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `history_depth` | int | 50 | Maximum number of state history entries |
| `sync_interval` | int | 100 | Sync interval in milliseconds |
| `persistence_driver` | string | 'cache' | Storage driver ('cache' or 'memory') |
| `compression_threshold` | int | 1024 | Bytes threshold for compression |
| `debug_mode` | bool | false | Enable debug logging |
| `site_id` | string | 'default' | Multi-tenant site identifier |
| `node_id` | string | 'node1' | Multi-tenant node identifier |

## Public API

### Core State Operations

#### `setState(string $key, $value): void`

Set a state value and notify observers.

```php
$stateManager->setState('user.preferences', ['theme' => 'dark']);
$stateManager->setState('cart.items', []);
```

#### `getState(string $key, $default = null): mixed`

Retrieve a state value or default if not found.

```php
$theme = $stateManager->getState('user.preferences.theme', 'light');
$items = $stateManager->getState('cart.items', []);
```

#### `removeState(string $key): void`

Remove a state value.

```php
$stateManager->removeState('temporary.data');
```

### Observer Pattern (gNode Integration)

StateManager integrates with gNode pub/sub for distributed state observation.

#### `subscribe(string $key, callable $callback, ?string $observerId = null): string`

Subscribe to state changes for a key. Returns observer ID.

```php
$observerId = $stateManager->subscribe('user.cart', function($key, $value, $oldValue) {
    // React to cart changes
    updateCartTotal($value);
});

// With custom ID
$stateManager->subscribe('user.settings', $callback, 'settings-observer');
```

#### `unsubscribe(string $key, string $observerId): bool`

Remove an observer subscription.

```php
$stateManager->unsubscribe('user.cart', $observerId);
```

### Transaction Support (gNode Integration)

Atomic state modifications with rollback capability using gNode transactions.

#### `beginTransaction(int $timeout = 300): string`

Start a new transaction. Returns transaction ID.

```php
$txId = $stateManager->beginTransaction(60); // 60 second timeout
```

#### `commitTransaction(): bool`

Commit all changes in the current transaction.

```php
try {
    $stateManager->beginTransaction();
    $stateManager->setState('order.status', 'processing');
    $stateManager->setState('inventory.item_123', $newCount);
    $stateManager->commitTransaction();
} catch (\Exception $e) {
    $stateManager->rollbackTransaction();
}
```

#### `rollbackTransaction(): bool`

Discard all changes in the current transaction.

```php
$stateManager->rollbackTransaction();
```

### History Tracking (gNode Streams)

State change history is stored in gNode streams for audit and debugging.

#### `getHistory(?string $key = null, int $limit = 50): array`

Retrieve state change history.

```php
// Get all history
$history = $stateManager->getHistory(null, 100);

// Get history for specific key
$cartHistory = $stateManager->getHistory('user.cart', 20);

// Returns: [['key' => 'user.cart', 'value' => [...], 'old_value' => [...], 'timestamp' => ...], ...]
```

### State Validation

#### `registerValidator(string $key, callable $validator, ?string $validatorId = null): string`

Register a validator function for state changes. Returns validator ID.

```php
$validatorId = $stateManager->registerValidator('user.age', function($value, $key) {
    if (!is_int($value) || $value < 0 || $value > 150) {
        throw new \InvalidArgumentException("Invalid age: $value");
    }
    return true;
});
```

#### `removeValidator(string $key, string $validatorId): bool`

Remove a validator.

```php
$stateManager->removeValidator('user.age', $validatorId);
```

### Middleware

#### `addMiddleware(callable $middleware, int $priority = 100): string`

Add middleware to process state changes. Returns middleware ID.

```php
$middlewareId = $stateManager->addMiddleware(function($key, $value, $next) {
    // Log all state changes
    error_log("State change: $key");
    return $next($key, $value);
}, 50); // Lower priority = runs first
```

#### `removeMiddleware(string $middlewareId): bool`

Remove middleware.

```php
$stateManager->removeMiddleware($middlewareId);
```

### ArrayAccess Interface

StateManager implements `ArrayAccess` for convenient array-style access:

```php
// Set state
$stateManager['user.name'] = 'John';

// Get state
$name = $stateManager['user.name'];

// Check existence
if (isset($stateManager['user.name'])) {
    // ...
}

// Remove state
unset($stateManager['user.name']);
```

### State Persistence

#### `restoreState(): void`

Restore state from cache (called automatically in WordPress via `wp_loaded` hook).

```php
$stateManager->restoreState();
```

#### `persistState(): void`

Persist current state to cache (called automatically in WordPress via `shutdown` hook).

```php
$stateManager->persistState();
```

### Module Interface Methods

#### `getInstance(): ModuleInterface`

Get singleton instance.

```php
$stateManager = StateManager::getInstance();
```

#### `initialize(array $config = []): void`

Initialize with configuration.

```php
$stateManager->initialize([
    'debug_mode' => true,
    'history_depth' => 100
]);
```

#### `isInitialized(): bool`

Check initialization status.

```php
if ($stateManager->isInitialized()) {
    // Ready to use
}
```

#### `getConfig(): array`

Get current configuration.

```php
$config = $stateManager->getConfig();
```

#### `updateConfig(array $config): void`

Update configuration at runtime.

```php
$stateManager->updateConfig(['debug_mode' => true]);
```

#### `getStatus(): array`

Get status information.

```php
$status = $stateManager->getStatus();
// Returns: initialized, mode, storage_type, state_count, observer_count,
//          history_depth, in_transaction, memory_usage, metrics, site_id, node_id, framework
```

## Usage Examples

### Basic State Management

```php
$state = StateManager::getInstance();
$state->initialize(['site_id' => 'shop']);

// Store user session data
$state->setState('session.user_id', 12345);
$state->setState('session.cart', ['item1', 'item2']);

// Retrieve data
$userId = $state->getState('session.user_id');
$cart = $state->getState('session.cart', []);

// Using array access
$state['session.locale'] = 'en_US';
echo $state['session.locale']; // 'en_US'
```

### Multi-Tenant Isolation

```php
// Site A
$stateA = StateManager::getInstance();
$stateA->initialize(['site_id' => 'site_a', 'node_id' => 'node1']);
$stateA->setState('config', ['theme' => 'blue']);

// State is isolated - each site has its own namespace
// Key format: state_data_site_a_node1
```

### Checking Status

```php
$status = $stateManager->getStatus();

if ($status['mode'] === 'free_tier') {
    // Running without the gNode integration
}

echo "State entries: " . $status['state_count'];
echo "Memory usage: " . $status['memory_usage'];
```

## Integration Points

### Dependencies

| Manager | Relationship | Purpose |
|---------|--------------|---------|
| **CacheManager** | Optional | Enables state persistence to ValKey |
| **ErrorManager** | Optional | Structured error logging |
| **gCore** | Parent | Service discovery and lifecycle |

### WordPress Integration

When running in WordPress, StateManager automatically:
- Restores state on `wp_loaded` hook
- Persists state on `shutdown` hook
- Registers debug handlers on `admin_init` (when debug mode enabled)

```php
// WordPress hooks are automatically registered
add_action('wp_loaded', [$this, 'restoreState']);
add_action('shutdown', [$this, 'persistState']);
```

### gNode Integration

StateManager registers with gNode for capability-based service discovery:

```php
$this->gNodeClient->registerService(
    'StateManager',
    $this->capabilityVector,
    [
        'type' => 'manager',
        'tier' => 'TOOL',
        'priority' => '350'
    ]
);
```

## Key Namespacing

State is isolated using the pattern:
```
state_data_{site_id}_{node_id}
```

Example keys:
- `state_data_default_node1` - Default namespace
- `state_data_shop_prod1` - Shop site on prod1 node
- `state_data_blog_node2` - Blog site on node2

## Error Handling

StateManager gracefully handles missing dependencies:

```php
// CacheManager unavailable - state is in-memory only
// ErrorManager unavailable - errors fall back to error_log()
// gCore unavailable - runs in standalone mode
```

## Performance Metrics

StateManager tracks internal metrics:

```php
$metrics = $stateManager->getStatus()['metrics'];
// state_updates: Number of state modifications
// cache_hits: Successful cache retrievals
// cache_misses: Cache misses requiring computation
// transaction_count: Number of transactions
// observer_notifications: Observer callbacks triggered
// start_time: Initialization timestamp
```

## Best Practices

1. **Use namespaced keys**: `user.preferences.theme` instead of `theme`
2. **Initialize early**: Call `initialize()` during bootstrap
3. **Configure persistence**: Set `persistence_driver` based on your needs
4. **Use site_id/node_id**: For multi-tenant deployments
5. **Monitor metrics**: Check `getStatus()` for performance insights

## Troubleshooting

### State Not Persisting

1. Verify CacheManager is available
2. Check ValKey connection
3. Ensure `persistence_driver` is set to 'cache'

### Memory Issues

1. Reduce `history_depth` configuration
2. Clean up large state entries
3. Check `compression_threshold` setting

### Multi-Tenant Conflicts

1. Verify unique `site_id` per tenant
2. Check `node_id` configuration
3. Review key namespacing in cache
