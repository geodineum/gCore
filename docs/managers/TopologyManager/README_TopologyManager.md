# TopologyManager Documentation

## Overview

TopologyManager provides service mesh topology visualization and management using gNode's geometric discovery capabilities. It enables n-dimensional capability space visualization (up to 23 dimensions: 19 discovery + 4 storage-only), service registration with the 23-dimension schema, and capability-based service discovery.

**Namespace**: `gCore\Modules\Managers\Base\TopologyManager`
**Implements**: `ModuleInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)
**Requires**: gNode integration (no fallback mode)

## Architecture

TopologyManager operates as a **gNode-only service mesh coordinator**:

1. **Service Registration**: Registers WordPress instances with 23-dimension schema
2. **Geometric Discovery**: Finds services by capability requirements
3. **Topology Visualization**: Provides 3D visualization data
4. **Smart Registration**: Hash-based idempotency prevents redundant registrations

### 23-Dimension Capability Schema (19 discovery + 4 storage-only)

```
Layer 1: Interface Identity (0-3)
  - protocol, native_format, api_version, contract_stability

Layer 2: Access Control (4-6)
  - clearance_required, auth_method, data_sensitivity

Layer 3: Service Scope (7)
  - service_scope

Layer 4: Functional Domain (8-10)
  - domain_primary, domain_secondary, specialization

Layer 5: Performance Profile (11-13)
  - throughput_tier, latency_class, reliability_tier

Layer 6: Workflow Context (14-15)
  - pipeline_stage, execution_priority

Layer 7: Runtime State (16)
  - current_load

Layer 8: Classification (17-18)
  - service_tier, environment

Layer 9: Visual Topology (19-21) — storage-only, user-set
  - user_x, user_y, user_z

Layer 10: Temporal (22) — storage-only, auto-injected
  - registration_order
```

## Initialization

```php
// Get TopologyManager instance via gCore
$topology = gCore::getService('TopologyManager');

// Configuration (passed during gCore initialization)
$config = [
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'use_gnode' => true,
    'gnode_client' => $gNodeClient,
    'cache_enabled' => true,
    'auto_register_service' => true,
    'smart_register' => true,           // Hash-based idempotent registration
    'capabilities' => [],               // Theme-provided capabilities
    'metadata' => [],                   // Theme-provided metadata
    'default_dimensions' => 9,
    'max_dimensions' => 16,
    'visualization_refresh_rate' => 5,  // seconds
    'api_discovery' => true,            // Introspect endpoints
    'debug' => false
];
```

## Public API Reference

### Singleton & Lifecycle

#### `getInstance(): ModuleInterface`
Returns the singleton instance of TopologyManager.

#### `initialize(array $config = []): void`
Initializes the topology system with configuration.
- **Throws**: `InitializationException` if gNode not available

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getStatus(): array`
Get full status including service ID, dimensions, and statistics.

```php
[
    'initialized' => true,
    'gnode_enabled' => true,
    'current_service_id' => 'my_site_wordpress_node1',
    'dimensions_active' => 23,
    'statistics' => [...],
    'site_id' => 'my_site',
    'node_id' => 'node1'
]
```

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

#### `getCapabilityVector(): array`
Get capability vector for gNode registration.

### Service Registration

#### `smartRegister(array $capabilities = [], array $metadata = [], bool $force = false): bool`
Smart registration with hash-based idempotency.
- **Parameters**:
  - `$capabilities`: Capability vector (merged with collected)
  - `$metadata`: Service metadata
  - `$force`: Force registration even if hash matches
- **Returns**: Success status

```php
// Auto-collects capabilities from managers
$topology->smartRegister();

// With custom capabilities
$topology->smartRegister([
    'ml_inference' => 0.9,
    'data_processing' => 0.8
], [
    'name' => 'ML Processing Site'
]);
```

#### `forceRegister(array $capabilities = [], array $metadata = []): bool`
Force re-registration (clears hash and registers).
- **Returns**: Success status

#### `getRegistrationStatus(): array`
Get registration status information.
- **Returns**: Registration status

```php
[
    'registered' => true,
    'hash' => 'abc123...',
    'registered_at' => '2026-01-08 10:30:00',
    'service_id' => 'my_site_wordpress_node1',
    'site_id' => 'my_site',
    'node_id' => 'node1'
]
```

#### `deregister(): bool`
Deregister current service from gNode topology.
- **Returns**: Success status

#### `updateCapabilities(array $capabilities, array $metadata = []): bool`
Update capabilities dynamically without full re-registration.
- **Parameters**:
  - `$capabilities`: New or updated capabilities (merged)
  - `$metadata`: Optional metadata updates
- **Returns**: Success status

#### `refreshRegistration(?callable $configLoader = null): array`
Reload configuration and re-register if changed.
- **Returns**: Result with 'changed' and 'registered' status

#### `isRegisteredInTopology(): bool`
Check if service is currently registered in gNode topology.
- Queries gNode directly (not just local hash check)

### Geometric Discovery

#### `discoverServices(array $requirements, int $limit = 10, int $distance = 0): array`
Discover services based on capability requirements.
- **Parameters**:
  - `$requirements`: Capability requirements `[dimension => minScore]`
  - `$limit`: Maximum services to return
  - `$distance`: Maximum distance in capability space (0 = exact)
- **Returns**: Discovered services with distances

```php
$services = $topology->discoverServices([
    'ml_inference' => 0.9,
    'data_processing' => 0.7
], 10);

// Returns array with:
// - service_id
// - capabilities
// - metadata
// - distance
// - is_current (bool)
```

#### `findServices(array $requirements): array`
Find services matching requirements.
- **Returns**: Matching services

#### `getServiceDetails(string $serviceId): array`
Get service details by ID.
- **Returns**: Service details including capabilities and metadata

#### `calculateDistance(array $service1Caps, array $service2Caps): array`
Calculate distance between two services in capability space.
- **Returns**: Distance information `['distance' => float, 'dimensions' => [...]]`

#### `getLoadSequence(string $group = 'default'): array`
Get optimal load sequence for services in a group.
- **Returns**: Ordered service list

#### `storeTopology(array $topology, int $dimensions = 9): bool`
Store complete topology data.
- **Returns**: Success status

### Dimension Management

#### `registerDimension(string $name, array $config = []): bool`
Register a custom dimension.
- **Parameters**:
  - `$name`: Dimension name
  - `$config`: Dimension configuration
- **Returns**: Success status

```php
$topology->registerDimension('profit', [
    'label' => 'Profit Margin',
    'type' => 'metric',
    'min' => 0,
    'max' => 100,
    'unit' => '%',
    'description' => 'Service profit contribution'
]);
```

#### `getDimensions(): array`
Get all registered dimensions.

#### `getDimension(string $name): ?array`
Get dimension by name.

#### `getCapabilityDimensions(): array`
Get capability dimensions from gNode.

### Topology Visualization

#### `getTopologyVisualization(array $selectedDimensions, array $filters = []): array`
Get topology data for 3D visualization.
- **Parameters**:
  - `$selectedDimensions`: `['x' => 'dim1', 'y' => 'dim2', 'z' => 'dim3']`
  - `$filters`: Optional filters
- **Returns**: Visualization data

```php
$viz = $topology->getTopologyVisualization([
    'x' => 'ml_inference',
    'y' => 'current_load',
    'z' => 'throughput_tier'
]);

// Returns:
// [
//     'nodes' => [...],
//     'edges' => [...],
//     'current_node' => {...},
//     'dimensions' => {...},
//     'statistics' => {...}
// ]
```

## Service Tiers

The 23-dimension schema includes tier classification (dimension 17):

| Tier | Coordinate | Description |
|------|------------|-------------|
| TOOL | 0.10 | Global utilities, managers |
| SERVICE | 0.30 | Business logic, WordPress sites |
| FORUM | 0.50 | Discovery, registry services |
| AQUEDUCT | 0.70 | Data pipelines, ETL |
| ROME | 0.90 | gNode daemons, orchestrators |

## Environments (DTAP)

Environment classification (dimension 18):

| Environment | Coordinate |
|-------------|------------|
| global | 0.00 |
| testing | 0.25 |
| staging | 0.50 |
| acceptance | 0.75 |
| production | 1.00 |

## Usage Examples

### Basic Service Discovery

```php
$topology = gCore::getService('TopologyManager');

// Find ML-capable services
$mlServices = $topology->discoverServices([
    'ml_inference' => 0.8
], 5);

foreach ($mlServices as $service) {
    echo $service['service_id'] . ': ' . $service['distance'] . "\n";
}
```

### Registration with Custom Capabilities

```php
// WordPress theme provides capabilities via registration.yaml
$topology->smartRegister([
    'e_commerce' => 0.95,
    'payment_processing' => 0.9,
    'inventory_management' => 0.8
], [
    'name' => 'E-Commerce Site',
    'url' => 'https://shop.example.com',
    'tier' => 'SERVICE',
    'environment' => 'production'
]);
```

### 3D Visualization

```php
$viz = $topology->getTopologyVisualization([
    'x' => 'ml_inference',
    'y' => 'current_load',
    'z' => 'reliability_tier'
]);

// Use for Three.js rendering
echo json_encode([
    'nodes' => $viz['nodes'],
    'edges' => $viz['edges'],
    'camera_target' => $viz['current_node']['coordinates'] ?? null
]);
```

### Dynamic Capability Updates

```php
// Update load at runtime (doesn't require full re-registration)
$topology->updateCapabilities([
    'current_load' => 0.75  // 75% loaded
]);
```

## Cache TTLs

```php
CACHE_TTL_TOPOLOGY = 300;     // 5 minutes
CACHE_TTL_SERVICES = 60;      // 1 minute
CACHE_TTL_DIMENSIONS = 3600;  // 1 hour
```

## Statistics Tracked

```php
[
    'services_registered' => 0,
    'services_discovered' => 0,
    'dimensions_active' => 0,
    'topology_updates' => 0,
    'cache_hits' => 0,
    'cache_misses' => 0
]
```

---

*Last Updated: January 2026*
