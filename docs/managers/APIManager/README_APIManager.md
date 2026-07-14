# APIManager Documentation

## Overview

APIManager provides routing, middleware, and API management capabilities for the gCore framework. It handles HTTP request routing, middleware pipelines, CORS, rate limiting, and authentication with support for both standalone server mode and web server integration.

**Namespace**: `gCore\Modules\Managers\Base\APIManager`
**Implements**: `ModuleInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)

## Architecture

APIManager supports three server modes:

1. **Standalone Mode**: PHP built-in server for development
2. **Integrated Mode**: Web server integration (Apache/Nginx)
3. **Auto Mode**: Automatic detection based on SAPI

### Request Flow

```
HTTP Request
├── CORS Middleware (preflight handling)
├── Rate Limit Middleware (request counting)
├── Auth Middleware (API key/JWT validation)
├── Custom Middleware (user-defined)
└── Endpoint Handler
    └── Response
```

## Initialization

```php
// Get APIManager instance via gCore
$apiManager = gCore::getService('APIManager');

// Configuration (passed during gCore initialization)
$config = [
    'cors' => [
        'enabled' => true,
        'allowed_origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
        'expose_headers' => [],
        'max_age' => 3600
    ],
    'rate_limit' => [
        'enabled' => true,
        'requests' => 100,
        'period' => 60      // seconds
    ],
    'cache' => [
        'enabled' => true,
        'ttl' => 300        // 5 minutes
    ],
    'auth' => [
        'enabled' => true,
        'methods' => ['api_key', 'jwt']
    ],
    'server' => [
        'mode' => 'auto',   // auto, standalone, integrated, disabled
        'port' => 8000,
        'host' => '0.0.0.0'
    ],
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'gnode_client' => $gNodeClient
];
```

## Public API Reference

### Singleton & Lifecycle

#### `getInstance(): ModuleInterface`
Returns the singleton instance of APIManager.

#### `initialize(array $config = []): void`
Initializes the API system with configuration, registers base middleware.
- **Throws**: `InitializationException` if initialization fails

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getStatus(): array`
Get full status including endpoint count, middleware, and capabilities.

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

### Endpoint Registration

#### `registerEndpoint(string $method, string $path, callable $handler, array $options = []): bool`
Register an API endpoint.
- **Parameters**:
  - `$method`: HTTP method (GET, POST, PUT, DELETE, OPTIONS, PATCH, HEAD)
  - `$path`: URL path (must start with `/`, supports parameters like `/users/:id`)
  - `$handler`: Callable handler function
  - `$options`: Additional options
- **Returns**: `true` on success
- **Throws**: `ValidationException` if method or path is invalid

**Options**:
```php
[
    'middleware' => ['auth', 'rate_limit'],  // Middleware names to apply
    'cache' => true,                          // Enable caching for this endpoint
    'auth' => true                            // Require authentication
]
```

**Example**:
```php
$apiManager->registerEndpoint('GET', '/users/:id', function($request, $response) {
    $userId = $request->param('id');
    return $response->json(['user' => getUserById($userId)]);
}, [
    'middleware' => ['auth'],
    'cache' => true
]);

$apiManager->registerEndpoint('POST', '/users', function($request, $response) {
    $data = $request->body;
    $user = createUser($data);
    return $response->json(['user' => $user], 201);
}, [
    'middleware' => ['auth', 'rate_limit']
]);
```

### Middleware

#### `addMiddleware(string $name, callable $handler): bool`
Add custom middleware.
- **Parameters**:
  - `$name`: Middleware name for reference
  - `$handler`: Middleware function `function($request, $response, $next)`
- **Returns**: `true` on success

**Middleware Signature**:
```php
function($request, $response, $next) {
    // Pre-processing

    // Continue to next middleware
    $result = $next($request, $response);

    // Post-processing

    return $result;
}
```

**Example**:
```php
$apiManager->addMiddleware('logging', function($request, $response, $next) {
    $start = microtime(true);

    $result = $next($request, $response);

    $duration = microtime(true) - $start;
    error_log("{$request->method} {$request->path} - {$duration}ms");

    return $result;
});
```

### Server Control

#### `start(int $port = null, string $host = null): bool`
Start the API server.
- **Parameters**:
  - `$port`: Port number (default from config)
  - `$host`: Host address (default from config)
- **Returns**: `true` on success

**Server Modes**:
- `standalone`: Uses PHP built-in server (CLI only)
- `integrated`: Registers shutdown handler for web server
- `auto`: Detects based on `php_sapi_name()`
- `disabled`: No server started (for testing)

#### `processRequest(): void`
Process the current HTTP request manually.
- Called automatically in integrated mode via shutdown handler

### Capability Discovery

#### `getCapabilityVector(): array`
Get capability vector for geometric service discovery.

## Built-in Middleware

### CORS Middleware
Enabled when `config['cors']['enabled'] = true`:
- Adds CORS headers (Access-Control-Allow-Origin, etc.)
- Handles OPTIONS preflight requests (returns 204)

### Rate Limit Middleware
Enabled when `config['rate_limit']['enabled'] = true`:
- Tracks requests per client (IP or API key)
- Returns 429 with Retry-After header when exceeded
- Adds X-RateLimit-* headers to responses

### Auth Middleware
Enabled when `config['auth']['enabled'] = true`:
- Supports API key (X-API-Key header)
- Supports JWT (Authorization: Bearer token)
- Attaches `$request->user` with auth info

## Request Object

The request object passed to handlers contains:

```php
$request = (object)[
    'method' => 'GET',              // HTTP method
    'path' => '/users/123',         // Request path
    'query' => ['page' => '1'],     // $_GET parameters
    'body' => ['name' => 'John'],   // Parsed JSON body
    'headers' => [...],             // All headers
    'ip' => '192.168.1.1',          // Client IP
    'params' => ['id' => '123'],    // Path parameters
    'user' => [...],                // Auth info (if authenticated)

    // Helper methods
    'header' => fn($name),          // Get header by name
    'param' => fn($name)            // Get path parameter
];
```

## Response Object

The response object provides:

```php
$response = (object)[
    'status' => 200,
    'headers' => [],
    'body' => '',

    // Helper methods
    'header' => fn($name, $value),  // Set header
    'json' => fn($data, $status),   // Send JSON response
    'send' => fn($status, $body)    // Send raw response
];

// Usage in handler
return $response->json(['success' => true]);
return $response->json(['error' => 'Not found'], 404);
return $response->send(204);  // No content
```

## Usage Examples

### Basic API Setup

```php
$api = gCore::getService('APIManager');

// Register endpoints
$api->registerEndpoint('GET', '/health', function($req, $res) {
    return $res->json(['status' => 'ok']);
});

$api->registerEndpoint('GET', '/users', function($req, $res) {
    $users = getAllUsers();
    return $res->json(['users' => $users]);
});

$api->registerEndpoint('GET', '/users/:id', function($req, $res) {
    $id = $req->param('id');
    $user = getUserById($id);

    if (!$user) {
        return $res->json(['error' => 'User not found'], 404);
    }

    return $res->json(['user' => $user]);
});

$api->registerEndpoint('POST', '/users', function($req, $res) {
    $data = $req->body;
    $user = createUser($data);
    return $res->json(['user' => $user], 201);
});

// Start server (standalone mode)
$api->start(8000, '0.0.0.0');
```

### Custom Middleware

```php
// Request timing middleware
$api->addMiddleware('timing', function($req, $res, $next) {
    $start = hrtime(true);
    $result = $next($req, $res);
    $duration = (hrtime(true) - $start) / 1e6;  // ms
    $result->header('X-Response-Time', "{$duration}ms");
    return $result;
});

// JSON validation middleware
$api->addMiddleware('json_body', function($req, $res, $next) {
    if (in_array($req->method, ['POST', 'PUT', 'PATCH']) && empty($req->body)) {
        return $res->json(['error' => 'JSON body required'], 400);
    }
    return $next($req, $res);
});

// Use in endpoint
$api->registerEndpoint('POST', '/data', $handler, [
    'middleware' => ['timing', 'json_body', 'auth']
]);
```

### Authentication Handling

```php
// Endpoint requiring auth
$api->registerEndpoint('GET', '/profile', function($req, $res) {
    // $req->user is set by auth middleware
    $user = $req->user;

    return $res->json([
        'auth_method' => $user['auth_method'],
        'roles' => $user['roles']
    ]);
}, [
    'middleware' => ['auth']
]);

// Optional auth endpoint
$api->registerEndpoint('GET', '/items', function($req, $res) {
    $items = getItems();

    // Show extra info if authenticated
    if (isset($req->user)) {
        foreach ($items as &$item) {
            $item['internal_id'] = $item['id'];
        }
    }

    return $res->json(['items' => $items]);
}, [
    'auth' => false  // Auth not required
]);
```

## Capability Vector

```php
[
    'auth' => 0.7,     // Authentication support
    'security' => 0.6, // Security middleware
    'cache' => 0.5,    // Response caching
    'errors' => 0.4    // Error handling
]
```

## Path Parameters

Use `:param` syntax for path parameters:

```php
'/users/:id'           // $req->param('id')
'/posts/:postId/comments/:commentId'
'/categories/:slug/items'
```

Parameters are captured by regex and added to `$request->params`.

---

*Last Updated: January 2026*
