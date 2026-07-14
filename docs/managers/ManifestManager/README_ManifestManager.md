# ManifestManager

## Overview

ManifestManager provides Progressive Web App (PWA) manifest generation and management for gCore applications. It is framework-agnostic, supporting both WordPress and standalone PHP environments with automatic detection.

**Key Responsibilities:**
- Generate valid web app manifest (manifest.json)
- Manage PWA icons and theme configuration
- Provide REST API endpoints for manifest delivery
- Cache manifest data for performance
- Multi-tenant isolation via site_id/node_id

## Architecture

```
ManifestManager
    |
    +-- Configuration (icon paths, theme colors, display mode)
    |
    +-- Framework Detection (WordPress / Standalone)
    |
    +-- Cache Layer (WordPress transients / CacheManager)
    |
    +-- gNode Integration (capability registration)
```

## Initialization

```php
$gCore = \gCore\Modules\Core\gCore::getInstance();
$manifest = $gCore->getService('ManifestManager');

// Or with custom configuration
$manifest->initialize([
    'enabled' => true,
    'site_id' => 'my-site',
    'node_id' => 'node1',
    'name' => 'My PWA App',
    'short_name' => 'MyApp',
    'theme_color' => '#3498db',
    'background_color' => '#ffffff',
    'display' => 'standalone',
    'icon_192x192' => '/assets/icons/icon-192.png',
    'icon_512x512' => '/assets/icons/icon-512.png'
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | `false` | Enable manifest generation |
| `cache_enabled` | bool | `true` | Enable manifest caching |
| `ttl` | int | `86400` | Cache TTL in seconds (24h) |
| `icons_path` | string | `/assets/icons/` | Base path for icons |
| `manifest_version` | string | `1.0.0` | Manifest version for cache busting |
| `site_id` | string | `default` | Multi-tenant site identifier |
| `node_id` | string | `node1` | Node identifier for clustering |
| `framework` | string | `auto` | Framework mode (auto, wordpress, standalone) |
| `rest_namespace` | string | `gCore/v1` | REST API namespace |

## Public API

### getManifestData()

Returns the complete manifest data array, using cache when available.

```php
$data = $manifest->getManifestData();
// Returns:
// [
//     'name' => 'Site Name',
//     'short_name' => 'Site',
//     'description' => 'Site description',
//     'start_url' => '/',
//     'display' => 'standalone',
//     'background_color' => '#ffffff',
//     'theme_color' => '#000000',
//     'icons' => [...]
// ]
```

### getManifestJson()

Returns manifest as HTTP response (JSON). Used for REST API endpoint.

```php
// WordPress: Returns WP_REST_Response
// Standalone: Outputs JSON with headers
$response = $manifest->getManifestJson();
```

### addManifestLink()

Outputs the `<link rel="manifest">` and theme-color meta tag to page head.

```php
// Usually called via WordPress wp_head hook automatically
$manifest->addManifestLink();
// Outputs:
// <link rel="manifest" href="/wp-json/gCore/v1/manifest">
// <meta name="theme-color" content="#000000">
```

### registerEndpoints()

Registers REST API endpoint for manifest delivery. Called automatically in WordPress.

```php
// WordPress: Automatically hooks to rest_api_init
// Standalone: Register with APIManager
$manifest->registerEndpoints();
```

### invalidateCache()

Clears cached manifest data. Call after configuration changes.

```php
$manifest->invalidateCache();
```

### updateConfig()

Updates configuration and invalidates cache.

```php
$manifest->updateConfig([
    'theme_color' => '#e74c3c',
    'name' => 'Updated App Name'
]);
```

### getConfig()

Returns current configuration.

```php
$config = $manifest->getConfig();
```

### getStatus()

Returns module status including initialization state and configuration.

```php
$status = $manifest->getStatus();
// [
//     'initialized' => true,
//     'enabled' => true,
//     'cache_enabled' => true,
//     'version' => '1.0.0',
//     'framework' => 'wordpress',
//     'icons_configured' => true,
//     'capabilities' => [...]
// ]
```

### getCapabilityVector()

Returns gNode capability vector for service discovery.

```php
$capabilities = $manifest->getCapabilityVector();
// ['pwa' => 1.0, 'manifest' => 1.0, 'cache' => 0.6, 'icons' => 0.8]
```

## WordPress Integration

In WordPress mode, ManifestManager automatically:

1. Hooks to `rest_api_init` to register the manifest endpoint
2. Hooks to `wp_head` to output manifest link tag
3. Uses WordPress transients for caching
4. Reads theme mods for PWA configuration (prefix: `pwa_`)

```php
// Theme customizer integration
set_theme_mod('pwa_name', 'My App');
set_theme_mod('pwa_theme_color', '#3498db');
set_theme_mod('pwa_icon_192x192', 'https://example.com/icon-192.png');
```

## Standalone Integration

For non-WordPress environments:

```php
// Initialize with full configuration
$manifest->initialize([
    'enabled' => true,
    'framework' => 'standalone',
    'name' => 'Standalone App',
    'home_url' => 'https://example.com',
    'manifest_url' => '/api/manifest'
]);

// Register with APIManager
$api = $gCore->getService('APIManager');
$api->registerRoute('GET', '/manifest', function() use ($manifest) {
    return $manifest->getManifestJson();
});

// Add to your HTML head
echo $manifest->addManifestLink();
```

## gNode Integration

ManifestManager registers with gNode for capability-based discovery:

```php
// Automatic registration during initialization
// Capability vector:
// - pwa: 1.0 (full PWA support)
// - manifest: 1.0 (manifest generation)
// - cache: 0.6 (caching support)
// - icons: 0.8 (icon management)
```

## Extending ManifestManager

Create a subclass to customize manifest generation:

```php
class CustomManifestManager extends ManifestManager {
    protected function generateManifest(): array {
        $manifest = parent::generateManifest();

        // Add custom fields
        $manifest['categories'] = ['business', 'productivity'];
        $manifest['shortcuts'] = $this->getAppShortcuts();

        return $manifest;
    }

    private function getAppShortcuts(): array {
        return [
            [
                'name' => 'New Task',
                'short_name' => 'Task',
                'url' => '/tasks/new',
                'icons' => [['src' => '/icons/task.png', 'sizes' => '96x96']]
            ]
        ];
    }
}
```

## Troubleshooting

### Manifest not loading

1. Check that `enabled` is set to `true`
2. Verify REST API endpoint is accessible: `GET /wp-json/gCore/v1/manifest`
3. Check browser DevTools Network tab for errors

### Icons not appearing

1. Verify icon URLs are accessible
2. Icons must be PNG format
3. Required sizes: 192x192 and 512x512

### Cache not clearing

```php
// Force cache invalidation
$manifest->invalidateCache();

// Or in WordPress
delete_transient('manifest_default_node1_manifest');
```

### Framework detection issues

```php
// Force specific framework
$manifest->initialize([
    'framework' => 'wordpress' // or 'standalone'
]);
```

## Dependencies

- **gCore\Modules\Core\Interfaces\ModuleInterface**: Required for singleton pattern
- **gCore\Modules\Core\Utils\SelfContainedErrorHandler**: Error logging
- **gNode Client** (optional): Capability registration
- **WordPress** (optional): WordPress-specific features

## Related Managers

- **CacheManager**: For standalone mode caching
- **ResourceManager**: Asset path resolution
- **APIManager**: REST endpoint registration (standalone mode)
