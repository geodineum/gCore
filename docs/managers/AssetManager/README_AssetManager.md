# AssetManager

CMS-agnostic asset storage, manifest management, and bundle retrieval via gNode.

## Overview

AssetManager provides persistent asset CRUD, manifest-driven bundling, and retrieval of daemon-built gzip bundles. It communicates with gNode via stream commands backed by Lua functions. When gNode is unavailable, it falls back to in-memory storage for the current request. Includes a backward-compatibility bridge for legacy face_mapping used by gCube and other child themes.

## Access

```php
$manager = $gCore->getService('AssetManager');
```

## Methods

### Asset CRUD

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `storeAsset` | `(string $assetId, string $content, string $contentType = 'text/html', array $options = [])` | `array` | Store content in ValKey. Options: `ttl`, `minify`, `gzip`. |
| `getAsset` | `(string $assetId)` | `?array` | Retrieve asset content and metadata. Checks in-memory cache first. |
| `deleteAsset` | `(string $assetId)` | `bool` | Remove asset from ValKey and cache. |
| `listAssets` | `(?string $prefix = null)` | `array` | List asset IDs, optionally filtered by prefix. |
| `assetExists` | `(string $assetId)` | `bool` | Check if an asset exists in cache or ValKey. |

### Manifest Operations

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `setManifest` | `(string $manifestId, array $manifest)` | `array` | Create/update a manifest. Required fields: `type`, `layout`, `slot_count`, `slots`. |
| `getManifest` | `(string $manifestId)` | `?array` | Retrieve a manifest definition. |
| `deleteManifest` | `(string $manifestId)` | `bool` | Delete a manifest. |
| `listManifests` | `()` | `array` | List all manifest IDs. |

### Bundle Retrieval

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `getBundle` | `(string $manifestId = 'main', bool $decompress = true)` | `?array` | Retrieve a daemon-built gzip bundle. Decompresses and JSON-decodes by default. |
| `getBundleStatus` | `(string $manifestId)` | `?array` | Get build metadata for a bundle. |
| `invalidateBundle` | `(string $manifestId = 'main')` | `bool` | Publish invalidation event to trigger daemon rebuild. |

### Backward Compatibility

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `syncFaceMapping` | `(array $faceMapping)` | `bool` | Writes legacy `face_mapping` key and converts to manifest. Triggers rebuild. Supports cube (6-face) and tesseract (8-cell) layouts. |

## Configuration

```php
$config = [
    'site_id'         => 'default',
    'node_id'         => 'default',
    'default_ttl'     => 0,       // 0 = no expiry for assets
    'bundle_ttl'      => 300,     // 5 minutes for bundles
    'default_minify'  => false,
    'default_gzip'    => false,
    'cache_assets'    => true,    // Per-request in-memory cache
];
```

## ValKey Key Layout

```
{site_id}:asset:{asset_id}                 # Asset content
{site_id}:asset:{asset_id}:meta            # Asset metadata hash
{site_id}:asset:manifests                  # Manifest ID index (set)
{site_id}:asset:manifest:{manifest_id}     # Manifest definition (hash)
{site_id}:gnode:bundle:{manifest_id}       # Gzip bundle (daemon-built)
{site_id}:gnode:bundle:{manifest_id}:meta  # Build metadata (hash)
```

## Status

Base tier -- included in core framework. Requires gNode-Client for persistent storage; operates in memory-only fallback mode without it.
