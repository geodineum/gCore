# VersionManager

**Version:** 2.0.0
**Namespace:** `gCore\Modules\Managers\Base\VersionManager`
**Implements:** `ModuleInterface`

## Overview

VersionManager provides version tracking and cache invalidation functionality. It manages group-based version numbers that enable automatic cache busting when content changes.

### Key Features

- **Group-based Versioning** - Track versions per cache group
- **Automatic Invalidation** - Increment versions to bust caches
- **Versioned Key Generation** - Generate cache keys with version prefixes
- **Multi-tenant Isolation** - Version namespacing via site_id/node_id
- **WordPress Integration** - Auto-increment on theme/plugin changes
- **Dual Storage** - WordPress options API or file-based storage

## Architecture

VersionManager maintains version numbers for cache groups:

1. **Version Registry**: In-memory version numbers per group
2. **Persistent Storage**: WordPress options or JSON file
3. **Key Generation**: Versioned cache keys for automatic invalidation
4. **Event Hooks**: Auto-increment on content changes

### Capability Vector

```php
[
    'versioning' => 1.0,
    'cache_busting' => 0.95,
    'invalidation' => 0.9,
    'tracking' => 0.85
]
```

## Default Cache Groups

| Group | Initial Version | Purpose |
|-------|-----------------|---------|
| `core` | 1 | Core framework cache |
| `face` | 1 | Frontend/theme cache |
| `api` | 1 | API response cache |
| `manifest` | 1 | Asset manifest cache |

## Installation & Initialization

### Via gCore (Recommended)

```php
$core = gCore::getInstance();
$versionManager = $core->getService('VersionManager');
```

### Direct Initialization

```php
$versionManager = VersionManager::getInstance();
$versionManager->initialize([
    'version_prefix' => 'gCore_',
    'auto_increment' => true,
    'site_id' => 'mysite',
    'node_id' => 'node1',
    'storage_path' => '/var/data/versions'
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `version_prefix` | string | 'gCore_' | Prefix for version keys |
| `auto_increment` | bool | true | Auto-increment on WordPress events |
| `store_history` | bool | false | Store version history |
| `debug` | bool | WP_DEBUG | Enable debug logging |
| `ttl` | int | DAY_IN_SECONDS | Version cache TTL |
| `storage_path` | string | null | File storage path (non-WP) |
| `site_id` | string | 'default' | Multi-tenant site identifier |
| `node_id` | string | 'node1' | Multi-tenant node identifier |

## Public API

### Version Operations

#### `getVersion(string $group = 'core'): int`

Get current version for a group.

```php
$version = $versionManager->getVersion('core');     // e.g., 5
$version = $versionManager->getVersion('api');      // e.g., 3
$version = $versionManager->getVersion('unknown');  // 1 (default)
```

#### `incrementVersion(string $group = 'core', int $amount = 1): int`

Increment version for a group, triggering cache invalidation. Uses gNode `GNODE_CACHE_INCR` when available for atomic operations.

```php
$newVersion = $versionManager->incrementVersion('core');
// Returns new version number
// Triggers: do_action('gCore_cache_version_incremented', $group, $version)

// Increment by specific amount
$newVersion = $versionManager->incrementVersion('api', 5);
```

#### `decrementVersion(string $group = 'core', int $amount = 1): int`

Decrement version for a group. Uses gNode `GNODE_CACHE_DECR` when available. Minimum version is 1.

```php
$newVersion = $versionManager->decrementVersion('core');
// Returns new version number (minimum 1)
// Triggers: do_action('gCore_cache_version_decremented', $group, $version)
```

#### `resetVersion(string $group = 'core', int $resetTo = 1): int`

Reset version to a specific value.

```php
$versionManager->resetVersion('core', 1);
// Triggers: do_action('gCore_cache_version_reset', $group, $version)
```

#### `incrementAllVersions(): void`

Increment all registered groups (full cache bust).

```php
$versionManager->incrementAllVersions();
// All groups incremented
// Triggers: do_action('gCore_cache_all_versions_incremented', $versions)
```

#### `registerGroup(string $group, int $initial_version = 1): bool`

Register a new cache group.

```php
$success = $versionManager->registerGroup('custom', 1);
// Returns false if group already exists
// Triggers: do_action('gCore_cache_group_registered', $group, $version)
```

### Version History

#### `getHistory(?string $group = null, int $limit = 50): array`

Get version change history.

```php
// Get all history
$history = $versionManager->getHistory(null, 100);

// Get history for specific group
$apiHistory = $versionManager->getHistory('api', 20);
// Returns: [['group' => 'api', 'version' => 5, 'action' => 'increment', 'timestamp' => ...], ...]
```

#### `clearHistory(?string $group = null): bool`

Clear version history.

```php
// Clear all history
$versionManager->clearHistory();

// Clear history for specific group
$versionManager->clearHistory('api');
```

### Key Generation

#### `getPrefix(string $group = 'core'): string`

Get versioned prefix for cache keys.

```php
$prefix = $versionManager->getPrefix('core');
// Returns: "v5_core_mysite_node1_"
```

#### `generateKey(string $key, string $group = 'core'): string`

Generate full versioned cache key.

```php
$cacheKey = $versionManager->generateKey('user_data', 'api');
// Returns: "v3_api_mysite_node1_user_data"
```

### WordPress Event Handlers

#### `handleCustomizerSave(): void`

Called on `customize_save_after` hook.

```php
// Automatically increments 'manifest' and 'face' groups
```

#### `handlePluginUpdate($upgrader, $options): void`

Called on `upgrader_process_complete` hook.

```php
// Increments all versions on theme updates
```

### Module Interface Methods

#### `getInstance(): ModuleInterface`

Get singleton instance.

#### `initialize(array $config = []): void`

Initialize with configuration.

#### `isInitialized(): bool`

Check initialization status.

#### `getConfig(): array`

Get current configuration.

#### `updateConfig(array $config): void`

Update configuration at runtime.

#### `getStatus(): array`

Get status information.

```php
$status = $versionManager->getStatus();
// Returns: initialized, versions, config, groups, site_id, node_id, storage_mode, framework
```

## Usage Examples

### Basic Cache Busting

```php
$vm = VersionManager::getInstance();
$vm->initialize(['site_id' => 'shop']);

// Generate versioned cache key
$cacheKey = $vm->generateKey('product_list', 'api');
// "v1_api_shop_node1_product_list"

// Store data with versioned key
$cache->set($cacheKey, $products, 3600);

// When products change, increment version
$vm->incrementVersion('api');
// New key: "v2_api_shop_node1_product_list"
// Old cached data automatically orphaned
```

### Custom Cache Groups

```php
$vm = VersionManager::getInstance();

// Register custom groups
$vm->registerGroup('products', 1);
$vm->registerGroup('categories', 1);
$vm->registerGroup('users', 1);

// Invalidate specific group
$vm->incrementVersion('products');

// Or invalidate everything
$vm->incrementAllVersions();
```

### Integration with CacheManager

```php
// In CacheManager or application code
$vm = $core->getService('VersionManager');
$cm = $core->getService('CacheManager');

// Get versioned key
$key = $vm->generateKey('expensive_query', 'api');

// Cache with version-aware key
$data = $cm->get($key);
if ($data === null) {
    $data = expensiveOperation();
    $cm->set($key, $data, 3600);
}

// When data changes
$vm->incrementVersion('api');
// Next request gets fresh data
```

### Multi-Tenant Version Isolation

```php
// Site A versions
$vmA = VersionManager::getInstance();
$vmA->initialize(['site_id' => 'site_a', 'node_id' => 'prod1']);
$vmA->getPrefix('core'); // "v1_core_site_a_prod1_"

// Site B has independent versions
// "v3_core_site_b_prod1_"
```

### File-Based Storage (Non-WordPress)

```php
$vm = VersionManager::getInstance();
$vm->initialize([
    'storage_path' => '/var/app/data',
    'site_id' => 'api_server',
    'node_id' => 'worker1'
]);
// Versions stored in: /var/app/data/versions_api_server_worker1.json
```

## Integration Points

### Dependencies

| Manager | Relationship | Purpose |
|---------|--------------|---------|
| **CacheManager** | Primary Storage | Version persistence (preferred) |
| **gCore** | Parent | Service discovery |
| **WordPress** | Fallback Storage | Options API when CacheManager unavailable |
| **File System** | Fallback Storage | JSON file when neither available |

### WordPress Hooks

VersionManager registers the following hooks:

| Hook | Callback | Purpose |
|------|----------|---------|
| `switch_theme` | `incrementAllVersions` | Bust all caches on theme switch |
| `activated_plugin` | `incrementAllVersions` | Bust caches on plugin activation |
| `deactivated_plugin` | `incrementAllVersions` | Bust caches on plugin deactivation |
| `customize_save_after` | `handleCustomizerSave` | Bust manifest/face on customizer save |
| `upgrader_process_complete` | `handlePluginUpdate` | Bust caches on theme updates |

### WordPress Actions Triggered

```php
// When a single group version increments
do_action('gCore_cache_version_incremented', $group, $newVersion);

// When all versions increment
do_action('gCore_cache_all_versions_incremented', $allVersions);

// When a new group is registered
do_action('gCore_cache_group_registered', $group, $initialVersion);
```

### gNode Integration

VersionManager registers with gNode for capability-based service discovery:

```php
$this->gNodeClient->registerService(
    'VersionManager',
    $this->capabilityVector,
    [
        'type' => 'manager',
        'tier' => 'TOOL',
        'priority' => '250'
    ]
);
```

## Key Namespacing

Version prefix format:
```
v{version}_{group}_{site_id}_{node_id}_
```

Examples:
- `v1_core_default_node1_` - Default namespace, version 1
- `v5_api_shop_prod1_` - Shop site, API group, version 5
- `v2_face_blog_node2_` - Blog site, face group, version 2

## Storage Modes

### WordPress Mode

Uses WordPress options API:
```php
// Option name: gCore_cache_versions
get_option('gCore_cache_versions', []);
update_option('gCore_cache_versions', $versions, 'no');
```

### File Mode

Uses JSON file storage:
```php
// File: {storage_path}/versions_{site_id}_{node_id}.json
$json = file_get_contents($storageFile);
$versions = json_decode($json, true);
```

## Error Handling

```php
try {
    $versionManager->initialize($config);
} catch (\Exception $e) {
    error_log('VersionManager init failed: ' . $e->getMessage());
}
```

## Best Practices

1. **Use meaningful group names**: Match groups to logical cache domains
2. **Increment selectively**: Only increment groups affected by changes
3. **Register groups early**: Set up custom groups during initialization
4. **Integrate with CacheManager**: Always use versioned keys for cached data
5. **Configure storage_path**: For non-WordPress deployments

## Troubleshooting

### Versions Not Persisting

1. In WordPress: Check if `update_option` is working
2. File mode: Verify `storage_path` is writable
3. Check file permissions on JSON storage

### Cache Not Invalidating

1. Verify version was incremented (`getVersion()`)
2. Confirm cache key uses `generateKey()` or `getPrefix()`
3. Check correct group is being incremented

### Multi-Tenant Issues

1. Ensure unique `site_id` per tenant
2. Verify `node_id` matches across nodes
3. Check key prefix generation
