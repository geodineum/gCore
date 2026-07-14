# AnalyticsManager

## Overview

AnalyticsManager provides privacy-first visitor analytics using ValKey sorted sets. Designed for GDPR compliance by default, it stores only hashed visitor identifiers - never raw IPs or PII.

**Key Features:**
- Privacy-first: Only hashed visitor identifiers stored
- Time-series data: Sorted sets for efficient date-range queries
- 90-day retention: Automatic expiration via TTL
- Multi-tenant: Site-isolated keyspaces
- GDPR-compliant: Consent-driven tracking with fail-closed defaults
- Service Worker aware: Filters stale-while-revalidate background fetches
- Journey tracking: Visitor path analysis without PII

## Installation & Configuration

### Basic Setup

```php
use gCore\Modules\Core\gCore;

$gCore = gCore::getInstance();
$analytics = $gCore->getService('AnalyticsManager');
```

### Configuration Options

```php
$config = [
    'enabled' => true,
    'debug' => false,
    'retention_days' => 90,
    'track_pageviews' => true,
    'track_visitors' => true,
    'hash_algorithm' => 'sha256',
    'site_id' => 'mysite',
    'node_id' => 'node1',
    'use_gnode' => true,
    'exclude_paths' => [
        '/wp-admin',
        '/wp-json',
        '/wp-cron.php',
        '/xmlrpc.php',
        '/favicon.ico',
        '/robots.txt'
    ],
    'exclude_bots' => true,
    'require_consent' => true,
    'consent_callback' => function($type) {
        // Return true if user consented to $type tracking
        return has_user_consent($type);
    }
];

$analytics->initialize($config);
```

## ValKey Key Schema

| Key Pattern | Type | Description |
|-------------|------|-------------|
| `{site_id}:visits:{YYYYMMDD}` | ZSET | Timestamp -> visitor_hash |
| `{site_id}:visitors:{hash}` | HASH | first_seen, last_seen, page_count, pages |
| `{site_id}:pageviews:{YYYYMMDD}` | ZSET | Timestamp -> page_url |
| `{site_id}:pagecounts:{YYYYMMDD}` | HASH | page_url -> count |
| `{site_id}:journeys:{YYYYMMDD}` | ZSET | journey_hash -> count |
| `{site_id}:journey_paths:{YYYYMMDD}` | HASH | journey_hash -> path |
| `{site_id}:visitor_journey:{YYYYMMDD}:{hash}` | STRING | path sequence |
| `{site_id}:visitor_requests:{YYYYMMDD}` | HASH | visitor -> request_count |
| `{site_id}:visitor_bytes:{YYYYMMDD}` | HASH | visitor -> bytes |
| `{site_id}:cache_efficiency:{YYYYMMDD}` | HASH | cache metrics |

## API Reference

### Tracking Methods

#### `trackVisit(): void`
Automatically tracks page visits. Called via WordPress `template_redirect` hook.

**GDPR Compliance:**
- Only executes if `require_consent` is false OR consent callback returns true
- Excludes admin, AJAX, cron, bots, and configured paths
- Filters Service Worker background fetches

#### `recordJourneyStep(string $visitorHash, string $uri, int $timestamp): void`
Records a step in visitor's journey path for funnel analysis.

```php
$analytics->recordJourneyStep($hash, '/products', time());
```

#### `trackResourceCosts(string $visitorHash, int $requestCount, int $bytesTransferred, bool $fromCache = false): void`
Tracks resource consumption per visitor for cost analysis.

```php
$analytics->trackResourceCosts($hash, 5, 102400, false);
```

### Query Methods

#### `getUniqueVisitors(string $startDate, string $endDate): int`
Returns unique visitor count across date range.

```php
$visitors = $analytics->getUniqueVisitors('20260101', '20260107');
// Returns: 1523
```

#### `getPageviews(string $startDate, string $endDate): int`
Returns total pageview count across date range.

```php
$pageviews = $analytics->getPageviews('20260101', '20260107');
// Returns: 8456
```

#### `getTopPages(string $startDate, string $endDate, int $limit = 10): array`
Returns top pages by view count.

```php
$topPages = $analytics->getTopPages('20260101', '20260107', 5);
// Returns: ['/' => 2100, '/products' => 890, '/about' => 450, ...]
```

#### `getTodaySummary(): array`
Returns today's analytics summary.

```php
$summary = $analytics->getTodaySummary();
// Returns:
// [
//     'date' => '20260109',
//     'unique_visitors' => 145,
//     'pageviews' => 623,
//     'top_pages' => ['/' => 120, ...]
// ]
```

#### `getSummary(int $days = 7): array`
Returns analytics summary for last N days.

```php
$weekly = $analytics->getSummary(7);
```

#### `getDailyStats(string $date): array`
Returns statistics for a specific date.

```php
$stats = $analytics->getDailyStats('20260108');
```

### Journey Analytics

#### `getVisitorJourneys(string $date, int $limit = 20): array`
Returns visitor journey patterns (user stories).

```php
$journeys = $analytics->getVisitorJourneys('20260109', 10);
// Returns:
// [
//     [
//         'path' => ['home', 'products', 'cart', 'checkout'],
//         'count' => 45,
//         'percentage' => 12.5,
//         'avg_duration' => '4.2m'
//     ],
//     ...
// ]
```

### Resource Cost Analytics

#### `getVisitorResourceCosts(string $date): array`
Returns visitor resource consumption sorted by bytes (highest first).

```php
$costs = $analytics->getVisitorResourceCosts('20260109');
// Returns: ['abc123...' => ['requests' => 50, 'bytes' => 5242880], ...]
```

#### `getCacheEfficiency(string $date): array`
Returns cache efficiency metrics.

```php
$efficiency = $analytics->getCacheEfficiency('20260109');
// Returns:
// [
//     'date' => '20260109',
//     'total_requests' => 10000,
//     'cached_requests' => 7500,
//     'network_requests' => 2500,
//     'cache_hit_rate' => 75.0,
//     'bytes_saved' => 15728640,
//     'bytes_transferred' => 5242880,
//     'bandwidth_savings_percent' => 75.0
// ]
```

#### `getMetricHistory(string $metricName, int $count = 10): array`
Returns historical metric data via gNode Lua function.

```php
$history = $analytics->getMetricHistory('visitor_resource_cost', 20);
```

### Module Interface Methods

#### `getStatus(): array`
Returns current manager status.

```php
$status = $analytics->getStatus();
// Returns:
// [
//     'initialized' => true,
//     'enabled' => true,
//     'gnode_enabled' => true,
//     'site_id' => 'mysite',
//     'node_id' => 'node1',
//     'retention_days' => 90,
//     'framework' => 'WordPress'
// ]
```

#### `getConfig(): array` / `updateConfig(array $config): void`
Get or update runtime configuration.

## GDPR Compliance

### Default Behavior (Fail-Closed)
By default, `require_consent` is `true`. If no consent mechanism is configured, tracking is disabled. This ensures GDPR compliance out of the box.

### Consent Integration

```php
// Option 1: Custom callback
$config['consent_callback'] = function($type) {
    return $_COOKIE['analytics_consent'] === 'granted';
};

// Option 2: CookieManager integration (automatic)
// AnalyticsManager checks CookieManager->hasConsent('analytics') if available

// Option 3: theme-provided consent functions (automatic)
// Checks theme consent helpers such as gcube_has_cookie_consent()
```

### What Gets Hashed
- Visitor identifier = `sha256(IP + UserAgent + site_id)`
- Site ID acts as salt to prevent cross-site tracking
- Hash is non-reversible; original data never stored

## Best Practices

### 1. Always Enable Consent Checking
```php
$config['require_consent'] = true;
$config['consent_callback'] = [$consentManager, 'hasConsent'];
```

### 2. Configure Exclusions Appropriately
```php
$config['exclude_paths'] = [
    '/wp-admin',
    '/api/',
    '/health',
    '/.well-known'
];
$config['exclude_bots'] = true;
```

### 3. Use gNode Storage Adapter
```php
// Preferred: Pass shared adapter for connection pooling
$config['gnode_storage_adapter'] = $sharedAdapter;
```

### 4. Monitor Cache Efficiency
```php
// Weekly cache efficiency report
$efficiency = $analytics->getCacheEfficiency(date('Ymd'));
if ($efficiency['cache_hit_rate'] < 70) {
    // Alert: cache performance degraded
}
```

## Troubleshooting

### Analytics Not Tracking

1. **Check consent**: Verify consent callback returns true
2. **Check exclusions**: Ensure path isn't in `exclude_paths`
3. **Check gNode**: Verify `gnode_enabled` is true in status
4. **Check bots**: User-Agent may be flagged as bot

```php
$status = $analytics->getStatus();
var_dump($status);
```

### Missing Historical Data

Data expires after `retention_days` (default 90). Configure longer retention if needed:
```php
$config['retention_days'] => 180; // 6 months
```

### High Memory Usage

Visitor journeys can accumulate. Monitor with:
```php
$costs = $analytics->getVisitorResourceCosts(date('Ymd'));
```

### Service Worker Double-Counting

AnalyticsManager automatically filters stale-while-revalidate background fetches using `Sec-Fetch-*` headers. If counts seem inflated, verify your Service Worker sends proper fetch metadata.

## Integration Points

| Component | Purpose |
|-----------|---------|
| CacheManager | ValKey storage backend |
| SecurityManager | Rate limiting context |
| CookieManager | Consent verification |
| gNode | Capability registration, Lua functions |

## Capability Vector

```php
[
    'analytics' => 1.0,
    'tracking' => 0.9,
    'metrics' => 0.6,
    'privacy' => 0.95
]
```
