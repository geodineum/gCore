# CacheManager Documentation

## Overview

CacheManager provides distributed caching capabilities with multi-site isolation for the gCore framework. Built on ValKey/Redis, it offers high-performance caching with support for gNode integration for advanced features including batch operations, content minification, and broadcast invalidation.

**Namespace**: `gCore\Modules\Managers\Base\CacheManager`
**Implements**: `ModuleInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)

## Architecture

CacheManager operates in two modes:

1. **gNode Mode (enhanced)**: Full-featured caching with FCALL-based Lua functions, batch operations, content minification, broadcast invalidation, and centralized metrics
2. **Default Tier Mode**: Basic caching using StorageInterface adapters (WordPress Transients, Memory)

### Storage Hierarchy

```
gNode-Client (enhanced)
    ├── Shared gNodeStorageAdapter (pooled connections)
    ├── Direct FCALL to ValKey Lua functions
    └── Key-Based Lua operations

Default Tier
    ├── WordPress Transients (if available)
    ├── APCu (future)
    └── Memory (fallback)
```

## Initialization

```php
// Get CacheManager instance via gCore
$cacheManager = gCore::getService('CacheManager');

// Or using helper function
$cacheManager = gcore_get_cache_manager();

// Configuration (passed during gCore initialization)
$config = [
    'storage' => [
        'host' => '127.0.0.1',
        'port' => 47445,           // gNode standard ValKey port
        'timeout' => 2.0,
        'prefix' => 'cache_',
        'auth' => null
    ],
    'default_ttl' => 3600,          // 1 hour
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'use_gnode' => true,              // Enable gNode integration
    'gnode_client' => $gNodeClient,     // gNode client instance
    'gnode_storage_adapter' => $adapter // Shared storage adapter
];
```

## Public API Reference

### Core Cache Operations

#### `getInstance(): ModuleInterface`
Returns the singleton instance of CacheManager.

#### `initialize(array $config = []): void`
Initializes the cache system with configuration.
- **Throws**: `InitializationException` if initialization fails

#### `set(string $key, mixed $value, int $ttl = 0): bool`
Store a value in cache.
- **Parameters**:
  - `$key`: Cache key (no spaces allowed)
  - `$value`: Value to cache (any serializable type)
  - `$ttl`: Time to live in seconds (0 = no expiration)
- **Returns**: `true` on success
- **Throws**: `ValidationException` if key is invalid

#### `get(string $key): mixed`
Retrieve a value from cache.
- **Parameters**:
  - `$key`: Cache key
- **Returns**: Cached value or `null` if not found

#### `delete(string $key): bool`
Remove a value from cache.
- **Returns**: `true` on success

#### `exists(string $key): bool`
Check if a key exists in cache.
- **Returns**: `true` if key exists

#### `increment(string $key, int $by = 1): int|false`
Atomically increment a numeric value.
- **Returns**: New value or `false` on failure

#### `decrement(string $key, int $by = 1): int|false`
Atomically decrement a numeric value.
- **Returns**: New value or `false` on failure

#### `setNx(string $key, mixed $value, int $ttl = 0): bool`
Set value only if key doesn't exist (atomic).
- **Returns**: `true` if set, `false` if key already exists

#### `clear(): bool`
Clear all cache entries for the current site/node.
- **Returns**: `true` on success

### Multi-Key Operations

#### `getMultiple(array $keys): array`
Retrieve multiple values efficiently.
- **Returns**: Associative array of key => value pairs

#### `setMultiple(array $items, int $ttl = 0): bool`
Store multiple values in one operation.
- **Parameters**:
  - `$items`: Associative array of key => value pairs

#### `deleteMultiple(array $keys): bool`
Delete multiple keys in one operation.

### Batch Operations (gNode-enhanced)

These operations use gNode's `executeBatch()` to batch many keys into a single round-trip.

#### `batchSet(array $items): array`
Batch set multiple values in a single round-trip.
- **Parameters**:
  - `$items`: Array of `[key => ['value' => mixed, 'ttl' => int]]`
- **Returns**: Results array with success status per key
- **Throws**: `StorageException` if gNode not available

#### `batchGet(array $keys): array`
Batch get multiple values.
- **Returns**: Associative array (null for missing keys)
- **Throws**: `StorageException` if gNode not available

#### `batchDelete(array $keys): array`
Batch delete multiple keys.
- **Returns**: Results array with success status per key
- **Throws**: `StorageException` if gNode not available

### Content Operations (gNode-enhanced)

#### `storeContent(string $key, string $content, string $contentType = 'text/html', bool $minify = true, int $ttl = 0): array`
Store content with automatic minification and compression.
- **Parameters**:
  - `$contentType`: MIME type (text/html, text/css, application/javascript)
  - `$minify`: Enable server-side minification
- **Returns**: Result with success status and metadata

#### `retrieveContent(string $key): ?array`
Retrieve stored content with automatic decompression.
- **Returns**: Content data array or null

#### `storeTemplate(string $id, string $content, array $dependencies = [], array $variables = [], ?int $ttl = null): array`
Store template fragment with dependency tracking.

#### `storeAssetBundle(string $bundleId, array $assets, string $bundleType = 'mixed', bool $minify = true, ?int $ttl = null): array`
Store optimized asset bundle.

### Broadcast Invalidation (gNode-enhanced)

Enables distributed cache coherence across multiple servers.

#### `broadcastInvalidate(array $keys, string $reason = 'manual'): string|false`
Broadcast cache invalidation to all nodes.
- **Returns**: Message ID on success, `false` on failure

#### `broadcastClearAll(string $reason = 'manual'): string|false`
Broadcast clear all cache message.

#### `listenForInvalidations(int $count = 10, int $blockMs = 100): array`
Listen for invalidation broadcasts from other nodes.
- **Returns**: Array of processed messages

#### `invalidate(array $keys, bool $broadcast = true): bool`
Invalidate locally and optionally broadcast.

### Connection Management

#### `enableNativeMode(): bool`
Enable native RESP3 mode for lower protocol overhead.
- **Throws**: `StorageException` if gNode not available

#### `disableNativeMode(): bool`
Disable native RESP3 mode.

#### `isNativeMode(): bool`
Check if native mode is enabled.

### Data Validation (gNode-enhanced)

#### `getFormatManager(): ?FormatManager`
Get format manager for data validation.

#### `registerFormat(string $formatId, array $schema): bool`
Register a custom data format for validation.

#### `validateData(string $formatId, mixed $data): bool`
Validate data against registered format.
- **Throws**: `ValidationException` if invalid

#### `setWithValidation(string $key, mixed $value, string $formatId, int $ttl = 0): bool`
Set value with format validation.

#### `batchSetWithValidation(array $items): array`
Batch set with format validation for each item.

#### `getRegisteredFormats(): array`
Get all registered format identifiers.

#### `getFormatSchema(string $formatId): ?array`
Get format schema definition.

### Status & Metrics

#### `getMetrics(): array`
Get cache metrics including hits, misses, sets, deletes, hit ratio, and mode.

#### `getGNodeStats(): array`
Get stats combining local and gNode daemon metrics.

#### `getStatus(): array`
Get full status including initialization state, storage type, mode, and capabilities.

#### `getCapabilityVector(): array`
Get capability vector for geometric service discovery.

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getKeys(string $pattern): array`
Get keys matching a pattern.

## Usage Examples

### Basic Caching

```php
$cache = gCore::getService('CacheManager');

// Simple set/get
$cache->set('user:123', ['name' => 'John', 'email' => 'john@example.com'], 3600);
$user = $cache->get('user:123');

// Counter operations
$cache->set('visitors', 0);
$count = $cache->increment('visitors');

// Cache-aside pattern
$data = $cache->get('expensive_query');
if ($data === null) {
    $data = performExpensiveQuery();
    $cache->set('expensive_query', $data, 300);
}
```

### Multi-Key Operations

```php
// Batch read
$keys = ['user:1', 'user:2', 'user:3'];
$users = $cache->getMultiple($keys);

// Batch write
$cache->setMultiple([
    'config:theme' => 'dark',
    'config:lang' => 'en',
    'config:timezone' => 'UTC'
], 86400);
```

### gNode-enhanced Features

```php
// Batch (single round-trip)
$results = $cache->batchSet([
    'page:home' => ['value' => $homeContent, 'ttl' => 3600],
    'page:about' => ['value' => $aboutContent, 'ttl' => 3600]
]);

// Content with minification
$cache->storeContent('css:main', $cssContent, 'text/css', true, 86400);

// Broadcast invalidation
$cache->invalidate(['page:home', 'page:about'], true);
```

## Key Naming Convention

Keys are automatically namespaced based on mode:

- **gNode Mode**: `{site_id}:cache:{key}`
- **Legacy Mode**: `cache:{site_id}:{node_id}:{key}`

Best practices:
- Use colon-separated namespaces: `type:id:field`
- No spaces in keys
- Keep keys under 250 characters

## Error Handling

```php
try {
    $cache->set('key', $value);
} catch (ValidationException $e) {
    // Invalid key format
} catch (StorageException $e) {
    // Storage/connection failure
}
```

## Capability Vector

```php
[
    'cache' => 1.0,    // Primary capability
    'storage' => 0.8,  // Storage operations
    'errors' => 0.2,   // Error handling integration
    'logging' => 0.3   // Logging integration
]
```

---

*Last Updated: January 2026*
