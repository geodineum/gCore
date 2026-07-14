# gCore Framework Documentation

## Overview

gCore is a distributed PHP framework designed to provide service management with zero local state and geometric topology-based capability discovery. It offers WordPress integration capabilities while maintaining compatibility with standalone PHP applications, Docker environments, and CLI tools.

The core of the framework is composed of four main managers (the Manager Quartet):
- **SecurityManager** - Manages security, authentication, data validation, and cryptographic operations
- **ErrorManager** - Provides distributed error handling, logging, and notification
- **CacheManager** - Offers high-performance distributed caching with advanced features like streams and batch operations
- **APIManager** - Handles REST API endpoints, middleware processing, authentication, and server modes

All managers follow a trait-based architecture where functionality can be dynamically composed at runtime based on configuration and actual usage patterns.

## System Architecture

The gCore framework uses a geometric topology approach to service discovery and capability management. Services are represented as points in an n-dimensional capability space, allowing for mathematical precision in service matching and discovery.

Key architectural principles:
- **Zero Local State**: All services maintain state in ValKey (Redis fork) for true distribution
- **Trait Composition**: Features are implemented as traits that can be loaded dynamically
- **Mathematical Approach**: Service discovery uses geometric principles for O(1) operations
- **Multi-site Support**: Built-in isolation for multi-tenant/multi-site deployments
- **Security-First**: Security is a core architectural component, not an afterthought

## Installation and Setup

### Requirements
- PHP 7.2 or higher (8.0+ recommended)
- ValKey or Redis server (6.0+ recommended)
- PHP extensions: redis, json, mbstring, igbinary, ffi (optional)

### Basic Installation
1. Clone the repository
2. Install dependencies with Composer
3. Configure the .env file with ValKey/Redis connection details
4. Initialize the system with `php gCoreCLI.php init`

### WordPress Integration
When used with WordPress:
1. Install as a plugin by placing in the wp-content/plugins directory
2. Activate the plugin through the WordPress admin interface
3. Configure settings through the gCore admin panel in WordPress

## Core Managers

### 1. gCore System

The gCore class is the central orchestrator of the entire system. It handles initialization, service management, and dependency resolution.

#### Key Functions

```php
// Include the standalone initialization script
require_once 'gcore-standalone.php';

// Initialize the system
$gCore = gcore_init([
    'core' => [
        'environment' => 'production',
        'debug' => false
    ],
    'site_id' => 'my_site',
    'node_id' => 'node1'
]);

// Get manager instances using helper functions
$errorManager = gcore_get_error_manager();
$cacheManager = gcore_get_cache_manager();
$securityManager = gcore_get_security_manager();
$apiManager = gcore_get_api_manager();

// Or get a service directly through gCore
$anotherService = $gCore->getService('CustomService');

// Check system status
$status = $gCore->getStatus();

// Shutdown the system when done
gcore_shutdown();
```

#### Configuration Options

- `core.environment`: The environment (production, development, staging, wordpress)
- `core.debug`: Enable debug mode
- `site_id`: Unique site identifier for multi-tenant isolation
- `node_id`: Unique node identifier within a site
- `service`: Host, port, and protocol configuration

### 2. Security Manager

The SecurityManager handles authentication, data validation, encryption, and overall security of the application.

#### Key Functions

```php
// Initialize
$securityManager = new \gCore\Modules\Managers\Base\SecurityManager\SecurityManager();
$securityManager->initialize([
    'site_id' => 'my_site',
    'node_id' => 'security_node',
    'environment' => 'production'
]);

// Encryption (requires CryptoTrait)
$encrypted = $securityManager->encrypt('sensitive data');
$decrypted = $securityManager->decrypt($encrypted);

// Data validation (requires SanitationTrait)
$cleaned = $securityManager->sanitize($_POST['user_input'], 'text');
$isValid = $securityManager->validate($data, 'email');

// Security monitoring (requires SecurityMonitoringTrait)
$securityManager->logSecurityEvent('login_attempt', [
    'username' => $username,
    'ip' => $_SERVER['REMOTE_ADDR'],
    'success' => $success
]);

// Get security status
$status = $securityManager->getStatus();
```

#### Available Traits

- **CryptoTrait**: Provides encryption and decryption capabilities
- **AdvancedCryptoTrait**: Adds hardware security module support
- **SanitationTrait**: Input validation and sanitization
- **SecurityRulesTrait**: Implementation of security rules and policies
- **SecurityMonitoringTrait**: Security event monitoring and alerting
- **HeaderSecurityTrait**: HTTP security headers management
- **XSSPreventionTrait**: Cross-site scripting prevention
- **WordPressIntegrationTrait**: WordPress-specific security features

### 3. Error Manager

The ErrorManager provides error tracking, logging, and notification capabilities with full multi-tenant isolation.

#### Key Functions

```php
// Initialize
$errorManager = new \gCore\Modules\Core\Managers\Base\ErrorManager();
$errorManager->initialize([
    'site_id' => 'my_site',
    'node_id' => 'error_node',
    'environment' => 'production',
    'admin_email' => 'admin@example.com'
]);

// Log errors with different severity levels
$errorManager->logError('Database connection failed', [
    'host' => $host,
    'error' => $e->getMessage()
], 'CRITICAL', 'DATABASE');

// Process error queue (for batch processing)
$errorManager->processErrorQueue();

// Get error metrics
$metrics = $errorManager->getMetrics();

// Check system health
$health = $errorManager->getClusterHealth();
```

#### Error Severity Levels

- `DEBUG`: Development information (level 100)
- `INFO`: Informational messages (level 200)
- `NOTICE`: Normal but significant events (level 250)
- `WARNING`: Warning conditions (level 300)
- `ERROR`: Error conditions (level 400)
- `CRITICAL`: Critical conditions (level 500)
- `ALERT`: Action must be taken immediately (level 550)
- `EMERGENCY`: System is unusable (level 600)

#### Error Categories

- `SYSTEM`: System-level errors
- `SECURITY`: Security-related issues
- `CACHE`: Caching system errors
- `API`: API-related errors
- `DATABASE`: Database errors
- `PERFORMANCE`: Performance issues
- `USER`: User-related errors
- `CONTENT`: Content-related errors

### 4. Cache Manager

The CacheManager provides high-performance distributed caching with ValKey/Redis backend, supporting advanced features like streams, transactions, and locks.

#### Key Functions

```php
// Initialize
$cacheManager = new \gCore\Modules\Managers\Base\CacheManager\CacheManager();
$cacheManager->initialize([
    'host' => '127.0.0.1',
    'port' => 6379,
    'auth' => 'password',
    'prefix' => 'mycache_',
    'timeout' => 2.0,
    'streams' => [
        'enabled' => true
    ]
]);

// Basic cache operations
$cacheManager->set('user:123', $userData, 'users', 3600);
$userData = $cacheManager->get('user:123', null, 'users');
$cacheManager->delete('user:123', 'users');

// Multiple operations
$cacheManager->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2'
], 'group1', 3600);

$values = $cacheManager->getMultiple(['key1', 'key2'], 'group1');

// Atomic operations
$cacheManager->increment('counter', 1);
$cacheManager->decrement('counter', 1);

// Locks
$cacheManager->acquireLock('resource_lock', 30);
// Do something with exclusive access
$cacheManager->releaseLock('resource_lock');

// Transactions
$txId = $cacheManager->beginTransaction();
// Perform multiple operations
$cacheManager->commitTransaction($txId);
// Or if error
$cacheManager->rollbackTransaction($txId);

// Stream operations (requires StreamCapabilities trait)
$cacheManager->streamAdd('events', ['type' => 'user_login', 'user_id' => 123]);
$cacheManager->streamCreateGroup('events', 'processors');
$cacheManager->streamConsume('events', 'processors', 'worker1', function($message) {
    // Process message
});
```

#### Advanced Features

- **Group Management**: Organize cache entries by groups
- **Batch Operations**: Efficient multi-key operations
- **Distributed Locks**: Exclusive resource access across nodes
- **Transactions**: Atomic multi-operation sequences
- **Streams**: Stream processing capabilities (with StreamCapabilities trait)
- **Rate Limiting**: Built-in rate limiting for operations
- **Circuit Breaker**: Prevent cascading failures
- **Connection Pooling**: Efficient connection management

### 5. API Manager

The APIManager provides REST API management with endpoint registration, request processing with middleware, response caching, rate limiting, and metrics collection.

#### Key Functions

```php
// Initialize
$apiManager = gcore_get_api_manager();

// Or initialize directly
$apiManager = new \gCore\Modules\Managers\Base\APIManager\APIManager();
$apiManager->initialize([
    'site_id' => 'my_site',
    'node_id' => 'api_node',
    'namespace' => 'myapp/v1',
    'cache_enabled' => true,
    'rate_limiting' => true,
    'server' => [
        'mode' => 'auto',   // Options: 'auto', 'standalone', 'integrated', 'disabled'
        'port' => 8000,     // Port for standalone server
        'host' => '0.0.0.0' // Host for standalone server
    ]
]);

// Register an endpoint with path parameters
$apiManager->registerEndpoint('users/{id}', [
    'methods' => 'GET',
    'callback' => function($request) {
        $userId = $request['path_params']['id'];
        return [
            'user' => [
                'id' => $userId,
                'name' => 'Example User'
            ]
        ];
    }
]);

// Add middleware
$apiManager->addMiddleware('auth', function($request, $next) {
    if (!isset($request['headers']['X-API-Key'])) {
        return [
            'status' => 'error',
            'code' => 401,
            'message' => 'Unauthorized'
        ];
    }
    return $next($request);
});

// Apply middleware to endpoints
$apiManager->registerEndpoint('secure/data', [
    'methods' => 'GET',
    'middleware' => ['auth'],
    'callback' => function() {
        return ['data' => 'Secure data'];
    }
]);

// In WordPress context
add_action('rest_api_init', function() use ($apiManager) {
    $apiManager->registerEndpoint('example', [
        'methods' => 'GET',
        'callback' => function($request) {
            return ['message' => 'Example endpoint'];
        },
        'permission_callback' => function() {
            return current_user_can('read');
        }
    ]);
});

// Start the server (in standalone mode)
$apiManager->startServer();

// Get API status
$status = $apiManager->getAPIStatus();

// Clear API cache
$apiManager->clearCache();

// Get API metrics
$metrics = $apiManager->getMetrics();
```

#### Available Traits

- **EndpointManagerTrait**: API endpoint registration and management with path parameter support
- **RequestProcessorTrait**: Request processing pipeline with middleware for authentication, validation, etc.
- **ResponseCacheTrait**: Efficient response caching with cache invalidation strategies
- **RateLimiterTrait**: API rate limiting with headers and configurable limits
- **WebSocketTrait**: WebSocket support for real-time communication
- **AuthenticationTrait**: API authentication methods (API keys, JWT, etc.)
- **ValidationTrait**: Request validation with validation rules
- **MetricsCollectorTrait**: API usage metrics collection for monitoring

#### Server Modes

The APIManager supports multiple server operation modes:

- **Auto Mode** (default): Automatically detects the environment and chooses appropriate behavior
  - In CLI environments: Starts PHP's built-in server
  - In web server environments: Sets up request handlers for the existing web server
  - In test environments: Skips actual server startup

- **Standalone Mode**: Forces the use of PHP's built-in server regardless of environment
  - Useful for development and testing
  - Self-contained API server without external dependencies
  - Configurable host and port settings

- **Integrated Mode**: Forces integration with an existing web server
  - Optimized for production environments with Apache/Nginx
  - Uses the existing web server's routing and processing capabilities
  - Better performance and security for production deployments

- **Disabled Mode**: Prevents any server from starting
  - Useful for applications that need the API structure but handle HTTP requests differently
  - Can be used when integrating with custom server implementations

## Configuration System

gCore uses a hierarchical configuration system with YAML files. Configuration can be provided through:
1. Default configuration files
2. Environment-specific configuration
3. Runtime configuration

### Configuration Files

- `default_config.yaml`: Base configuration for all environments
- `environments/{env}.yaml`: Environment-specific configuration
- `service_topology.yaml`: Service topology configuration
- `dependencies.yaml`: Service dependency configuration
- `traits/{manager}/{trait}.yaml`: Trait-specific configuration

### Configuration Hierarchy (Priority Order)

1. Runtime configuration (passed to `initialize()`)
2. Environment variables
3. Environment-specific configuration
4. Default configuration

## Capability-based Service Discovery

gCore uses a capability-based service discovery system, powered by the geometric topology component. Services are represented as points in an n-dimensional capability space, with capabilities as dimensions. 

This mathematical approach allows for:
1. O(1) service discovery based on required capabilities
2. Precise service matching with capability vectors
3. Automatic scaling with capability manifolds
4. Zero-trust verification through capability boundaries

### Using the Geometric Topology

```php
// Initialize topology
$topology = new \gCore\Modules\Core\Topology\GeometricTopology();
$topology->initialize([
    'dimensions' => 5,
    'grid_size' => 10
]);

// Register service with capabilities
$capabilities = [
    'api' => 0.8,
    'auth' => 0.9,
    'cache' => 0.7
];
$topology->registerService('service1', $capabilities);

// Find services by required capabilities
$requirements = [
    'api' => 0.5,
    'auth' => 0.5
];
$services = $topology->findServices($requirements);
```

## WordPress Integration

gCore can be used as a WordPress plugin, providing advanced capabilities within the WordPress ecosystem.

### WordPress-specific Features

- **Admin Interface**: Complete admin interface in WordPress admin area
- **WP REST API Integration**: Integration with WordPress REST API
- **Dashboard Widgets**: Status widgets for WordPress dashboard
- **Role-based Access**: Integration with WordPress roles and capabilities
- **Plugin Activation/Deactivation**: Proper setup and cleanup handlers
- **Multi-site Support**: Support for WordPress multi-site installations

### WordPress Hooks

```php
// Register custom endpoint
add_action('rest_api_init', [$apiManager, 'registerRestRoutes']);

// Add admin pages
add_action('admin_menu', [$apiManager, 'registerAdminPages']);

// Enqueue admin assets
add_action('admin_enqueue_scripts', [$apiManager, 'enqueueAdminAssets']);

// Handle post updates for cache invalidation
add_action('save_post', [$apiManager, 'invalidateCacheOnSave']);
```

## Multi-tenant / Multi-site Support

gCore provides multi-tenant isolation through site-specific prefixing and geometric boundary transforms.

### Multi-tenant Features

- **Site Isolation**: Complete data isolation between sites
- **Node Discovery**: Cross-site service discovery
- **Hierarchical Configuration**: Inheritance of configuration
- **Territory Concept**: Site roles (Settlement, City, Capital, etc.)
- **Metrics Aggregation**: Cross-site metrics collection

## ValKey Integration

gCore uses ValKey (a Redis fork) as its distributed backend. The ValKey integration provides:

- **Zero Local State**: All state maintained in ValKey
- **Lua Scripts**: Optimized operations using embedded Lua scripts
- **Connection Pooling**: Efficient connection management
- **Batch Operations**: High-performance multi-key operations
- **Streams Support**: Advanced stream processing capabilities
- **Distributed Locking**: Cluster-safe resource locking
- **Transactions**: Atomic operations across keys
- **Pub/Sub**: Real-time messaging across nodes

## Security Best Practices

gCore implements security best practices throughout the system:

- **Zero Trust Model**: No implicit trust of any component
- **Defense in Depth**: Multiple security layers
- **Capability Verification**: Mathematical verification of capabilities
- **Content Security Policy**: Strict CSP implementation
- **HTTPS Enforcement**: HSTS headers and secure transport
- **Input Validation**: input validation
- **Error Isolation**: Errors isolated to prevent information disclosure
- **Hardware Security**: Optional hardware security module integration

## Troubleshooting and FAQ

### Common Issues

1. **ValKey Connection Issues**
   - Ensure ValKey server is running
   - Check connection credentials
   - Verify network connectivity

2. **Trait Loading Failures**
   - Verify trait configuration
   - Check for circular dependencies
   - Ensure trait path is correct

3. **WordPress Integration Problems**
   - Verify WordPress version compatibility
   - Check plugin activation status
   - Ensure all required PHP extensions are installed

### FAQ

**Q: Can gCore work without ValKey/Redis?**
A: No, ValKey or Redis is required for core functionality as it provides the distributed backend for zero local state.

**Q: Is gCore compatible with shared hosting?**
A: Limited compatibility. gCore works best on environments where you control the PHP configuration and have Redis/ValKey available. Docker deployment is recommended for easier setup.

**Q: Can I use gCore without WordPress?**
A: Yes, gCore can be used in standalone PHP applications, with Docker, or via the CLI tools.

**Q: What's the minimum PHP version required?**
A: PHP 7.2+, but 8.0+ is recommended for better performance.

**Q: How does gCore handle high traffic?**
A: gCore uses connection pooling, batched operations, and caching strategies to handle high traffic efficiently. The framework includes circuit breaker patterns, exponential backoff with jitter, and adaptive timeout adjustment.

**Q: Does gCore support API server functionality out of the box?**
A: Yes, the APIManager provides a complete API server with support for multiple operation modes, middleware processing, path parameters, and rate limiting.

**Q: Is gCore production-ready?**
A: All core managers are operational and have been validated through testing, including the MessageBroker example which integrates all four managers. See the test suite for current coverage.

**Q: How can I monitor gCore's performance?**
A: Each manager provides built-in metrics collection and monitoring capabilities. The CLI tools also include monitoring commands for system health.

## API Reference

For a complete API reference, please see the individual manager documentation:
- [Security Manager API](Security.md)
- [Error Manager API](Component-ErrorManager.md)
- [Cache Manager API](Component-CacheManager.md)
- [API Manager API](Component-APIManager.md)