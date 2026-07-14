# gCore Standalone Setup Guide

This guide provides instructions for setting up and using gCore as a standalone PHP framework, without WordPress integration.

## Installation

### Prerequisites

Before installing gCore in standalone mode, ensure your system meets these requirements:

- PHP 8.0 or higher (8.2+ recommended)
- ValKey server with gNode Lua functions loaded (port 47445)
- PHP extensions: json, mbstring, redis, igbinary, zlib
- Composer (for dependency management)

### Installation Methods

#### Method 1: Composer Installation (Recommended)

```bash
# Create a new project
mkdir my-gcore-project
cd my-gcore-project

# Initialize Composer
composer init --require="geodinium/gcore:^1.0" --name="your-vendor/your-project" -n

# Install dependencies
composer install
```

#### Method 2: Manual Installation

```bash
git clone https://github.com/geodineum/gCore.git
cd gCore
composer install
```

## Basic Configuration

### Environment Setup

Create a `.env` file in your project root:

```bash
# Application settings
APP_ENV=development
GNODE_SITE_ID=my_standalone_app
GNODE_NODE_ID=standalone_node

# ValKey connection (gNode-Client auto-discovers credentials)
# Only set these if NOT using standard /opt/gNode/.gnode/ password files
# VALKEY_HOST=127.0.0.1
# VALKEY_PORT=47445
# VALKEY_PASSWORD_FILE=/path/to/password/file

# Debug mode
GCORE_DEBUG=true
```

### Credential Resolution

gNode-Client automatically resolves ValKey credentials in this order:

1. **Environment variables**: `VALKEY_PASSWORD` or `VALKEY_PASSWORD_FILE`
2. **Standard location**: `/opt/gNode/.gnode/valkey_client_{site_id}.password`
3. **GNODE_BASE_PATH**: `${GNODE_BASE_PATH}/.gnode/valkey_client_{site_id}.password`

You do NOT need to specify credentials in your config - gNode-Client handles this automatically based on `site_id`.

## Basic Usage

Create a basic application that uses gCore:

```php
<?php
// index.php

// Require the autoloader
require_once 'vendor/autoload.php';

// Include standalone initialization script
require_once 'gcore-standalone.php';

// Initialize the framework (site_id is REQUIRED)
$gCore = gcore_init([
    'site_id' => 'my_standalone_app',  // Required: identifies your application
    'environment' => 'development',     // DTAP: testing|staging|acceptance|production
    'debug' => true
]);

// Get manager instances using the helper functions
$errorManager = gcore_get_error_manager();
$cacheManager = gcore_get_cache_manager();
$securityManager = gcore_get_security_manager();
$apiManager = gcore_get_api_manager();
$gNodeClient = gcore_get_gnode_client();

// Use the error manager
$errorManager->logError('My application started', [
    'timestamp' => time(),
    'environment' => 'standalone'
], 'INFO', 'APPLICATION');

// Cache operations via gNode-Client (recommended)
$gNodeClient->luaSet('welcome_message', 'Hello from gCore!', 3600);
$message = $gNodeClient->luaGet('welcome_message');

// Security operations
$input = $_GET['input'] ?? '';
$sanitizedInput = $securityManager->sanitize($input);

// Register API endpoints
$apiManager->registerEndpoint('hello', [
    'methods' => 'GET',
    'callback' => function() use ($message) {
        return [
            'status' => 'success',
            'message' => $message
        ];
    }
]);

// Start the API server (only in standalone mode)
$apiManager->startServer();
```

## Advanced Usage

### Custom Application Structure

For more complex applications, use this recommended structure:

```
my-gcore-app/
├── config/
│   ├── custom_config.yaml
│   └── environments/
│       ├── development.yaml
│       └── production.yaml
├── src/
│   ├── Controllers/
│   │   └── ApiController.php
│   ├── Models/
│   │   └── User.php
│   └── Services/
│       └── AuthService.php
├── public/
│   └── index.php
├── .env
├── composer.json
└── bootstrap.php
```

Example bootstrap.php:

```php
<?php
// bootstrap.php
require_once 'vendor/autoload.php';
require_once 'gcore-standalone.php';

// Load environment variables (using vlucas/phpdotenv)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Initialize gCore
$gCore = gcore_init([
    'site_id' => $_ENV['GNODE_SITE_ID'] ?? 'my_app',
    'environment' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => ($_ENV['GCORE_DEBUG'] ?? 'false') === 'true',
]);

return $gCore;
```

Example public/index.php:

```php
<?php
// Define application root
define('APP_ROOT', dirname(__DIR__));

// Bootstrap the application
$gCore = require APP_ROOT . '/bootstrap.php';

// Get API manager
$apiManager = gcore_get_api_manager();

// Initialize your application components
require APP_ROOT . '/src/Controllers/ApiController.php';
$apiController = new \App\Controllers\ApiController();

// Register routes
$apiController->registerRoutes($apiManager);

// Start the server
$apiManager->startServer();
```

## Integration with Frameworks

### Integrating with Laravel

```php
<?php
// app/Providers/GCoreServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class GCoreServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('gcore', function ($app) {
            require_once base_path('vendor/geodinium/gcore/gcore-standalone.php');

            return gcore_init([
                'site_id' => env('GCORE_SITE_ID', 'laravel_app'),
                'environment' => config('app.env') === 'local' ? 'testing' : config('app.env'),
                'debug' => config('app.debug'),
            ]);
        });

        $this->app->singleton('gcore.gnode_client', fn() => gcore_get_gnode_client());
        $this->app->singleton('gcore.cache_manager', fn() => gcore_get_cache_manager());
        $this->app->singleton('gcore.error_manager', fn() => gcore_get_error_manager());
        $this->app->singleton('gcore.security_manager', fn() => gcore_get_security_manager());
    }
}
```

### Integrating with Symfony

```php
<?php
// src/Service/GCoreService.php
namespace App\Service;

class GCoreService
{
    private $gcore;

    public function __construct()
    {
        require_once dirname(__DIR__, 2) . '/vendor/geodinium/gcore/gcore-standalone.php';

        $this->gcore = gcore_init([
            'site_id' => $_ENV['GCORE_SITE_ID'] ?? 'symfony_app',
            'environment' => $_ENV['APP_ENV'] === 'dev' ? 'testing' : $_ENV['APP_ENV'],
            'debug' => $_ENV['APP_DEBUG'] === 'true',
        ]);
    }

    public function getgNodeClient() { return gcore_get_gnode_client(); }
    public function getCacheManager() { return gcore_get_cache_manager(); }
    public function getErrorManager() { return gcore_get_error_manager(); }
    public function getSecurityManager() { return gcore_get_security_manager(); }
}
```

## Working with gNode-Client

The gNode-Client is the primary interface for ValKey operations:

```php
$gNode = gcore_get_gnode_client();

// Cache operations (via Lua functions - FCALL)
$gNode->luaSet('user:123', json_encode($userData), 3600);
$user = json_decode($gNode->luaGet('user:123'), true);
$gNode->luaDel('user:123');

// Batch operations (one round-trip for many keys)
$results = $gNode->batchCacheGet(['key1', 'key2', 'key3']);
$gNode->batchCacheSet([
    'key1' => ['value' => 'val1', 'ttl' => 300],
    'key2' => ['value' => 'val2', 'ttl' => 300],
]);

// Geometric topology discovery
$services = $gNode->geometricDiscover([
    'security' => 0.8,
    'cache' => 0.7,
]);

// Template rendering (via gNode daemon)
$html = $gNode->renderTemplate('my_template', [
    'title' => 'Hello World',
    'items' => [1, 2, 3],
]);
```

## Working with Managers

### CacheManager

```php
$cacheManager = gcore_get_cache_manager();

// Basic operations
$cacheManager->set('key', 'value', 3600);
$value = $cacheManager->get('key');
$cacheManager->delete('key');

// Multiple operations (uses gNode batch internally)
$cacheManager->setMultiple([
    'key1' => 'value1',
    'key2' => 'value2'
], 3600);
$values = $cacheManager->getMultiple(['key1', 'key2']);
```

### ErrorManager

```php
$errorManager = gcore_get_error_manager();

// Log different severity levels
$errorManager->logError('Critical failure', [
    'connection' => 'primary',
    'errno' => $errno
], 'CRITICAL', 'DATABASE');

// Log with context
$errorManager->logError('User login failed', [
    'username' => $username,
    'ip' => $_SERVER['REMOTE_ADDR']
], 'WARNING', 'SECURITY');
```

### SecurityManager

```php
$securityManager = gcore_get_security_manager();

// Data validation
if ($securityManager->validate($input, 'email')) {
    // Valid email
}

// Sanitization
$safeHtml = $securityManager->sanitizeHTML($userInput);

// Encryption
$encrypted = $securityManager->encrypt($sensitiveData);
$decrypted = $securityManager->decrypt($encrypted);
```

### APIManager

```php
$apiManager = gcore_get_api_manager();

// Register endpoint with callback
$apiManager->registerEndpoint('users', [
    'methods' => 'GET',
    'callback' => function() {
        return ['users' => [/* ... */]];
    }
]);

// With path parameters
$apiManager->registerEndpoint('users/{id}', [
    'methods' => 'GET',
    'callback' => function($request) {
        $id = $request['path_params']['id'];
        return ['user' => ['id' => $id]];
    }
]);

// Add middleware
$apiManager->addMiddleware('auth', function($request, $next) {
    if (!isset($request['headers']['X-API-Key'])) {
        return ['status' => 'error', 'code' => 401, 'message' => 'Unauthorized'];
    }
    return $next($request);
});

// Start the server (in standalone mode)
$apiManager->startServer();
```

## Troubleshooting

### ValKey Connection Issues

If you encounter ValKey connection problems:

```bash
# 1. Verify ValKey is running on port 47445
valkey-cli -p 47445 ping

# 2. Check if gNode Lua functions are loaded
valkey-cli -p 47445 FUNCTION LIST

# 3. Test authentication with your site credentials
valkey-cli -p 47445 --user gnode_client_my_standalone_app \
  --pass "$(cat /opt/gNode/.gnode/valkey_client_my_standalone_app.password)" PING
```

### Initialization Errors

If gCore fails to initialize:

```php
// 1. Enable debug mode
$gCore = gcore_init([
    'site_id' => 'my_app',
    'debug' => true
]);

// 2. Check credential resolution
$debugInfo = \gCore\gNode\gNodeClient::getCredentialDebugInfo('my_app');
print_r($debugInfo);
```

### WordPress Code Bypass

gCore gracefully bypasses WordPress-specific code in standalone mode:

- `function_exists('add_action')` checks prevent WP hook registration
- `defined('WP_CONTENT_DIR')` checks skip WP-specific paths
- TransientStorage is only used when WordPress functions are available

You can verify standalone mode:

```php
if (gcore_is_wordpress()) {
    echo "Running in WordPress context";
} else {
    echo "Running in standalone mode";
}
```

### Performance Optimization

For best performance:

1. Use PHP 8.2+ with JIT enabled
2. Enable OPcache in php.ini
3. Use gNode-Client batch operations for bulk data
4. Set appropriate TTLs to reduce cache misses

## Next Steps

After setting up gCore in standalone mode:

1. Explore the example applications in `/examples`
2. Set up geometric topology for service discovery
3. Implement rate limiting for API endpoints
4. Configure monitoring and alerting
5. Create a deployment strategy for production

For more information, see:
- [gCore Documentation](Introduction.md)
- [API Manager Guide](Component-APIManager.md)
- [Security Manager Guide](Security.md)
