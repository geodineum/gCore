# MetricsManager Documentation

## Overview

MetricsManager provides a thin PHP interface to read metrics stored in ValKey by Lua scripts. This manager is intentionally minimal - it does NOT record metrics itself. Metric recording is handled by ValKey Lua functions (`TRACK_METRIC`, etc.) called by other managers. MetricsManager simply reads and aggregates that data.

**Namespace**: `gCore\Modules\Managers\Base\MetricsManager`
**Implements**: `ModuleInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)

## Architecture

MetricsManager operates as a **read-only metrics gateway**:

1. **gNode-Client Mode**: Direct ValKey access via gNode-Client connection
2. **Storage Adapter Mode**: ValKey access via gNodeStorageAdapter
3. **Read-Only Mode**: No ValKey connection (returns empty data)

### Key Namespacing

```
{site_id}:metrics                    Hash with metric counters
{site_id}:metrics:details:{type}     Lists with detailed data (last 1000)
{site_id}:latency                    Sorted set with latency samples
{global}:metrics                     Global aggregated metrics
```

## Initialization

```php
// Get MetricsManager instance via gCore
$metrics = gCore::getService('MetricsManager');

// Configuration (passed during gCore initialization)
$config = [
    'enabled' => true,
    'site_id' => 'my_site',
    'use_gnode' => true,
    'latency_window' => 3600,      // 1 hour default for latency stats
    'details_limit' => 100,        // How many detail entries to return
    'gnode_client' => $gNodeClient     // gNode-Client instance
];
```

## Public API Reference

### Singleton & Lifecycle

#### `getInstance(): ModuleInterface`
Returns the singleton instance of MetricsManager.

#### `initialize(array $config = []): void`
Initializes the metrics reader with configuration.

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getStatus(): array`
Get full status including mode and connection info.

```php
[
    'initialized' => true,
    'enabled' => true,
    'site_id' => 'my_site',
    'has_gnode_client' => true,
    'has_storage' => false,
    'mode' => 'gnode_client'  // gnode_client | storage_adapter | read_only
]
```

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

### Core Metrics Reading

#### `getAllMetrics(): array`
Get all metrics counters for the site.
- **Returns**: Associative array of `metric_name => count`

```php
$all = $metrics->getAllMetrics();
// ['cache_hits' => 1234, 'cache_misses' => 56, 'locks_acquired' => 789, ...]
```

#### `getMetric(string $metricType): int`
Get a specific metric counter.
- **Parameters**:
  - `$metricType`: Metric type (e.g., 'cache_hits', 'locks_acquired')
- **Returns**: Integer count

```php
$hits = $metrics->getMetric('cache_hits');  // 1234
```

#### `getMetricDetails(string $metricType, ?int $limit = null): array`
Get detailed entries for a specific metric type.
- **Parameters**:
  - `$metricType`: Metric type
  - `$limit`: Max entries to return (default from config)
- **Returns**: List of detailed metric entries

```php
$details = $metrics->getMetricDetails('cache_misses', 50);
// Returns last 50 cache miss events with context
```

#### `getLatencyStats(?int $windowSeconds = null): array`
Get latency statistics within a time window.
- **Parameters**:
  - `$windowSeconds`: Time window (default from config, typically 3600)
- **Returns**: Latency statistics

```php
$latency = $metrics->getLatencyStats(3600);
// [
//     'count' => 1000,
//     'window_seconds' => 3600,
//     'min' => 0.5,
//     'max' => 150.3,
//     'avg' => 12.4,
//     'p50' => 8.2,
//     'p95' => 45.6,
//     'p99' => 98.1
// ]
```

#### `getGlobalMetrics(): array`
Get global aggregated metrics (cross-site).
- **Returns**: Global metrics counters

### Aggregated Views

#### `getSummary(): array`
Get metrics summary with categorized statistics.
- **Returns**: Categorized metrics summary

```php
$summary = $metrics->getSummary();
// [
//     'site_id' => 'my_site',
//     'timestamp' => 1704672000,
//     'total_metrics' => 42,
//     'categories' => [
//         'cache' => ['cache_hits' => 1234, 'cache_misses' => 56],
//         'locks' => ['locks_acquired' => 789, 'locks_released' => 788],
//         'streams' => [...],
//         'transactions' => [...],
//         'errors' => [...],
//         'other' => [...]
//     ],
//     'latency' => [/* latency stats */]
// ]
```

#### `getCacheHitRatio(): ?float`
Get cache hit ratio (0.0-1.0).
- **Returns**: Hit ratio or null if no data

```php
$ratio = $metrics->getCacheHitRatio();  // 0.956 (95.6% hit rate)
```

#### `getOpsPerSecond(string $metricType, int $windowSeconds = 60): float`
Get operations per second estimate.
- **Note**: Requires time-series data from Lua METRICS_AGGREGATE
- **Returns**: Operations per second (0.0 if not available)

### Manual Metric Recording

#### `trackMetric(string $metricType, int $value = 1, ?array $extra = null): bool`
Track a metric by calling the ValKey TRACK_METRIC Lua function.
- **Parameters**:
  - `$metricType`: Metric type name
  - `$value`: Value to increment by (default 1)
  - `$extra`: Optional extra data to store
- **Returns**: Success status

```php
$metrics->trackMetric('custom_event', 1, ['user_id' => '123']);
```

## Usage Examples

### Basic Metrics Dashboard

```php
$metrics = gCore::getService('MetricsManager');

// Get overview
$summary = $metrics->getSummary();
echo "Total Metrics Tracked: " . $summary['total_metrics'] . "\n";

// Cache performance
$hitRatio = $metrics->getCacheHitRatio();
echo "Cache Hit Ratio: " . number_format($hitRatio * 100, 1) . "%\n";

// Latency analysis
$latency = $metrics->getLatencyStats();
echo "P99 Latency: " . $latency['p99'] . "ms\n";
echo "Average Latency: " . round($latency['avg'], 2) . "ms\n";
```

### Monitoring Specific Metrics

```php
// Check specific counters
$errors = $metrics->getMetric('error_count');
$warnings = $metrics->getMetric('warning_count');

if ($errors > 100) {
    // Alert on high error count
    notifyAdmin("High error count: $errors");
}

// Get error details
$errorDetails = $metrics->getMetricDetails('errors', 10);
foreach ($errorDetails as $error) {
    logError($error);
}
```

### Category-Based Analysis

```php
$summary = $metrics->getSummary();

// Analyze cache category
if (isset($summary['categories']['cache'])) {
    $cache = $summary['categories']['cache'];
    $total = ($cache['cache_hits'] ?? 0) + ($cache['cache_misses'] ?? 0);

    echo "Cache Operations: $total\n";
    echo "Hit Rate: " . round(($cache['cache_hits'] / $total) * 100, 1) . "%\n";
}

// Analyze lock contention
if (isset($summary['categories']['locks'])) {
    $locks = $summary['categories']['locks'];
    $acquired = $locks['locks_acquired'] ?? 0;
    $failed = $locks['locks_failed'] ?? 0;

    if ($failed > 0) {
        echo "Lock Contention Rate: " . round(($failed / ($acquired + $failed)) * 100, 1) . "%\n";
    }
}
```

## Metric Categories

MetricsManager automatically categorizes metrics based on name patterns:

| Category | Pattern Matches | Examples |
|----------|-----------------|----------|
| `cache` | cache, hit, miss | cache_hits, cache_misses |
| `locks` | lock | locks_acquired, locks_failed |
| `streams` | stream, message | messages_sent, stream_reads |
| `transactions` | transaction | transactions_committed |
| `errors` | error, fail | error_count, operation_failed |
| `other` | everything else | custom_metric |

## Latency Percentiles

The `getLatencyStats()` method provides:

- **p50** (Median): 50% of requests are faster
- **p95**: 95% of requests are faster (used for SLOs)
- **p99**: 99% of requests are faster (tail latency)

```php
$latency = $metrics->getLatencyStats(3600);

// SLO Check: p95 should be under 100ms
if ($latency['p95'] > 100) {
    alert("P95 latency exceeded: " . $latency['p95'] . "ms");
}
```

## Mode Detection

```php
$status = $metrics->getStatus();

switch ($status['mode']) {
    case 'gnode_client':
        // Full gNode integration - all features available
        break;
    case 'storage_adapter':
        // ValKey access via adapter - metrics available
        break;
    case 'read_only':
        // No ValKey connection - returns empty data
        break;
}
```

---

*Last Updated: January 2026*
