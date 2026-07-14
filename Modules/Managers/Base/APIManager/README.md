# APIManager with Dynamic Trait Loading

The APIManager is a key component of the gCore framework, providing REST API functionality, request processing, response caching, rate limiting, and performance monitoring as a base-level service.

## Dynamic Trait Loading

Starting with version 3.0.0, the APIManager now uses the geometric topology-based trait loading system which provides:

1. **Dynamic Capability-Based Trait Loading**: Traits are loaded based on capability requirements and service topology.
2. **Configuration-Driven Traits**: Traits are configured via YAML files rather than hardcoded in the class.
3. **N-Dimensional Capability Space**: Traits are positioned in n-dimensional capability space for mathematical discovery.
4. **Simplified Dependency Management**: Automatic management of trait dependencies.
5. **Improved Performance**: Optimized trait loading with capability vector calculations.

## Configuration

Traits are configured at three levels:

1. **Global API Configuration**: `/config/managers/APIManager.yaml`
2. **Trait-Specific Configuration**: `/config/traits/APIManager/{traitName}.yaml`
3. **Service Topology**: `/config/service_topology.yaml`

### Example Configuration

```yaml
# In APIManager.yaml
traits:
  EndpointManagerTrait:
    enabled: true
    dependencies: []
    capabilities:
      endpoint_registration: 1.0
      endpoint_management: 1.0
    features:
      permission_handling: true
      rest_api: true
```

## Available Traits

The APIManager supports the following traits:

| Trait Name | Capabilities | Description |
|------------|--------------|-------------|
| EndpointManagerTrait | endpoint_registration, routing | Handles API endpoint registration and management |
| RequestProcessorTrait | request_handling, middleware | Processes API requests through middleware pipeline |
| ResponseCacheTrait | response_caching, cache | Caches API responses for improved performance |
| RateLimiterTrait | rate_limiting, throttling | Controls API request rates to prevent abuse |
| WebSocketTrait | websocket, realtime | Provides WebSocket functionality for real-time communication |
| AuthenticationTrait | authentication, authorization | Handles API authentication and authorization |
| ValidationTrait | validation, schema_checking | Validates API requests against schemas |
| MetricsCollectorTrait | metrics, monitoring | Collects performance metrics for API operations |

## Usage

The APIManager automatically loads traits based on configuration. You don't need to manually load traits in code.

```php
use gCore\Modules\Managers\Base\APIManager\APIManager;

// Get the APIManager instance
$apiManager = APIManager::getInstance();

// Initialize with configuration
$apiManager->initialize([
    'namespace' => 'my-api/v1',
    'cache_enabled' => true,
    'rate_limiting' => true
]);

// All configured traits are automatically loaded
```

## Dynamic Loading at Runtime

You can also dynamically load and unload traits at runtime:

```php
// Load a trait dynamically
$apiManager->loadTrait('ValidationTrait', [
    'enabled' => true,
    'features' => [
        'json_schema' => true
    ]
]);

// Check if a trait is active
if ($apiManager->hasActiveTrait('ResponseCacheTrait')) {
    // Use functionality provided by the trait
}

// Unload a trait if needed
$apiManager->unloadTrait('WebSocketTrait');
```

## Trait Health Monitoring

You can check the health status of loaded traits:

```php
// Check all traits
$healthStatus = $apiManager->performHealthCheck();

// Check a specific trait
$cacheTraitHealth = $apiManager->performHealthCheck('ResponseCacheTrait');
```

## Capability-Based Discovery

Find traits that provide specific capabilities:

```php
// Find traits with these capabilities
$traits = $apiManager->findTraitsWithCapabilities([
    'authentication' => 0.8,
    'api_keys' => 0.5
]);
```

## Performance Considerations

The trait loading system uses ValKey for state storage, so it's important to ensure proper connection pools are configured for optimal performance.

## Extending with Custom Traits

You can create custom traits for the APIManager by:

1. Creating a trait PHP file in the Traits directory
2. Creating a trait configuration YAML file
3. Registering the trait in service_topology.yaml
4. Updating schemas for validation

See the documentation on creating custom traits for more details.