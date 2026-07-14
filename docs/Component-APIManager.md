# APIManager Documentation

## Overview

The APIManager serves as the core REST API interface for the gCore framework, providing a high-performance, scalable, and secure API gateway with built-in middleware support, request processing, caching, rate limiting, and authentication. It integrates transparently with ValKey/Redis for distributed operations while maintaining zero external dependencies to prevent circular dependency issues.

## Key Features

- **Built-in HTTP Server**: Multiple server modes (auto, standalone, integrated, disabled)
- **Middleware Pipeline**: Flexible request processing pipeline
- **Request Routing**: Pattern-based path parameter extraction with regex
- **Authentication**: Support for API keys, JWT, and pluggable auth providers
- **Response Caching**: High-performance distributed response caching
- **Rate Limiting**: Configurable request throttling with header support
- **Validation**: request validation system
- **Metrics Collection**: Detailed performance and usage metrics
- **Trait-Based Architecture**: Modular functionality through traits
- **Geometric Topology Integration**: Capability-based discovery

## Architecture

The APIManager follows gCore's modular trait-based architecture:

### Core Module
- `APIManager.php`: Base manager class with no external dependencies

### Traits
- `EndpointManagerTrait`: Manages endpoint registration and routing
- `RequestProcessorTrait`: Processes incoming requests with middleware
- `ResponseCacheTrait`: Caches API responses for performance
- `RateLimiterTrait`: Provides rate limiting capabilities
- `WebSocketTrait`: Handles WebSocket communications
- `AuthenticationTrait`: Manages API authentication
- `ValidationTrait`: Validates incoming requests
- `MetricsCollectorTrait`: Collects performance metrics

## Server Modes

The APIManager supports multiple server operation modes:

1. **Auto Mode** (default): Automatically selects the appropriate mode based on environment
   - In CLI: Starts PHP's built-in server
   - In web server: Integrates with existing web server
   - In test environments: Skips server startup

2. **Standalone Mode**: Forces use of PHP's built-in server
   - Ideal for development and testing
   - Configurable host and port

3. **Integrated Mode**: Integrates with existing web server
   - Optimized for production environments with Apache/Nginx
   - Uses existing web server's routing

4. **Disabled Mode**: Prevents server startup
   - For applications that need the API structure but handle HTTP requests differently

## Getting Started

### Basic Usage

```php
// Get APIManager instance
$apiManager = gcore_get_api_manager();

// Initialize with configuration
$apiManager->initialize([
    'server' => [
        'mode' => 'standalone',
        'port' => 8080,
        'host' => '127.0.0.1'
    ]
]);

// Add middleware for authentication
$apiManager->addMiddleware('auth', function($request, $response, $next) {
    $apiKey = $request->getHeader('X-API-Key');
    if (!$this->validateAPIKey($apiKey)) {
        return $response->json(['error' => 'Unauthorized'], 401);
    }
    return $next($request, $response);
});

// Register endpoint with URL parameter
$apiManager->registerEndpoint('GET', '/users/:id', function($request, $response) {
    $userId = $request->getParam('id');
    $user = getUserById($userId);
    return $response->json($user);
}, [
    'middleware' => ['auth'],
    'cache' => true
]);

// Start the server
$apiManager->start();
```

### Request Handling

The APIManager provides request and response objects for handling HTTP interactions:

```php
// Request object methods
$method = $request->getMethod();       // GET, POST, etc.
$path = $request->getPath();           // Request path
$query = $request->getQuery();         // Query parameters
$body = $request->getBody();           // Request body
$json = $request->getJson();           // JSON parsed body
$header = $request->getHeader('Name'); // Request header
$param = $request->getParam('id');     // Path parameter

// Response object methods
$response->setStatus(200);                   // Set status code
$response->setHeader('X-Custom', 'value');   // Set header
$response->json(['data' => $value]);         // JSON response
$response->html('<html>...</html>');         // HTML response
$response->text('Plain text');               // Text response
$response->file('/path/to/file');            // File response
$response->redirect('/new-location');        // Redirect
```

## Advanced Features

### Middleware Pipeline

The middleware pipeline allows processing requests through a series of handlers:

```php
// Rate limiting middleware
$apiManager->addMiddleware('rate-limit', function($request, $response, $next) {
    $clientId = $request->getClientIp();
    $route = $request->getPath();
    
    if ($this->isRateLimited($clientId, $route)) {
        return $response->json([
            'error' => 'Rate limit exceeded'
        ], 429);
    }
    
    // Add rate limit headers
    $response = $next($request, $response);
    $limits = $this->getRateLimitHeaders($clientId, $route);
    
    foreach ($limits as $name => $value) {
        $response->setHeader($name, $value);
    }
    
    return $response;
});

// Apply middleware to endpoint
$apiManager->registerEndpoint('GET', '/data', $handler, [
    'middleware' => ['auth', 'rate-limit']
]);
```

### URL Parameter Extraction

The APIManager supports path parameters with regex pattern matching:

```php
// Basic parameter
$apiManager->registerEndpoint('GET', '/users/:id', $handler);

// With regex constraint
$apiManager->registerEndpoint('GET', '/users/:id([0-9]+)', $handler);

// Multiple parameters
$apiManager->registerEndpoint('GET', '/posts/:year/:month/:slug', $handler);

// Optional parameters
$apiManager->registerEndpoint('GET', '/articles/:category?', $handler);
```

### Response Caching

Cache API responses for improved performance:

```php
// Enable response caching
$apiManager->initialize([
    'cache' => [
        'enabled' => true,
        'ttl' => 300, // 5 minutes
        'exclude' => ['/users/profile'] // Routes to exclude
    ]
]);

// Register cacheable endpoint
$apiManager->registerEndpoint('GET', '/products', $handler, [
    'cache' => true,
    'cache_ttl' => 600 // 10 minutes
]);

// Clear cache for a route
$apiManager->clearResponseCache('/products');
```

### API Authentication

Multiple authentication methods:

```php
// API key authentication
$apiManager->registerAuthMethod('api-key', function($request) {
    $key = $request->getHeader('X-API-Key');
    return $this->validateAPIKey($key);
});

// JWT authentication
$apiManager->registerAuthMethod('jwt', function($request) {
    $token = $request->getHeader('Authorization');
    if (empty($token) || strpos($token, 'Bearer ') !== 0) {
        return false;
    }
    $token = substr($token, 7);
    return $this->validateJWT($token);
});

// Register endpoint with auth
$apiManager->registerEndpoint('GET', '/secure-data', $handler, [
    'auth' => 'api-key'
]);
```

### Rate Limiting

Control request rates to prevent abuse:

```php
// Configure rate limiting
$apiManager->initialize([
    'rate_limiting' => [
        'enabled' => true,
        'default_limit' => 60, // per minute
        'routes' => [
            '/api/search' => 30,
            '/api/upload' => 10
        ]
    ]
]);

// Get rate limit status
$status = $apiManager->getRateLimitStatus($clientId, $route);
```

## API Digest

### Main APIManager Class (gCore\Modules\Managers\Base\APIManager\APIManager)

- `getInstance(): APIManager` - Returns the singleton instance of the APIManager.
- `initialize(array $config = []): void` - Initializes the API management system with the given configuration.
- `initializeTraits(array $config = []): void` - Initializes the trait system with configuration.
- `hasActiveTrait(string $trait): bool` - Checks if a specific trait is active.
- `getActiveTraits(): array` - Returns all currently active traits.
- `addMiddleware(string $name, callable $handler): void` - Adds middleware to the processing pipeline.
- `processMiddleware($request, array $middlewareStack = null): mixed` - Processes a request through middleware pipeline.
- `registerEndpoint(string $method, string $path, callable $handler, array $options = []): bool` - Registers an API endpoint.
- `start(): void` - Starts the API server in configured mode.
- `isServerRunning(): bool` - Checks if the API server is running.
- `getServerMode(): string` - Gets the current server mode.
- `setServerMode(string $mode): void` - Sets the server mode.
- `getServerUrl(): string` - Gets the base URL for the server.
- `getConfig(): array` - Gets the current API configuration.
- `updateConfig(array $config): void` - Updates the API configuration.
- `isInitialized(): bool` - Checks if API manager is initialized.
- `getStatus(): array` - Gets detailed status of the API management system.

### EndpointManagerTrait

- `registerEndpoint(string $method, string $path, callable $handler, array $options = []): bool` - Registers an API endpoint.
- `unregisterEndpoint(string $method, string $path): bool` - Unregisters an API endpoint.
- `hasEndpoint(string $method, string $path): bool` - Checks if an endpoint is registered.
- `getEndpointConfig(string $method, string $path): ?array` - Gets configuration for a specific endpoint.
- `getRegisteredEndpoints(): array` - Gets all registered endpoints.
- `registerResourceEndpoints(string $resource, array $config = []): array` - Registers CRUD endpoints for a resource.
- `parsePathParameters(string $path, string $requestPath): ?array` - Extracts parameters from request path.
- `handleEndpointRequest($request): mixed` - Handles a request to an endpoint.

### RequestProcessorTrait

- `processRequest($request): mixed` - Processes an API request through middleware.
- `addRequestProcessor(string $name, callable $processor): bool` - Adds a request processor.
- `createRequest(array $serverVars = []): Request` - Creates a request object from server variables.
- `createResponse(): Response` - Creates a new response object.
- `createErrorResponse(string $code, string $message, int $status = 400): Response` - Creates an error response.
- `handleException(\Exception $e): Response` - Handles an exception during request processing.
- `getRequestStats(): array` - Gets statistics about request processing.

### ResponseCacheTrait

- `cacheResponse($request, $response): void` - Caches an API response.
- `getCachedResponse($request): ?Response` - Gets a cached API response.
- `clearResponseCache(string $path = null): int` - Clears API response cache.
- `getCacheKey($request): string` - Generates a cache key for a request.
- `isCacheable($request): bool` - Determines if a request is cacheable.
- `getCacheHitRatio(): float` - Gets the cache hit ratio.

### RateLimiterTrait

- `isRateLimited(string $clientId, string $route): bool` - Checks if a request is rate limited.
- `trackRequest(string $route, string $clientId): bool` - Tracks a request for rate limiting.
- `getRateLimit(string $route): array` - Gets rate limit configuration for a route.
- `setRateLimit(string $route, int $limit, int $period = 60): bool` - Sets rate limit for a route.
- `resetRateLimits(string $clientId = null): int` - Resets rate limits for a client.
- `getClientIdentifier($request): string` - Gets a unique identifier for the client.
- `getRateLimitStatus(string $clientId, string $route): array` - Gets rate limit status for a client and route.
- `getRateLimitHeaders(string $clientId, string $route): array` - Gets rate limit HTTP headers.

### AuthenticationTrait

- `requiresAuth($request): bool` - Determines if a request requires authentication.
- `authenticateRequest($request): bool` - Authenticates an API request.
- `registerAuthMethod(string $name, callable $handler): bool` - Registers an authentication method.
- `generateAPIKey(string $userId, array $scopes = []): string` - Generates an API key.
- `validateAPIKey(string $key): ?array` - Validates an API key.
- `getAPIKeyForUser(string $userId): ?string` - Gets API key for a user.

### ValidationTrait

- `validateRequest($request): bool` - Validates a request against rules.
- `registerValidator(string $name, callable $validator): bool` - Registers a validator.
- `validateField(string $field, $value, array $rules): array` - Validates a field against rules.
- `getValidationSchema(string $route): ?array` - Gets validation schema for a route.
- `sanitizeInput(array $data, array $rules): array` - Sanitizes input data based on rules.

### MetricsCollectorTrait

- `trackMetric(string $name, $value): void` - Tracks a metric.
- `trackRequest(string $route, string $method): void` - Tracks an API request.
- `trackResponseTime(string $route, float $duration): void` - Tracks response time for a route.
- `getMetrics(): array` - Gets collected metrics.
- `exportMetrics(string $format = 'json'): string` - Exports metrics in a specific format.

## Best Practices

1. **Use Middleware for Cross-Cutting Concerns**
   - Authentication, logging, rate limiting, etc.
   - Keep endpoint handlers focused on business logic

2. **Use Response Caching**
   - Cache read-heavy endpoints
   - Set appropriate TTLs based on data volatility

3. **Implement Proper Rate Limiting**
   - Adjust limits based on endpoint impact
   - Use different limits for authenticated vs. anonymous requests

4. **Structure API Endpoints Logically**
   - Use RESTful patterns for resources
   - Group related endpoints

5. **Validate All Input**
   - Define validation schemas for endpoints
   - Use custom validators for complex validation

6. **Collect and Monitor Metrics**
   - Track response times and error rates
   - Export metrics for monitoring systems

7. **Choose the Right Server Mode**
   - Use standalone for development
   - Use integrated for production
   - Use auto for adaptive behavior

## Performance Considerations

- Enable connection pooling for ValKey operations
- Use batch operations where possible
- Implement appropriate caching strategies
- Configure server timeouts appropriately

## Security Considerations

- Always validate and sanitize all input
- Use HTTPS in production
- Implement proper authentication and authorization
- Set appropriate rate limits
- Be cautious with error messages (don't expose sensitive information)
- Use Content Security Policy headers

## Troubleshooting

### Common Issues

1. **Server won't start**
   - Check port availability
   - Verify permissions
   - Check server mode configuration

2. **Authentication failures**
   - Verify API key format and validity
   - Check auth method registration

3. **Rate limiting problems**
   - Check rate limit configuration
   - Verify client identification method

4. **Path parameter extraction issues**
   - Check path pattern format
   - Verify regex patterns if used

## Conclusion

The APIManager provides a flexible and flexible foundation for building APIs with gCore. Its modular design, middleware support, and integrated server capabilities make it suitable for a wide range of applications from simple services to complex APIs.

---

*Updated: March 2025*