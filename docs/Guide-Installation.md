# gCore Installation Guide

This guide will walk you through installing and configuring gCore, both as a standalone PHP library and as a WordPress plugin.

*Updated: March 2025*

## Requirements

### System Requirements

- PHP 7.2 or higher (8.0+ recommended)
- ValKey or Redis server (6.0+ recommended)
- Sufficient memory: 128MB minimum, 256MB recommended

### PHP Extensions

Required extensions:
- json
- mbstring
- redis
- igbinary (recommended for performance)
- zlib

Optional extensions:
- openssl (for encryption features)
- curl (for remote service communication)

## Installation Options

### Option 1: Composer Installation (Recommended)

1. Create a new project directory or navigate to your existing project
2. Install gCore via Composer:

```bash
composer require geodineum/gcore
```

3. Create a `.env` file in your project root with the following configuration:

```
# ValKey/Redis Configuration
VALKEY_HOST=127.0.0.1
VALKEY_PORT=6379
VALKEY_AUTH=
VALKEY_DB=0

# gCore Settings
GCORE_ENVIRONMENT=production
GCORE_SITE_ID=my_site
GCORE_DEBUG=false
```

4. Initialize gCore in your application:

```php
<?php
require 'vendor/autoload.php';

// Load environment variables
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Initialize gCore (preferred method)
$gCore = gcore_init([
    'core' => [
        'environment' => $_ENV['GCORE_ENVIRONMENT'],
        'debug' => (bool)$_ENV['GCORE_DEBUG']
    ],
    'site_id' => $_ENV['GCORE_SITE_ID'],
    'storage' => [
        'host' => $_ENV['VALKEY_HOST'],
        'port' => $_ENV['VALKEY_PORT'],
        'auth' => $_ENV['VALKEY_AUTH'],
        'database' => $_ENV['VALKEY_DB']
    ]
]);

// Get services through helper functions
$securityManager = gcore_get_security_manager();
$errorManager = gcore_get_error_manager();
$cacheManager = gcore_get_cache_manager();
$apiManager = gcore_get_api_manager();
```

### Option 2: WordPress Plugin Installation

1. Download the gCore zip file from the official website or GitHub repository
2. Log in to your WordPress admin panel
3. Navigate to Plugins → Add New → Upload Plugin
4. Choose the downloaded zip file and click "Install Now"
5. After installation, click "Activate Plugin"
6. Navigate to gCore → Settings in your WordPress admin menu
7. Configure your ValKey/Redis connection and other settings
8. Save changes

### Option 3: Manual Installation

1. Clone the repository or download the zip file
2. Extract the files to your project directory
3. Install dependencies using Composer:

```bash
cd gCore
composer install
```

4. Copy the `.env.example` file to `.env` and configure it
5. Include the loader in your application:

```php
<?php
require_once 'path/to/gcore/gcore-standalone.php';

// Initialize gCore
$gCore = gcore_init([
    // Configuration options
]);

// Get services
$securityManager = gcore_get_security_manager();
$errorManager = gcore_get_error_manager();
$cacheManager = gcore_get_cache_manager();
$apiManager = gcore_get_api_manager();
```

## ValKey/Redis Setup

### Installing ValKey (Recommended)

ValKey is a Redis fork with enhanced functionality for gCore:

1. Install ValKey using Docker (recommended):

```bash
docker run -d --name valkey -p 6379:6379 valkey/valkey:latest
```

2. Or install ValKey from source:

```bash
git clone https://github.com/valkey-io/valkey.git
cd valkey
make
make install
```

3. Start ValKey:

```bash
valkey-server
```

### Using Redis

If you prefer to use Redis instead of ValKey:

1. Install Redis:

```bash
# Ubuntu/Debian
sudo apt-get install redis-server

# macOS with Homebrew
brew install redis

# Windows
# Download from https://github.com/microsoftarchive/redis/releases
```

2. Start Redis:

```bash
redis-server
```

3. Update your gCore configuration to use Redis:

```php
$gCore = gcore_init([
    // Other configurations...
    'storage' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => '',
        'database' => 0
    ]
]);
```

## Configuration

### Core Configuration

Edit your `.env` file or pass configuration directly to the `gcore_init()` method:

```php
$gCore = gcore_init([
    'core' => [
        'environment' => 'production', // production, development, staging, wordpress
        'debug' => false,
        'log_path' => '/var/log/gcore'
    ],
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'storage' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'auth' => 'password', // leave empty if no password
        'database' => 0
    ]
]);
```

### Manager Configuration

Each manager can be configured with specific options:

```php
$gCore = gcore_init([
    // Core config...
    
    // Security Manager config
    'security' => [
        'encryption' => [
            'algorithm' => 'AES-256-GCM',
            'key_rotation_days' => 30
        ],
        'authentication' => [
            'require_2fa' => true
        ]
    ],
    
    // Error Manager config
    'error' => [
        'logging' => [
            'level' => 'WARNING',
            'channels' => ['file', 'valkey']
        ],
        'notifications' => [
            'email' => 'admin@example.com'
        ]
    ],
    
    // Cache Manager config
    'cache' => [
        'prefix' => 'mycache_',
        'default_ttl' => 3600,
        'streams' => [
            'enabled' => true
        ],
        'connection_pool_size' => 10
    ],
    
    // API Manager config
    'api' => [
        'namespace' => 'myapp/v1',
        'cache_enabled' => true,
        'rate_limiting' => true,
        'server' => [
            'mode' => 'auto',  // auto, standalone, integrated, disabled
            'port' => 8080,
            'host' => '127.0.0.1'
        ]
    ]
]);
```

### YAML Configuration (Alternative)

gCore supports YAML configuration files for more complex setups:

1. Create a `config/gcore.yaml` file:

```yaml
version: "1.0"
core:
  environment: production
  debug: false
  log_path: /var/log/gcore

site_id: my_site
node_id: node1

storage:
  host: 127.0.0.1
  port: 6379
  auth: password
  database: 0

security:
  encryption:
    algorithm: AES-256-GCM
    key_rotation_days: 30
  authentication:
    require_2fa: true

error:
  logging:
    level: WARNING
    channels:
      - file
      - valkey
  notifications:
    email: admin@example.com

cache:
  prefix: mycache_
  default_ttl: 3600
  connection_pool_size: 10
  streams:
    enabled: true

api:
  namespace: myapp/v1
  cache_enabled: true
  rate_limiting: true
  server:
    mode: auto
    port: 8080
    host: 127.0.0.1
```

2. Load the YAML configuration:

```php
$gCore = gcore_init($config);
```

## Source Directory Structure

gCore encourages a clean separation between your application code and the framework. Your custom application code should be placed in the `Source` directory:

```
Source/
├── MyApp.php                 # Application entry point
├── Controllers/              # Application controllers
├── Models/                   # Domain models
├── Services/                 # Application services
└── config/
    ├── .env                  # Environment variables
    └── custom_config.yaml    # Application-specific configuration
```

Use `Source/MyApp.php` as a starting template for your application.

## Docker Deployment

gCore can be easily deployed with Docker:

1. Use the included `docker-compose.yml` file:

```yaml
version: '3'

services:
  gcore:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    volumes:
      - ./Source:/var/www/gcore/Source
      - ./config:/var/www/gcore/config
    environment:
      - APP_ENV=development
      - SITE_ID=default
      - NODE_ID=docker
      - VALKEY_HOST=valkey
      - VALKEY_PORT=6379
    depends_on:
      - valkey
    restart: unless-stopped

  valkey:
    image: valkey/valkey:latest
    volumes:
      - valkey-data:/data
    command: ["valkey-server", "--appendonly", "yes"]
    restart: unless-stopped

volumes:
  valkey-data:
```

2. Start the containers:

```bash
docker-compose up -d
```

This will:
1. Build the gCore Docker image with all required dependencies
2. Start a ValKey container for storage
3. Mount your `Source` directory for easy development
4. Make gCore available at http://localhost:8000

## Verification

To verify your installation is working correctly:

```php
$gCore = gcore_init();
$status = $gCore->getStatus();
var_dump($status);

if ($gCore->isHealthy()) {
    echo "gCore is installed and running correctly.\n";
} else {
    echo "There are issues with your gCore installation.\n";
}

// Check ValKey/Redis connection
$cacheManager = gcore_get_cache_manager();
if ($cacheManager->getConnectionPool()->ping()) {
    echo "Cache connection is working.\n";
} else {
    echo "Cache connection failed.\n";
}
```

## Using the MessageBroker Example

gCore includes a MessageBroker example that demonstrates the framework's capabilities:

```bash
# Start the simplified server (in-memory storage)
php examples/message_broker/server_simplified.php

# Or start the full server with ValKey/Redis
php examples/message_broker/server.php

# Use the client to interact with the broker
API_KEY=your-api-key php examples/message_broker/client.php
```

See the [MessageBroker-Guide.md](MessageBroker-Guide.md) for complete documentation.

## Troubleshooting

### Common Issues

1. **ValKey/Redis Connection Failure**
   - Check if ValKey/Redis server is running
   - Verify connection settings (host, port, auth)
   - Ensure PHP Redis extension is installed

2. **Missing PHP Extensions**
   - Run `php -m` to list installed extensions
   - Install missing extensions using your package manager

3. **WordPress Integration Issues**
   - Verify WordPress version (5.2+ required)
   - Check for plugin conflicts
   - Ensure correct permissions for plugin directory

### Debug Mode

Enable debug mode for detailed logging:

```php
$gCore = gcore_init([
    'core' => [
        'debug' => true
    ]
]);
```

Or in WordPress, go to gCore → Settings and enable Debug Mode.

### Logs

Check the logs for error messages:

- Default log location: `/var/log/gcore`
- WordPress logs: `wp-content/gcore-logs`
- PHP error log: Check your PHP configuration

## Next Steps

Once gCore is installed and configured:

1. Read the [DeveloperGuide.md](DeveloperGuide.md) for an overview
2. Explore manager-specific documentation:
   - [SecurityManager](managers/SecurityManager/README_SecurityManager.md)
   - [ErrorManager](managers/ErrorManager/README_ErrorManager.md)
   - [CacheManager](managers/CacheManager/README_CacheManager.md)
   - [APIManager](managers/APIManager/README_APIManager.md)
3. Check out the examples in the `examples/` directory
4. Join the community forum for support and discussions

For any issues or questions, please open an issue on GitHub or contact support.