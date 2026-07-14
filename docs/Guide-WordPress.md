# gCore WordPress Plugin Installation Guide

This guide provides instructions for installing and configuring gCore as a WordPress plugin, including basic setup, configuration options, and integration with your WordPress site.

## Installation

### Prerequisites

Before installing gCore as a WordPress plugin, ensure your system meets these requirements:

- WordPress 5.2 or higher
- PHP 7.2 or higher (8.0+ recommended)
- Redis or ValKey server (6.0+ recommended)
- PHP extensions: json, mbstring, redis

### Installation Methods

#### Method 1: WordPress Admin Dashboard

1. Download the gCore plugin zip file from the official repository
2. Log into your WordPress admin panel
3. Navigate to **Plugins → Add New → Upload Plugin**
4. Click **Choose File** and select the gCore zip file
5. Click **Install Now**
6. After installation completes, click **Activate Plugin**

#### Method 2: Manual Installation

1. Download the gCore plugin zip file
2. Extract the contents to your `/wp-content/plugins/` directory
3. Rename the extracted folder to `gcore` if needed
4. Log into your WordPress admin panel
5. Navigate to **Plugins**
6. Find "gCore Framework" in the list and click **Activate**

#### Method 3: Using Composer

If your WordPress site uses Composer for dependency management:

1. Add gCore to your `composer.json`:

```json
{
  "require": {
    "geodineum/gcore": "^1.0"
  }
}
```

2. Run composer update:

```bash
composer update
```

3. Activate the plugin through the WordPress admin interface

## Configuration

### Basic Configuration

After activating the plugin, configure gCore:

1. Navigate to **gCore → Settings** in your WordPress admin menu
2. Configure the following settings:

#### Core Settings

- **Environment**: Choose between Development, Staging, or Production
- **Debug Mode**: Enable for detailed logging (disable in production)
- **Site ID**: Unique identifier for your WordPress site
- **Node ID**: Unique identifier for this WordPress instance

#### ValKey/Redis Connection

- **Host**: Redis server hostname (default: 127.0.0.1)
- **Port**: Redis server port (default: 6379)
- **Password**: Redis server password (if required)
- **Database**: Redis database index (default: 0)

#### Manager Settings

- **Security Manager Settings**: Configure security options
- **Error Manager Settings**: Configure error logging and notifications
- **Cache Manager Settings**: Configure caching options
- **API Manager Settings**: Configure API endpoints and access

3. Click **Save Changes** to apply your configuration

### Advanced Configuration

#### Custom YAML Configuration

For advanced setups, you can provide a custom YAML configuration:

1. Create a `gcore-config.yaml` file in your WordPress root directory:

```yaml
core:
  environment: production
  debug: false
  
site_id: my_wordpress_site
node_id: production_node

storage:
  host: redis.example.com
  port: 6379
  auth: password
  database: 0

security:
  encryption:
    algorithm: AES-256-GCM
    key_rotation_days: 30
  
error:
  logging:
    level: WARNING
    channels:
      - file
      - database
      
cache:
  prefix: wp_gcore_
  default_ttl: 3600
  
api:
  namespace: my-site/v1
  rate_limiting: true
```

2. In your WordPress configuration file (`wp-config.php`), add:

```php
define('GCORE_CONFIG_PATH', __DIR__ . '/gcore-config.yaml');
```

#### Environment Variables

gCore can also be configured using environment variables:

```php
// In wp-config.php
define('GCORE_ENVIRONMENT', 'production');
define('GCORE_SITE_ID', 'my_wordpress_site');
define('GCORE_VALKEY_HOST', 'redis.example.com');
define('GCORE_VALKEY_PORT', '6379');
define('GCORE_VALKEY_AUTH', 'password');
```

## Using gCore with WordPress

### Accessing gCore Services

You can access gCore services from your theme or plugin:

```php
// Get gCore instance
$gCore = \gCore\Modules\Core\gCore::getInstance();

// Get specific managers
$securityManager = $gCore->getService('SecurityManager');
$cacheManager = $gCore->getService('CacheManager');
$errorManager = $gCore->getService('ErrorManager');
$apiManager = $gCore->getService('APIManager');

// Use gCore services
$cacheKey = 'my_custom_data';
$data = $cacheManager->get($cacheKey);

if ($data === null) {
    // Generate data
    $data = get_expensive_data();
    
    // Cache for 1 hour
    $cacheManager->set($cacheKey, $data, 3600);
}
```

### Adding Custom REST Endpoints

Use gCore's API Manager to add custom REST endpoints:

```php
// Get API Manager
$gCore = \gCore\Modules\Core\gCore::getInstance();
$apiManager = $gCore->getService('APIManager');

// Register endpoint
$apiManager->registerEndpoint('custom/v1', 'data', [
    'methods' => 'GET',
    'callback' => function($request) {
        // Process request
        $params = $request->get_params();
        
        // Return response
        return [
            'status' => 'success',
            'data' => $your_data
        ];
    },
    'permission_callback' => function() {
        return current_user_can('read');
    }
]);
```

### Security Features

Implement enhanced security features:

```php
// Get Security Manager
$gCore = \gCore\Modules\Core\gCore::getInstance();
$securityManager = $gCore->getService('SecurityManager');

// Sanitize user input
$clean_content = $securityManager->sanitize($user_input);

// Encrypt sensitive data
$encrypted = $securityManager->encrypt($sensitive_data);

// Verify capabilities
if ($securityManager->hasCapability('manage_content')) {
    // User has the required capability
}
```

### Error Handling

Use gCore's Error Manager for centralized error handling:

```php
// Get Error Manager
$gCore = \gCore\Modules\Core\gCore::getInstance();
$errorManager = $gCore->getService('ErrorManager');

try {
    // Risky operation
    process_something();
} catch (\Exception $e) {
    // Log the error
    $errorManager->logError('process_failure', $e->getMessage(), [
        'context' => 'custom_process',
        'user_id' => get_current_user_id(),
        'additional_data' => $some_data
    ]);
    
    // Notify administrators if critical
    if ($is_critical) {
        $errorManager->notifyAdmin('Critical Process Failure', $e->getMessage());
    }
}
```

## Using the Capability Checker with WordPress

The gCore capability checker can help optimize your WordPress integration by identifying which gCore capabilities are being used in your themes and plugins.

### Prerequisites

- gCore plugin installed and activated
- Access to WP CLI or command-line PHP on your server

### Running the Capability Check

```bash
# Navigate to your WordPress directory
cd /path/to/wordpress

# For a theme
wp gcore check-capabilities wp-content/themes/your-theme --recursive --output-config=wp-content/plugins/gcore/config/theme-config.yaml

# For a plugin
wp gcore check-capabilities wp-content/plugins/your-plugin --recursive --output-config=wp-content/plugins/gcore/config/plugin-config.yaml

# For your entire WordPress installation
wp gcore check-capabilities . --recursive --output-config=wp-content/plugins/gcore/config/wp-config.yaml
```

If WP CLI is not available, you can use the PHP CLI directly:

```bash
php wp-content/plugins/gcore/gCoreCLI.php check-capabilities wp-content/themes/your-theme --recursive --output-config=wp-content/plugins/gcore/config/theme-config.yaml
```

### Applying the Configuration

After generating the configuration file, you can apply it in your WordPress site:

1. Navigate to **gCore → Settings** in your WordPress admin menu
2. Select **Import Configuration**
3. Choose the generated YAML file
4. Click **Import and Apply**

Or, modify your `wp-config.php`:

```php
define('GCORE_CONFIG_PATH', __DIR__ . '/wp-content/plugins/gcore/config/wp-config.yaml');
```

## Multisite Support

gCore supports WordPress multisite installations:

1. Install gCore as a network plugin:
   - Network Admin → Plugins → Add New → Upload Plugin
   - Network Activate the plugin

2. Configure network settings:
   - Network Admin → gCore → Network Settings
   - Configure settings that apply to all sites

3. Site-specific configuration:
   - Each site admin can access their own gCore settings
   - Site settings inherit from network settings unless overridden

4. Network-wide caching:
   - Enable shared caching across sites
   - Set site isolation level (isolated, partial, shared)

## Troubleshooting

### Plugin Won't Activate

Check your PHP and server environment:

```bash
# Verify PHP version
php -v

# Check required extensions
php -m | grep -E 'redis|json|mbstring|ffi'
```

### Redis Connection Issues

1. Verify Redis is running:
```bash
redis-cli ping
```

2. Check connection settings in the gCore configuration

3. Ensure your server can connect to Redis:
```bash
telnet your-redis-host 6379
```

### Debug Mode

Enable debug mode to get more information:

1. Navigate to **gCore → Settings**
2. Enable **Debug Mode**
3. Check the gCore logs at `/wp-content/gcore-logs/`

Alternatively, add to `wp-config.php`:

```php
define('GCORE_DEBUG', true);
```

### Compatibility Issues

If you experience compatibility issues with other plugins:

1. Temporarily deactivate other plugins to identify conflicts
2. Check gCore logs for specific error messages
3. Enable WordPress debug logging:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Next Steps

After installing and configuring gCore:

1. Explore the gCore dashboard for system status
2. Check out the example implementations in the documentation
3. Begin integrating gCore services into your themes and plugins
4. Consider using the gCore CLI for advanced management tasks

For more information, see:
- [gCore Documentation](INDEX.md)
- [API Manager Guide](Component-APIManager.md)
- [Security Manager Guide](managers/SecurityManager/README_SecurityManager.md)
- [CLI Usage Guide](Guide-CLI.md)