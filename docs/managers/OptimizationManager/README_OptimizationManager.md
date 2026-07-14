# OptimizationManager

**Version:** 2.0.0
**Namespace:** `gCore\Modules\Managers\Base\OptimizationManager`
**Implements:** `ModuleInterface`
**Uses Trait:** `AdvancedOptimizations`

## Overview

OptimizationManager handles performance optimization, resource management, and asset optimization. It provides a suite of optimizations for both WordPress and generic PHP environments.

### Key Features

- **Multi-Phase Optimization** - Early, Standard, and Late optimization phases
- **Asset Optimization** - Script deferring, style optimization, query string removal
- **Resource Hints** - Preload, preconnect, DNS prefetch management
- **HTML Minification** - Output buffer processing with safe content preservation
- **Database Optimization** - Query optimization and cleanup (WordPress)
- **HTTP/2 Server Push** - Automatic critical resource pushing
- **Security Headers** - X-Content-Type-Options, X-Frame-Options, X-XSS-Protection

## Architecture

OptimizationManager operates in three phases:

1. **Early Phase** (Priority 1): Pre-init optimizations (emoji removal, query strings)
2. **Standard Phase** (Priority 10): Header cleanup, security headers, version removal
3. **Late Phase** (Priority 999): Database optimizations, output buffering

### Capability Vector

```php
[
    'optimization' => 1.0,
    'performance' => 0.95,
    'caching' => 0.3,
    'assets' => 0.85,
    'resources' => 0.9
]
```

## Installation & Initialization

### Via gCore (Recommended)

```php
$core = gCore::getInstance();
$optimizationManager = $core->getService('OptimizationManager');
```

### Direct Initialization

```php
$optimizationManager = OptimizationManager::getInstance();
$optimizationManager->initialize([
    'enabled' => true,
    'defer_scripts' => true,
    'optimize_styles' => true,
    'remove_query_strings' => true,
    'optimize_database' => true,
    'site_id' => 'mysite',
    'node_id' => 'node1'
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | true | Enable all optimizations |
| `debug` | bool | WP_DEBUG | Enable debug mode |
| `static_extensions` | array | [...] | File extensions for static caching |
| `cache_ttl` | int | HOUR_IN_SECONDS | Cache TTL for optimized assets |
| `resource_version` | string | '1.0' | Resource version string |
| `defer_scripts` | bool | true | Add defer attribute to scripts |
| `optimize_styles` | bool | true | Optimize stylesheet loading |
| `remove_query_strings` | bool | true | Remove ?ver= from URLs |
| `optimize_database` | bool | true | Enable database optimizations |
| `preload_resources` | array | [] | Resources to preload |
| `excluded_scripts` | array | [...] | Script handles to exclude from defer |
| `excluded_styles` | array | [...] | Style handles to exclude from optimization |
| `cleanup_interval` | int | 3600 | Seconds between database cleanup runs |
| `site_id` | string | 'default' | Multi-tenant site identifier |
| `node_id` | string | 'node1' | Multi-tenant node identifier |

### Default Static Extensions

```php
['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'woff', 'woff2']
```

### Default Excluded Scripts

```php
['jquery', 'jquery-core', 'jquery-migrate', 'wp-i18n', 'wp-hooks']
```

### Default Excluded Styles

```php
['admin-bar', 'dashicons']
```

## Public API

### Optimization Phases (WordPress)

#### `earlyOptimizations(): void`

First-phase optimizations (priority 1 on `init`).

- Disables emoji detection and styles
- Removes query strings from scripts/styles
- Disables XML-RPC
- Removes unnecessary WordPress head links

#### `standardOptimizations(): void`

Second-phase optimizations (priority 10 on `init`).

- Removes X-Powered-By, X-Pingback, Server headers
- Adds security headers
- Removes WordPress version info

#### `lateOptimizations(): void`

Third-phase optimizations (priority 999 on `init`).

- Registers database query optimization

### Asset Optimization

#### `optimizeAssets(): void`

Optimize script and style loading (hooked to `wp_enqueue_scripts`).

```php
// Automatically adds defer to scripts (except jQuery)
// Changes non-critical styles to print media with onload swap
```

#### `removeQueryStrings(string $src): string`

Remove version query strings from asset URLs.

```php
$clean = $optimizationManager->removeQueryStrings('/js/app.js?ver=1.2.3');
// Returns: '/js/app.js'
```

### Exclusion Management

#### `getExcludedScripts(): array`

Get list of script handles excluded from defer.

```php
$excluded = $optimizationManager->getExcludedScripts();
// Returns: ['jquery', 'jquery-core', 'jquery-migrate', 'wp-i18n', 'wp-hooks']
```

#### `getExcludedStyles(): array`

Get list of style handles excluded from optimization.

```php
$excluded = $optimizationManager->getExcludedStyles();
// Returns: ['admin-bar', 'dashicons']
```

#### `excludeScript(string $handle): bool`

Add a script handle to the exclusion list.

```php
$optimizationManager->excludeScript('my-critical-script');
```

#### `excludeStyle(string $handle): bool`

Add a style handle to the exclusion list.

```php
$optimizationManager->excludeStyle('my-critical-styles');
```

#### `includeScript(string $handle): bool`

Remove a script handle from the exclusion list.

```php
$optimizationManager->includeScript('wp-hooks'); // Allow wp-hooks to be deferred
```

#### `includeStyle(string $handle): bool`

Remove a style handle from the exclusion list.

```php
$optimizationManager->includeStyle('dashicons');
```

### Header Optimization

#### `optimizeHeaders(array $headers, $wp): array`

Optimize HTTP response headers (filter: `wp_headers`).

```php
// For static files: Cache-Control: public, max-age=31536000
```

#### `addResourceHints(): void`

Add preload and preconnect hints (hooked to `wp_head`).

```php
// Outputs:
// <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
// <link rel="preload" href="..." as="...">
```

### Database Optimization (WordPress)

#### `optimizeQueries(string $where, $query): string`

Optimize WordPress queries (filter: `posts_where`).

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
$status = $optimizationManager->getStatus();
// Returns: initialized, enabled, defer_scripts, optimize_styles,
//          remove_query_strings, optimize_database, resource_version,
//          metrics_available, site_id, node_id, framework
```

## AdvancedOptimizations Trait

The trait provides additional optimization methods:

### DNS Management

#### `manageDnsPrefetch(): void`

Custom DNS prefetch for common CDN domains.

```php
// Adds prefetch for fonts.googleapis.com, fonts.gstatic.com, cdnjs.cloudflare.com
```

### Font Loading

#### `optimizeFontLoading(string $html, string $handle, string $href, string $media): string`

Optimize Google Fonts loading with font-display: swap.

### Image Optimization

#### `optimizeImageLoading(string $content): string`

Add lazy loading and async decoding to images.

```php
// Adds loading="lazy" decoding="async" to all <img> tags
// Adds srcset for responsive images (WordPress)
```

### DOM Monitoring

#### `monitorDomSize(): void`

Client-side DOM size monitoring (debug mode).

```php
// Warns if DOM > 1500 elements or nesting > 20 levels
```

### Memory Optimization (WordPress)

#### `optimizeMemoryUsage(): void`

Database cleanup optimizations. **Throttled** to run at most once per `cleanup_interval` (default: 1 hour) to support high-throughput scenarios.

- Limits post revisions to 5
- Deletes auto-drafts older than 7 days
- Cleans expired transients

**IMPORTANT:** This method checks `shouldRunCleanup()` before executing. For high-traffic sites, consider using `forceCleanup()` via WP-Cron instead of relying on request-based cleanup.

#### `forceCleanup(): array`

Force run database cleanup regardless of throttle. Returns cleanup results.

```php
$results = $optimizationManager->forceCleanup();
// Returns: [
//   'auto_drafts_deleted' => 5,
//   'transients_deleted' => 12,
//   'timestamp' => 1704067200
// ]
```

**Risk Note:** Database cleanup operations DELETE records. Ensure database backups are in place.

### Query Optimization

#### `optimizeWpQueries($query): void`

WordPress query optimizations.

- Disables meta/term cache updates when not needed
- Limits author query results
- Adds `no_found_rows` for archives

### Output Buffering

#### `startOutputBuffer(): void` / `endOutputBuffer(): void`

Manages output buffering for HTML minification.

#### `optimizeHtmlOutput(string $buffer): string`

HTML minification with safe content preservation.

- Preserves script, style, pre, code, textarea content
- Removes HTML comments
- Collapses whitespace
- Removes query strings from URLs

### HTTP/2 Server Push

#### `setupHttp2ServerPush(): void`

Add Link headers for critical resources.

```php
// Link: </assets/css/tesseract.css>; rel=preload; as=style
// Link: </assets/js/tesseract.js>; rel=preload; as=script
```

### Media Query Optimization

#### `optimizeMediaQueries(): void`

Optimize stylesheet media query loading.

## Usage Examples

### Basic Usage

```php
$opt = OptimizationManager::getInstance();
$opt->initialize([
    'enabled' => true,
    'defer_scripts' => true,
    'site_id' => 'shop'
]);

// Optimizations automatically apply via WordPress hooks
```

### Custom Preload Resources

```php
$opt->initialize([
    'preload_resources' => [
        'assets/css/critical.css' => 'style',
        'assets/js/app.js' => 'script',
        'assets/fonts/main.woff2' => 'font'
    ]
]);
```

### Selective Optimization

```php
$opt->initialize([
    'enabled' => true,
    'defer_scripts' => true,    // Enable script deferring
    'optimize_styles' => false,  // Disable style optimization
    'optimize_database' => false // Disable DB optimization
]);
```

### Checking Status

```php
$status = $optimizationManager->getStatus();

if ($status['metrics_available']) {
    // MetricsManager integration active
}

echo "Script deferring: " . ($status['defer_scripts'] ? 'enabled' : 'disabled');
```

## Integration Points

### Dependencies

| Manager | Relationship | Purpose |
|---------|--------------|---------|
| **MetricsManager** | Optional | Performance metric recording |
| **gCore** | Parent | Service discovery |

### WordPress Hooks

OptimizationManager registers the following hooks:

| Hook | Callback | Priority | Purpose |
|------|----------|----------|---------|
| `init` | `earlyOptimizations` | 1 | First-phase optimizations |
| `init` | `standardOptimizations` | 10 | Second-phase optimizations |
| `init` | `lateOptimizations` | 999 | Third-phase optimizations |
| `wp_enqueue_scripts` | `optimizeAssets` | 999 | Asset optimization |
| `wp_headers` | `optimizeHeaders` | 10 | Header optimization |
| `wp_head` | `addResourceHints` | 2 | Resource hints |

### AdvancedOptimizations Hooks

| Hook | Callback | Purpose |
|------|----------|---------|
| `wp_head` | `manageDnsPrefetch` | DNS prefetch management |
| `style_loader_tag` | `optimizeFontLoading` | Font optimization |
| `the_content` | `optimizeImageLoading` | Image lazy loading |
| `post_thumbnail_html` | `optimizeImageLoading` | Thumbnail lazy loading |
| `template_redirect` | `monitorDomSize` | DOM monitoring |
| `init` | `optimizeMemoryUsage` | Memory optimization |
| `pre_get_posts` | `optimizeWpQueries` | Query optimization |
| `template_redirect` | `startOutputBuffer` | Start output buffering |
| `shutdown` | `endOutputBuffer` | End output buffering |
| `send_headers` | `setupHttp2ServerPush` | HTTP/2 push |
| `wp_enqueue_scripts` | `optimizeMediaQueries` | Media query optimization |

### WordPress Filters

| Filter | Callback | Purpose |
|--------|----------|---------|
| `script_loader_src` | `removeQueryStrings` | Remove ?ver= from scripts |
| `style_loader_src` | `removeQueryStrings` | Remove ?ver= from styles |
| `wp_headers` | `optimizeHeaders` | Header modification |
| `posts_where` | `optimizeQueries` | Query modification |

### gNode Integration

OptimizationManager registers with gNode for capability-based service discovery:

```php
$this->gNodeClient->registerService(
    'OptimizationManager',
    $this->capabilityVector,
    [
        'type' => 'manager',
        'tier' => 'TOOL',
        'priority' => '300'
    ]
);
```

## Security Headers

StandardOptimizations adds these security headers:

```
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
```

And removes:
```
X-Powered-By
X-Pingback
Server
```

## HTML Minification

The output buffer processor:

1. **Preserves** script, style, pre, code, textarea content
2. **Removes** HTML comments (except IE conditionals)
3. **Collapses** whitespace between tags
4. **Strips** query strings from inline URLs

## Performance Metrics

When MetricsManager is available, records:
- `optimization_init` - Initialization metrics
- `asset_optimization` - Asset processing metrics
- `auto_drafts_cleaned` - Database cleanup counts
- `expired_transients_cleaned` - Transient cleanup counts

## Best Practices

1. **Test thoroughly**: Optimizations can break functionality
2. **Monitor Core Web Vitals**: Track LCP, FID, CLS
3. **Use debug mode**: Enable in development to catch issues
4. **Preserve critical CSS**: Don't defer above-the-fold styles
5. **Configure preloads**: Identify critical resources
6. **Check compatibility**: Some plugins conflict with optimizations

## Troubleshooting

### Scripts Breaking

1. Check if script requires jQuery (excluded from defer)
2. Verify script order dependencies
3. Disable `defer_scripts` temporarily

### Styles Flash (FOUC)

1. Identify critical CSS
2. Exclude critical styles from print media swap
3. Consider critical CSS inlining

### HTML Minification Issues

1. Check if content is in preserved tags
2. Verify regex patterns aren't corrupting content
3. Disable output buffering temporarily

### Database Optimization Errors

1. Check WordPress database permissions
2. Verify table prefixes
3. Monitor query log for issues
