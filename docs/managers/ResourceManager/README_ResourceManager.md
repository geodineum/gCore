# ResourceManager Documentation

## Overview

ResourceManager provides resource management including asset bundling, template management, and resource optimization. It uses gNode's native asset bundling (batched in a single round-trip) and template fragment management.

**Namespace**: `gCore\Modules\Managers\Base\ResourceManager`
**Implements**: `ModuleInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)

## Architecture

ResourceManager operates in two modes:

1. **Enhanced Mode (gNode)**: Full asset bundling, template management, batch operations
2. **Default Tier Mode**: Basic caching and optimization (limited features)

### Key Features

- Native gNode asset bundling (batched, single round-trip)
- Template fragment management with discovery
- Server-side template rendering
- Resource loading and caching
- Capability-based template discovery
- Multi-tenant isolation (site_id/node_id)

## Initialization

```php
// Get ResourceManager instance via gCore
$resources = gCore::getService('ResourceManager');

// Configuration (passed during gCore initialization)
$config = [
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'use_gnode' => true,
    'gnode_client' => $gNodeClient,
    'cache_enabled' => true,
    'optimization_enabled' => true,
    'default_bundle_type' => 'mixed',
    'default_minify' => true,
    'default_ttl' => 3600,
    'max_bundle_size' => 1048576,  // 1MB
    'debug' => false
];
```

## Public API Reference

### Singleton & Lifecycle

#### `getInstance(): ModuleInterface`
Returns the singleton instance of ResourceManager.

#### `initialize(array $config = []): void`
Initializes the resource system with configuration.
- **Throws**: `InitializationException` if initialization fails

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getStatus(): array`
Get full status including mode, statistics, and registry counts.

```php
[
    'initialized' => true,
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'mode' => 'full',           // full | stub
    'storage_type' => 'gnode',
    'storage' => 'available',
    'gnode_enabled' => true,
    'cache_enabled' => true,
    'optimization_enabled' => true,
    'statistics' => [...],
    'capabilities' => [...],
    'registry_counts' => [
        'assets' => 0,
        'templates' => 0,
        'bundles' => 0
    ]
]
```

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

#### `getCapabilityVector(): array`
Get capability vector for gNode registration.

#### `getStatistics(): array`
Get resource statistics.

### Asset Management

#### `createAssetBundle(string $bundleId, array $assets, string $bundleType = 'mixed', bool $minify = true, ?int $ttl = null): array`
Create an asset bundle using native gNode assetBundle method.
- **Parameters**:
  - `$bundleId`: Bundle identifier
  - `$assets`: Array of asset identifiers or content
  - `$bundleType`: Bundle type ('css', 'js', or 'mixed')
  - `$minify`: Enable minification
  - `$ttl`: Time to live in seconds
- **Returns**: Result with bundle details
- **Throws**: `StorageException` if gNode not available

```php
$bundle = $resources->createAssetBundle('main-css', [
    'style.css' => $cssContent,
    'theme.css' => $themeContent,
    'custom.css' => $customContent
], 'css', true, 3600);

// Returns:
// [
//     'success' => true,
//     'bundle_id' => 'main-css',
//     'bundled_size' => 15234,
//     'original_size' => 45678,
//     'compression_ratio' => 0.67
// ]
```

#### `loadAsset(string $assetId, bool $useCache = true): ?array`
Load an asset by identifier.
- **Returns**: Asset data or null if not found

#### `batchLoadAssets(array $assetIds): array`
Batch load multiple assets using gNode executeBatch.
- **Returns**: Associative array of `assetId => data`
- **Throws**: `StorageException` if gNode not available

```php
$assets = $resources->batchLoadAssets(['style.css', 'script.js', 'theme.css']);
```

#### `optimizeAsset(string $content, string $type, array $options = []): string`
Optimize an asset (minify, compress).
- **Parameters**:
  - `$content`: Asset content
  - `$type`: Asset type ('css' or 'js')
  - `$options`: Optimization options
- **Returns**: Optimized content

#### `invalidateAsset(string $assetId): bool`
Invalidate an asset from cache.
- **Returns**: Success status

### Template Management

#### `storeTemplateFragment(string $templateId, string $content, array $dependencies = [], array $variables = [], ?int $ttl = null): array`
Store template fragment using native gNode templateFragment method.
- **Parameters**:
  - `$templateId`: Template identifier
  - `$content`: Template content
  - `$dependencies`: Template dependencies
  - `$variables`: Template variables
  - `$ttl`: Time to live
- **Returns**: Result with success status
- **Throws**: `StorageException` if gNode not available

```php
$result = $resources->storeTemplateFragment('header', $headerContent,
    ['logo', 'nav'],           // dependencies
    ['site_name', 'menu_items'] // variables
);
```

#### `discoverTemplatesByCapability(array $capabilities, int $limit = 10): array`
Discover templates by capability using gNode geometric discovery.
- **Returns**: Array of matching template metadata
- **Throws**: `StorageException` if gNode not available

```php
$templates = $resources->discoverTemplatesByCapability([
    'e_commerce' => 0.8,
    'responsive' => 0.9
]);
```

#### `renderTemplateString(string $template, array $variables = [], array $config = []): string`
Render template string with variables using gNode.
- **Returns**: Rendered template
- **Throws**: `StorageException` if gNode not available

```php
$rendered = $resources->renderTemplateString(
    'Hello {{name}}, your order #{{order_id}} is ready!',
    ['name' => 'John', 'order_id' => '12345']
);
```

#### `getAllTemplateMetadata(array $config = []): array`
Get all template metadata from gNode.
- **Throws**: `StorageException` if gNode not available

#### `getTemplateStatistics(): array`
Get template statistics from gNode.

#### `cacheTemplate(string $templateId, string $rendered, ?int $ttl = null): bool`
Cache a rendered template.

#### `invalidateTemplate(string $templateId): bool`
Invalidate a template from cache.

### Resource Loading

#### `loadResource(string $url, string $type = 'auto'): ?array`
Load a resource by URL or identifier.
- **Parameters**:
  - `$url`: Resource URL or identifier
  - `$type`: Resource type (auto-detected if 'auto')
- **Returns**: Resource data or null

#### `preloadResources(array $resources): array`
Preload resources for performance.
- **Returns**: Results for each resource

#### `setupLazyLoad(string $url, string $trigger = 'viewport'): array`
Setup lazy-loading for a resource.
- **Returns**: Lazy-load configuration

#### `getBundleManifest(string $bundleId): ?array`
Get bundle manifest.
- **Returns**: Bundle manifest or null

#### `warmupCache(array $resources): int`
Warmup cache with resources.
- **Returns**: Number of resources cached

### Performance Optimization

#### `minifyAsset(string $content, string $type): string`
Minify asset content.

#### `compressBundle(array $bundle): array`
Compress bundle data.
- **Returns**: Bundle with compression info

#### `generateSourceMap(string $bundleId): ?array`
Generate source map for bundle.

#### `analyzeBundle(string $bundleId): array`
Analyze bundle performance.

```php
$analysis = $resources->analyzeBundle('main-css');
// [
//     'bundle_id' => 'main-css',
//     'type' => 'css',
//     'minified' => true,
//     'created_at' => 1704672000,
//     'size_estimate' => 15234,
//     'compression_ratio' => 0.67
// ]
```

## Usage Examples

### Asset Bundling

```php
$resources = gCore::getService('ResourceManager');

// Bundle CSS files
$cssBundle = $resources->createAssetBundle('site-styles', [
    'reset' => file_get_contents('css/reset.css'),
    'theme' => file_get_contents('css/theme.css'),
    'custom' => file_get_contents('css/custom.css')
], 'css', true);

// Bundle JS files
$jsBundle = $resources->createAssetBundle('site-scripts', [
    'vendor' => file_get_contents('js/vendor.js'),
    'app' => file_get_contents('js/app.js')
], 'js', true);

echo "CSS compressed: " . round($cssBundle['compression_ratio'] * 100) . "%\n";
echo "JS compressed: " . round($jsBundle['compression_ratio'] * 100) . "%\n";
```

### Template Management

```php
// Store a reusable template
$resources->storeTemplateFragment('product-card', '
<div class="product">
    <img src="{{image_url}}" alt="{{name}}">
    <h3>{{name}}</h3>
    <p class="price">{{price}}</p>
    <button data-id="{{id}}">Add to Cart</button>
</div>
', [], ['image_url', 'name', 'price', 'id']);

// Render template with data
$rendered = $resources->renderTemplateString(
    file_get_contents('templates/product-card.tpl'),
    [
        'image_url' => '/images/product.jpg',
        'name' => 'Widget',
        'price' => '$29.99',
        'id' => '12345'
    ]
);
```

### Batch Operations

```php
// Batch load assets (single round-trip)
$assets = $resources->batchLoadAssets([
    'header.css',
    'main.css',
    'footer.css',
    'sidebar.css'
]);

foreach ($assets as $assetId => $data) {
    if ($data !== null) {
        echo "Loaded: $assetId\n";
    }
}
```

### Resource Type Detection

```php
$type = $resources->loadResource('https://example.com/styles/main.css');
// Auto-detected as 'stylesheet'

$type = $resources->loadResource('https://example.com/scripts/app.js');
// Auto-detected as 'script'

$type = $resources->loadResource('https://example.com/images/hero.jpg');
// Auto-detected as 'image'
```

## Capability Vector

```php
[
    'resource_loading' => 1.0,
    'asset_bundling' => 0.95,
    'template_management' => 0.9,
    'optimization' => 0.8,
    'caching' => 0.85
]
```

## Resource Type Mapping

| Extension | Type |
|-----------|------|
| css | stylesheet |
| js | script |
| jpg, jpeg, png, gif, svg | image |
| woff, woff2, ttf, eot | font |

## Statistics Tracked

```php
[
    'assets_loaded' => 0,
    'templates_rendered' => 0,
    'bundles_created' => 0,
    'cache_hits' => 0,
    'cache_misses' => 0
]
```

---

*Last Updated: January 2026*
