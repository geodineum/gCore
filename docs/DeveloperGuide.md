# gCore Framework: Developer Documentation

## Introduction

gCore is a PHP framework built on a mathematical foundation of n-dimensional capability space. It provides a flexible architecture for building secure applications with a focus on scalability and maintainability.

This guide will help you quickly understand gCore's capabilities and get started building applications with it.

## Core Architecture

gCore's architecture is centered around four foundational managers that form what we call the "Manager Quartet":

1. **ErrorManager**: Error tracking, logging, and notifications
2. **CacheManager**: Distributed caching with ValKey (Redis-compatible) integration
3. **SecurityManager**: Authentication, authorization, and security features
4. **APIManager**: API endpoint management with middleware support

These managers work together within an novel mathematical model that represents services as points in n-dimensional capability space, enabling dynamic service discovery and feature composition.

## Getting Started

### Installation Options

#### Option 1: Docker Installation (Recommended)

```bash
# Clone the repository
git clone https://github.com/your-org/gcore.git
cd gcore

# Start with Docker Compose
docker-compose up -d
```

#### Option 2: Standalone Installation

```bash
# Clone the repository
git clone https://github.com/your-org/gcore.git
cd gcore

# Install dependencies
composer install

# Compile Rust components (optional but recommended)
./compile.sh

# Test the installation
php test-all.php
```

### Setting Up Your First Application

gCore encourages a clean separation between your application code and the framework. Your custom application code should be placed in the `Source` directory.

1. **Configure Environment**
   - Copy `Source/config/.env.example` to `Source/config/.env`
   - Edit with your environment-specific settings
   - Modify `Source/config/custom_config.yaml` as needed

2. **Create Your Application**
   - Use `Source/MyApp.php` as a starting template
   - Initialize the framework and access managers:

```php
// Initialize gCore
require_once __DIR__ . '/../gcore-standalone.php';

// Load custom configuration
$customConfig = []; // Your configuration loading logic here

// Initialize gCore with your custom configuration
$gCore = gcore_init($customConfig);

// Access the core managers
$errorManager = gcore_get_error_manager();
$cacheManager = gcore_get_cache_manager();
$securityManager = gcore_get_security_manager();
$apiManager = gcore_get_api_manager();
```

## Using Core Managers

### Error Management

The ErrorManager handles error tracking, logging, and notifications:

```php
// Log errors with context
$errorManager->trackError('error', 'Database connection failed', [
    'host' => $dbHost,
    'error_code' => $errorCode
]);

// Log exceptions
try {
    // Your code
} catch (\Exception $e) {
    $errorManager->logException($e, [
        'operation' => 'user_registration',
        'user_data' => $sanitizedData
    ]);
}
```

### Data Caching

The CacheManager provides distributed caching with ValKey integration:

```php
// Basic operations
$cacheManager->set('user:123', $userData, 3600); // TTL: 1 hour
$user = $cacheManager->get('user:123');
$cacheManager->delete('user:123');

// Batch operations
$keys = ['user:123', 'user:456', 'user:789'];
$users = $cacheManager->getMultiple($keys);

// Hash operations
$cacheManager->hashSet('user:123:data', 'profile', $profileData);
$profile = $cacheManager->hashGet('user:123:data', 'profile');

// Advanced script-based operations
$result = $cacheManager->runScript(
    'BATCH_SET',
    ['user:batch:keys'],
    [json_encode($batchData)]
);
```

### Security Features

The SecurityManager handles authentication, authorization, and security:

```php
// Define roles and permissions
$securityManager->defineRole('admin', ['manage_users', 'view_reports']);
$securityManager->defineRole('user', ['view_profile']);

// Assign roles
$securityManager->assignRole('user123', 'admin');

// Check permissions
if ($securityManager->hasPermission('user123', 'manage_users')) {
    // Perform admin action
}

// Sanitize input
$cleanData = $securityManager->sanitize($userData, [
    'name' => 'string',
    'email' => 'email',
    'age' => 'integer'
]);
```

### API Management

The APIManager handles API routing, middleware, and request processing:

```php
// Initialize with configuration
$apiManager->initialize([
    'server' => [
        'mode' => 'standalone',
        'port' => 8080,
        'host' => '127.0.0.1'
    ]
]);

// Add authentication middleware
$apiManager->addMiddleware('auth', function($request, $response, $next) {
    $apiKey = $request->header('X-API-Key');
    if (!isValidApiKey($apiKey)) {
        return $response->json(['error' => 'Unauthorized'], 401);
    }
    return $next($request, $response);
});

// Register endpoints
$apiManager->registerEndpoint('GET', '/users/:id', function($request, $response) {
    $userId = $request->param('id');
    $user = getUserById($userId);
    return $response->json($user);
}, [
    'middleware' => ['auth'],
    'cache' => true
]);

// Start the API server
$apiManager->start();
```

## Advanced Features

### Geometric Topology System

gCore's n-dimensional capability space allows services to be discovered based on their capabilities:

```php
// Register a service with capability dimensions
$topology->registerService('MyService', [
    'caching' => 0.8,
    'security' => 0.7,
    'api' => 0.9
]);

// Discover services based on capability requirements
$services = $topology->findServices([
    'caching' => 0.6,  // Requires at least 0.6 in caching dimension
    'security' => 0.5  // Requires at least 0.5 in security dimension
]);
```

### ValKey Script System

One of gCore's most features is its script system for ValKey (Redis-compatible) operations:

```php
// Core operations
$value = $cacheManager->runScript('GET', ['key']);
$result = $cacheManager->runScript('SET', ['key'], ['value', 3600]);

// Batch operations for high performance
$results = $cacheManager->runScript('BATCH_GET', ['batch:keys'], [json_encode($keys)]);

// Transaction management
$result = $cacheManager->runScript(
    'TRANSACTION_EXEC',
    ['transaction:key'],
    [json_encode($operations)]
);
```

### Trait Composition

Extend functionality through capability-aware traits:

```php
use gCore\Modules\Managers\Base\SecurityManager\Traits\XSSPreventionTrait;
use gCore\Modules\Managers\Base\SecurityManager\Traits\HardwareSecurityTrait;

class MySecurityManager extends SecurityManager {
    use XSSPreventionTrait;
    use HardwareSecurityTrait;
}
```

## Real-World Example: Message Broker

The gCore Message Broker example demonstrates integration of all four core managers:

```php
class MessageBroker {
    private $cacheManager;
    private $errorManager;
    private $securityManager;
    private $apiManager;
    
    public function __construct($cacheManager, $errorManager, $securityManager, $apiManager) {
        $this->cacheManager = $cacheManager;
        $this->errorManager = $errorManager;
        $this->securityManager = $securityManager;
        $this->apiManager = $apiManager;
    }
    
    public function initialize() {
        // Register API endpoints
        $this->apiManager->registerEndpoint('POST', '/queues', [$this, 'createQueue']);
        $this->apiManager->registerEndpoint('GET', '/queues', [$this, 'listQueues']);
        $this->apiManager->registerEndpoint('DELETE', '/queues/:queue', [$this, 'deleteQueue']);
        $this->apiManager->registerEndpoint('POST', '/messages/:queue', [$this, 'publishMessage']);
        $this->apiManager->registerEndpoint('GET', '/messages/:queue', [$this, 'consumeMessage']);
        
        // Add authentication middleware
        $this->apiManager->addMiddleware('auth', function($request, $response, $next) {
            // Validate API key
            $apiKey = $request->header('X-API-Key');
            $user = $this->securityManager->getUserByApiKey($apiKey);
            
            if (!$user) {
                return $response->json(['error' => 'Unauthorized'], 401);
            }
            
            return $next($request->withAttribute('user', $user), $response);
        });
    }
    
    public function createQueue($request, $response) {
        try {
            $data = $request->json();
            $user = $request->getAttribute('user');
            
            // Check permissions
            if (!$this->securityManager->hasPermission($user['id'], 'create_queue')) {
                return $response->json(['error' => 'Permission denied'], 403);
            }
            
            // Validate input
            $queueName = $this->securityManager->sanitize($data['name'], 'string');
            
            // Create queue in cache
            $queueKey = "queue:{$queueName}:meta";
            $this->cacheManager->hashMultipleSet($queueKey, [
                'created_by' => $user['id'],
                'created_at' => time(),
                'message_count' => 0
            ]);
            
            // Log success
            $this->errorManager->trackError('info', 'Queue created', [
                'queue' => $queueName,
                'user_id' => $user['id']
            ]);
            
            return $response->json(['status' => 'success', 'queue' => $queueName]);
        } catch (\Exception $e) {
            $this->errorManager->logException($e, [
                'operation' => 'create_queue',
                'request_data' => $request->json()
            ]);
            return $response->json(['error' => 'Failed to create queue'], 500);
        }
    }
    
    // Additional methods for queue management and message processing...
}

// Initialize the Message Broker
$gCore = gcore_init($config);
$messageBroker = new MessageBroker(
    gcore_get_cache_manager(),
    gcore_get_error_manager(),
    gcore_get_security_manager(),
    gcore_get_api_manager()
);
$messageBroker->initialize();
```

## Best Practices

### Configuration Management

1. **Environment-specific Configuration**
   - Use `.env` for secrets and environment-specific values
   - Store configuration in YAML files for readability
   - Support configuration inheritance and overrides

2. **Security First**
   - Always sanitize user input before processing
   - Implement role-based access control for all operations
   - Use TLS for all connections, including to ValKey/Redis
   - Implement rate limiting for API endpoints

3. **Performance Optimization**
   - Use batch operations instead of multiple individual operations
   - Use Lua scripts for atomic complex operations
   - Implement appropriate caching strategies
   - Use connection pooling for high-throughput scenarios

4. **Error Handling**
   - Catch and log all exceptions with context
   - Provide meaningful error messages
   - Track errors for monitoring and alerting
   - Implement circuit breaker patterns for external services

## Deployment Options

### Docker Deployment (Recommended)

```bash
# Run with Docker Compose
docker-compose up -d
```

This approach:
- Starts ValKey for storage
- Builds and starts the gCore container
- Mounts your `Source` directory for easy development

### API Server Modes

The APIManager supports multiple server modes:

1. **Auto Mode**: Automatically detects the environment
   - In CLI: Starts a built-in PHP server
   - In web server: Integrates with the existing server
   - In tests: Disables actual server startup

2. **Standalone Mode**: Uses PHP's built-in server
   - Perfect for development and testing
   - Configure with: `'mode' => 'standalone', 'port' => 8080, 'host' => '127.0.0.1'`

3. **Integrated Mode**: Integrates with existing web servers
   - Recommended for production with Apache/Nginx
   - Configure with: `'mode' => 'integrated'`

4. **Disabled Mode**: Prevents any server from starting
   - Use when you need API structure but not HTTP server
   - Configure with: `'mode' => 'disabled'`

## Troubleshooting

### Common Issues

1. **ValKey/Redis Connection Issues**
   - Ensure ValKey/Redis is running (`docker ps` to check container status)
   - Verify connection parameters in configuration
   - Check for network connectivity and firewall settings

2. **Initialization Failures**
   - Check for missing or incorrect configuration
   - Look for circular dependencies in service initialization
   - Verify proper class loading and namespaces

3. **Performance Problems**
   - Enable connection pooling with `ValKeyConnectionPool`
   - Use batch operations instead of individual operations
   - Implement appropriate caching for API responses
   - Consider using the script system for complex operations

4. **Script Execution Errors**
   - Verify ValKey version compatibility with Lua scripts
   - Check script syntax for errors
   - Use script debugging with detailed error logging

### Development Tools

1. **Static Analysis**
   ```bash
   composer analyse
   ```

2. **Code Style**
   ```bash
   # Check code style
   composer check-style
   
   # Auto-fix style issues
   composer fix-style
   ```

3. **Static Analysis**
   ```bash
   # Run static analysis
   composer analyze
   ```

## Conclusion

The gCore framework offers a mathematical foundation for building modern applications. By using its geometric topology system, manager quartet, and optimized ValKey integration, you can build scalable applications with clean architectural patterns.

Start building with the Source/MyApp.php template, and explore the examples in examples/message_broker for working implementations that demonstrate framework integration.

---

This documentation covers building applications with gCore, based on the framework capabilities as of March 2025.