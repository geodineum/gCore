# CacheManager Documentation

## Overview

The CacheManager provides high-performance distributed caching with zero local state for the gCore framework. Built on ValKey/Redis, it offers advanced features including distributed locking, transactions, streams, batch operations, and script-based atomic operations through a modular architecture.

## Core Features

- **Distributed Caching**: Fast, distributed cache with zero local state
- **Modular Script System**: Specialized script components for different operations
- **Connection Pooling**: Efficient connection management with adaptive backoff
- **Batch Operations**: High-performance multi-key operations
- **Streams Support**: Real-time data processing with streams
- **Distributed Locks**: Cluster-safe resource locking
- **Transactions**: Atomic multi-operation processing
- **Rate Limiting**: Throttling and protection
- **Circuit Breaking**: Fault tolerance with exponential backoff

## Architecture

The CacheManager architecture has been enhanced with a modular script system:

### Core Components

- **CacheManager**: Main manager class implementing ModuleInterface
- **ValKeyStorage**: Low-level storage operations
- **ValKeyConnectionPool**: Connection management with pooling

### Modular Script System

- **CacheScriptsBase**: Base class for all script modules
- **CacheScriptsUtils**: Common utilities and helper functions
- **CacheScriptsCoreOperations**: Basic key operations (GET, SET, DELETE)
- **CacheScriptsHashOperations**: Hash-related operations
- **CacheScriptsBatchOperations**: Multi-key operations (MGET, MSET, MDEL)
- **CacheScriptsLockManager**: Distributed locking functionality
- **CacheScriptsTransactionManager**: Distributed transaction management
- **CacheScriptsPubSub**: Publish/subscribe operations
- **CacheScriptsStreamOperations**: Stream operations
- **CacheScriptsMonitoring**: Monitoring and maintenance
- **CacheScriptsGroupManager**: Group management
- **CacheScriptsSiteManager**: Site isolation and management

## Initialization

```php
// Get CacheManager instance
$cacheManager = gcore_get_cache_manager();

// Initialize with configuration
$cacheManager->initialize([
    // ValKey/Redis configuration
    'host' => '127.0.0.1',       // ValKey host
    'port' => 6379,              // ValKey port
    'auth' => 'password',        // Authentication password
    'database' => 0,             // Database index
    'timeout' => 2.0,            // Connection timeout
    'prefix' => 'cache_',        // Key prefix
    'retry_interval' => 100,     // Retry interval (ms)
    'persistent' => true,        // Use persistent connection
    
    // Connection pooling
    'connection_pool_size' => 10,  // Maximum connections
    'min_connections' => 2,        // Minimum persistent connections
    
    // Cache configuration
    'default_ttl' => 3600,       // Default TTL (seconds)
    'serialize' => true,         // Serialize values
    
    // Performance options
    'batch_size' => 100,         // Batch operation size
    'script_retry_attempts' => 3, // Script retry attempts
    'script_retry_delay' => 100, // Script retry delay (ms)
    
    // Feature toggles
    'streams' => [
        'enabled' => true,       // Enable streams
        'auto_create' => true,   // Auto-create streams
        'max_len' => 10000       // Max stream length
    ],
    
    // Debugging
    'debug' => false,            // Debug mode
    
    // Trait configuration
    'traits' => [
        'StreamCapabilities' => ['enabled' => true]
    ]
]);
```

## Basic Usage

### Simple Cache Operations

```php
// Set a value with TTL
$cacheManager->set('user:123', $userData, 3600);

// Get a value with default fallback
$userData = $cacheManager->get('user:123', ['name' => 'Guest']);

// Check if key exists
$exists = $cacheManager->has('user:123');

// Delete a key
$cacheManager->delete('user:123');

// Increment/decrement counters
$newValue = $cacheManager->increment('visits', 1);
$newValue = $cacheManager->decrement('remaining', 1);
```

### Multiple Operations

```php
// Get multiple values efficiently
$values = $cacheManager->getMultiple(['key1', 'key2', 'key3']);

// Set multiple values in one operation
$cacheManager->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2',
    'key3' => 'value3'
], 3600);

// Delete multiple keys
$cacheManager->deleteMultiple(['key1', 'key2', 'key3']);
```

### Hash Operations

```php
// Set a hash field
$cacheManager->hashSet('user:123:data', 'email', 'user@example.com');

// Get a hash field
$email = $cacheManager->hashGet('user:123:data', 'email');

// Get multiple hash fields
$fields = $cacheManager->hashMultipleGet('user:123:data', ['email', 'name', 'status']);

// Set multiple hash fields
$cacheManager->hashMultipleSet('user:123:data', [
    'email' => 'user@example.com',
    'name' => 'John Doe',
    'status' => 'active'
]);

// Delete hash field
$cacheManager->hashDelete('user:123:data', 'status');

// Get all hash fields
$userData = $cacheManager->hashGetAll('user:123:data');
```

## Advanced Features

### Script System

The modular script system allows for atomic, efficient operations:

```php
// Execute a core script
$value = $cacheManager->runScript(
    'GET',                // Script name
    ['user:profile:123']  // Keys
);

// Execute a batch operation script
$values = $cacheManager->runScript(
    'MGET',                            // Script name
    ['batch:keys'],                    // Keys
    [json_encode(['key1', 'key2'])]    // Arguments
);

// Execute a transaction script
$result = $cacheManager->runScript(
    'TRANSACTION_EXEC',                // Script name
    ['transaction:123'],               // Keys
    [json_encode([                     // Arguments
        ['SET', 'key1', 'value1'],
        ['INCR', 'counter'],
        ['EXPIRE', 'key1', 3600]
    ])]
);
```

### Connection Pooling

Connection pooling improves performance by reusing connections:

```php
// Get the connection pool
$pool = $cacheManager->getConnectionPool();

// Execute with a connection from the pool
$result = $pool->executeWithConnection(function($redis) {
    // Use the connection
    return $redis->get('some-key');
});

// Execute with retry logic
$result = $pool->executeWithRetry(
    function($redis) {
        return $redis->set('key', 'value');
    },
    3,    // Max retries
    100   // Retry delay in ms
);

// Get connection pool stats
$stats = $pool->getConnectionStats();
```

### Distributed Locking

Safely coordinate distributed operations:

```php
// Acquire a lock
if ($cacheManager->lock('resource_lock', 30)) {
    try {
        // Perform operations that require exclusive access
        processResource();
    } finally {
        // Always release the lock when done
        $cacheManager->unlock('resource_lock');
    }
}
```

### Streams (with StreamCapabilities Trait)

Process real-time data streams:

```php
// Add entry to stream
$entryId = $cacheManager->streamAdd('events', [
    'type' => 'user_login',
    'user_id' => 123,
    'timestamp' => time()
]);

// Create consumer group
$created = $cacheManager->streamCreateGroup('events', 'processors', '0');

// Read from stream with a consumer group
$entries = $cacheManager->streamReadGroup(
    'events',       // Stream
    'processors',   // Group
    'worker1',      // Consumer
    10              // Count
);

// Process entries
foreach ($entries as $entry) {
    // Process entry
    processEvent($entry['data']);
    
    // Acknowledge processing
    $cacheManager->streamAck('events', 'processors', $entry['id']);
}

// Get pending entries
$pending = $cacheManager->streamPending('events', 'processors');

// Claim abandoned entries
$claimed = $cacheManager->streamClaim(
    'events',       // Stream
    'processors',   // Group
    'worker2',      // New consumer
    60000,          // Min idle time (ms)
    [$messageId]    // Message IDs to claim
);
```

### Pub/Sub Messaging

Real-time messaging between components:

```php
// Publish a message
$recipients = $cacheManager->publish('channel', json_encode([
    'event' => 'user.created',
    'data' => ['id' => 123, 'name' => 'John']
]));

// Subscribe to channel (blocking operation)
$cacheManager->subscribe('channel', function($message) {
    $data = json_decode($message, true);
    if ($data['event'] === 'user.created') {
        // Handle user creation event
    }
});
```

### Circuit Breaking with Adaptive Backoff

Prevent cascading failures with circuit breaking:

```php
// Get the connection pool with adaptive backoff
$pool = $cacheManager->getConnectionPool();

// Execute with adaptive backoff
try {
    $result = $pool->executeWithRetry(function($redis) {
        return $redis->get('key');
    });
} catch (\Exception $e) {
    // Circuit may be open after multiple failures
    if ($pool->isCircuitOpen('redis_get')) {
        // Use fallback mechanism
        $result = getFallbackValue();
    }
}

// Check circuit status
$status = $pool->getCircuitStatus('redis_get');
```

## API Digest

### Main CacheManager Class

- `getInstance(): CacheManager` - Returns the singleton instance of the CacheManager.
- `initialize(array $config = []): void` - Initializes the cache system with the given configuration.
- `get(string $key, mixed $default = null): mixed` - Retrieves a value from cache with an optional default.
- `set(string $key, mixed $value, int $ttl = null): bool` - Stores a value in cache with optional TTL.
- `delete(string $key): bool` - Deletes a value from cache.
- `has(string $key): bool` - Checks if a value exists in cache.
- `clear(): bool` - Clears all cache entries.
- `getMultiple(array $keys, mixed $default = null): array` - Retrieves multiple values from cache.
- `setMultiple(array $values, int $ttl = null): bool` - Stores multiple values in cache.
- `deleteMultiple(array $keys): bool` - Deletes multiple values from cache.
- `increment(string $key, int $amount = 1, int $ttl = null): int` - Increments a numeric value in cache.
- `decrement(string $key, int $amount = 1, int $ttl = null): int` - Decrements a numeric value in cache.
- `remember(string $key, callable $callback, int $ttl = null): mixed` - Retrieves from cache or computes and stores value.
- `lock(string $name, int $timeout = 10): bool` - Acquires a distributed lock.
- `unlock(string $name): bool` - Releases a distributed lock.
- `hashGet(string $key, string $field): mixed` - Gets a field from a hash.
- `hashSet(string $key, string $field, mixed $value): bool` - Sets a field in a hash.
- `hashDelete(string $key, string $field): bool` - Deletes a field from a hash.
- `hashGetAll(string $key): array` - Gets all fields from a hash.
- `hashExists(string $key, string $field): bool` - Checks if a field exists in a hash.
- `hashMultipleGet(string $key, array $fields): array` - Gets multiple fields from a hash.
- `hashMultipleSet(string $key, array $fields): bool` - Sets multiple fields in a hash.
- `publish(string $channel, string $message): int` - Publishes a message to a channel.
- `subscribe(string $channel, callable $callback): void` - Subscribes to messages on a channel.
- `runScript(string $name, array $keys = [], array $args = []): mixed` - Runs a ValKey/Redis Lua script.
- `getConnectionPool(): ValKeyConnectionPool` - Gets the ValKey connection pool.
- `getStatus(): array` - Returns the current status of the cache system.

### StreamCapabilities Trait

- `streamExists(string $stream): bool` - Checks if a stream exists.
- `streamCreate(string $stream): bool` - Creates a new stream.
- `streamAdd(string $stream, array $data, string $id = '*'): string` - Adds an entry to a stream.
- `streamDelete(string $stream, string $id): bool` - Deletes an entry from a stream.
- `streamGetRange(string $stream, string $start = '0', string $end = '+', int $count = 100): array` - Gets a range of entries from a stream.
- `streamLength(string $stream): int` - Gets the number of entries in a stream.
- `streamTrim(string $stream, int $maxLen): int` - Trims a stream to a maximum length.
- `streamCreateGroup(string $stream, string $group, string $id = '$'): bool` - Creates a consumer group for a stream.
- `streamReadGroup(string $stream, string $group, string $consumer, int $count = 1, int $block = 0): array` - Reads messages from a stream as a consumer group.
- `streamAck(string $stream, string $group, string $id): int` - Acknowledges processing of a message.
- `streamPending(string $stream, string $group): array` - Gets pending messages for a consumer group.
- `streamClaim(string $stream, string $group, string $consumer, int $minIdleTime, array $ids): array` - Claims pending messages for a consumer.
- `streamInfo(string $stream): array` - Gets information about a stream.

### ValKeyConnectionPool

- `getInstance(): ValKeyConnectionPool` - Returns the singleton instance of the connection pool.
- `initialize(array $config = []): void` - Initializes the connection pool with configuration.
- `getConnection(?string $preferredNode = null): ?\Redis` - Gets a connection from the pool.
- `releaseConnection(\Redis $connection): void` - Releases a connection back to the pool.
- `executeWithConnection(callable $callback, ?string $preferredNode = null): mixed` - Executes a callback with a connection from the pool.
- `executeWithRetry(callable $callback, int $maxRetries = 3, int $retryDelay = 100): mixed` - Executes a callback with retry logic.
- `invalidateConnection(\Redis $connection): void` - Marks a connection as invalid.
- `getConnectionStats(): array` - Gets statistics about the connection pool.
- `isCircuitOpen(string $context): bool` - Checks if the circuit breaker is open for a specific context.
- `getPoolSize(): int` - Gets the maximum size of the connection pool.

### CacheScripts Classes

- `CacheScriptsBase::registerScript(string $name, string $script): bool` - Registers a Lua script with the ValKey/Redis server.
- `CacheScriptsBase::executeScript(string $name, array $keys = [], array $args = []): mixed` - Executes a registered Lua script.
- `CacheScriptsCoreOperations::get(string $key): mixed` - Gets a value using a Lua script.
- `CacheScriptsCoreOperations::set(string $key, mixed $value, ?int $ttl = null): bool` - Sets a value using a Lua script.
- `CacheScriptsBatchOperations::mget(array $keys): array` - Gets multiple values using a Lua script.
- `CacheScriptsBatchOperations::mset(array $keyValues, ?int $ttl = null): bool` - Sets multiple values using a Lua script.
- `CacheScriptsLockManager::acquireLock(string $lockName, string $token, int $ttl): bool` - Acquires a distributed lock using a Lua script.
- `CacheScriptsTransactionManager::executeTransaction(array $operations): array` - Executes a transaction using a Lua script.
- `CacheScriptsStreamOperations::streamAdd(string $stream, array $data, string $id = '*'): string` - Adds to a stream using a Lua script.

## Performance Optimization

### Batch Operations

Use batch operations for better performance:

```php
// Inefficient: Multiple individual operations
foreach ($keys as $key) {
    $value = $cacheManager->get($key);
}

// Efficient: Single batch operation
$values = $cacheManager->getMultiple($keys);
```

### Connection Pooling

Configure connection pooling for optimal performance:

```php
$cacheManager->initialize([
    'connection_pool_size' => 20,        // More connections for high traffic
    'min_connections' => 5,              // Keep min connections open
    'connection_idle_timeout' => 60,     // Close idle connections after 60s
    'adaptive_pool_sizing' => true       // Dynamically adjust pool size
]);
```

### Script-Based Operations

Use script-based operations for complex atomic operations:

```php
// Multiple operations with race condition risk
$count = $cacheManager->get('counter');
$count++;
$cacheManager->set('counter', $count);

// Atomic operation using script
$count = $cacheManager->runScript('INCR', ['counter']);
```

### Intelligent Retry Logic

Configure retry behavior for optimal resilience:

```php
$cacheManager->initialize([
    'retry_interval' => 100,     // Initial retry delay in ms
    'retry_jitter' => 0.1,       // Add randomness to prevent thundering herd
    'max_retries' => 3,          // Maximum retry attempts
    'circuit_threshold' => 5,    // Failures before circuit opens
    'circuit_reset' => 30        // Seconds to keep circuit open
]);
```

## Best Practices

1. **Use Appropriate TTLs**
   - Set realistic expiration times based on data volatility
   - Use shorter TTLs for frequently changing data
   - Use longer TTLs for static content

2. **Implement Key Naming Conventions**
   - Use colon-separated namespaces: `type:id:field`
   - Include entity type in keys
   - Be consistent with naming patterns

3. **Handle Cache Failures Gracefully**
   - Always have fallback mechanisms
   - Use circuit breakers to prevent cascade failures
   - Log cache failures for monitoring

4. **Use Batch Operations**
   - Group related operations into batches
   - Use multi-key operations instead of loops
   - Consider using transactions for related updates

5. **Monitor Cache Performance**
   - Track hit/miss ratios
   - Monitor memory usage
   - Set up alerts for abnormal patterns

6. **Use Connection Pooling Effectively**
   - Configure pool size based on expected traffic
   - Use adaptive pool sizing for variable loads
   - Monitor connection pool metrics

## Troubleshooting

### Common Issues

1. **Connection Failures**
   - Verify ValKey/Redis server is running
   - Check network connectivity
   - Ensure authentication credentials are correct
   - Check for firewall restrictions

2. **Performance Issues**
   - Monitor connection pool utilization
   - Check for key hotspots
   - Verify appropriate batch operations are used
   - Examine script execution times

3. **Memory-Related Issues**
   - Monitor ValKey/Redis memory usage
   - Implement key eviction policies
   - Ensure appropriate TTLs are set
   - Consider data compression for large values

4. **Script Execution Failures**
   - Verify script syntax
   - Check script load performance
   - Monitor script execution times
   - Use script caching for better performance

## Conclusion

The CacheManager provides a reliable, high-performance distributed caching solution for gCore applications. With its modular script system, connection pooling, and feature set, it enables efficient data caching, processing, and coordination in distributed environments.

---

*Updated: March 2025*