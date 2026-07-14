<?php
/**
 * gCore Framework - Message Broker Server
 * 
 * This script initializes the gCore framework and starts the message broker server.
 */

// Set error reporting for better debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constants
define('GCORE_CONFIG_PATH', __DIR__ . '/../../config');
define('ABSPATH', __DIR__ . '/../..');

// Require gCore
require_once __DIR__ . '/../../gcore-standalone.php';

// Require the MessageBroker class
require_once __DIR__ . '/MessageBroker.php';

// Determine environment (development, staging, production) from environment variable
$environment = getenv('GCORE_ENV') ?: 'development';

// Define API routes
$apiRoutes = [
    [
        'method' => 'GET',
        'path' => '/api/health',
        'handler' => function($request, $response) {
            return $response->withJson([
                'status' => 'ok',
                'timestamp' => time(),
                'version' => '1.0.0'
            ]);
        }
    ]
];

// Load configuration from YAML file
$configPath = __DIR__ . '/config/message_broker.yaml';
$config = [];

// Check if the YAML file exists
if (file_exists($configPath)) {
    // Load configuration from YAML
    $yamlConfig = yaml_parse_file($configPath);
    if ($yamlConfig !== false) {
        $config = $yamlConfig;
        echo "Loaded configuration from YAML file\n";
    } else {
        echo "Error parsing YAML configuration file\n";
    }
} else {
    echo "Configuration file not found, using default values\n";
}

// Override with environment variables if set
$config['core']['environment'] = getenv('GCORE_ENV') ?: ($config['core']['environment'] ?? 'development');
$config['core']['debug'] = $config['core']['environment'] !== 'production';
$config['node_id'] = $config['node_id'] ?? 'mb_' . gethostname();
$config['storage']['host'] = getenv('VALKEY_HOST') ?: ($config['storage']['host'] ?? 'localhost');
$config['storage']['port'] = (int)(getenv('VALKEY_PORT') ?: ($config['storage']['port'] ?? 6379));
$config['storage']['auth'] = getenv('VALKEY_AUTH') ?: ($config['storage']['auth'] ?? null);
$config['storage']['tls'] = getenv('VALKEY_TLS') === 'true' || ($config['storage']['tls'] ?? false);
$config['api']['port'] = (int)(getenv('API_PORT') ?: ($config['api']['port'] ?? 8080));
$config['api']['cors']['origins'] = getenv('CORS_ORIGINS') ? explode(',', getenv('CORS_ORIGINS')) : ($config['api']['cors']['origins'] ?? ['*']);
$config['api']['routes'] = $apiRoutes;

// Set message broker configuration
$messageBrokerConfig = [
    'queue_prefix' => $config['message_broker']['queue_prefix'] ?? 'mb:queue:',
    'max_queue_size' => (int)(getenv('MAX_QUEUE_SIZE') ?: ($config['message_broker']['max_queue_size'] ?? 10000)),
    'message_ttl' => (int)(getenv('MESSAGE_TTL') ?: ($config['message_broker']['message_ttl'] ?? 86400)),
];

echo "Starting Message Broker Server...\n";
echo "Environment: {$environment}\n";
echo "ValKey Host: {$config['storage']['host']}:{$config['storage']['port']}\n";
echo "API Port: {$config['api']['port']}\n";

try {
    // Initialize gCore
    $gCore = gcore_init($config);
    echo "gCore initialized successfully\n";
    
    // Get managers
    $errorManager = gcore_get_error_manager();
    echo "ErrorManager obtained successfully\n";
    
    $cacheManager = gcore_get_cache_manager();
    echo "CacheManager obtained successfully\n";
    var_dump($cacheManager->isInitialized());
    
    $securityManager = gcore_get_security_manager();
    echo "SecurityManager obtained successfully\n";
    
    $apiManager = gcore_get_api_manager();
    echo "APIManager obtained successfully\n";
} catch (\Exception $e) {
    echo "Error initializing gCore or obtaining managers: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// Require the SecurityExtension class
require_once __DIR__ . '/SecurityExtension.php';

// Configure security
$errorManager->log('info', 'Configuring security roles and permissions');

// Set up security roles and permissions
$securityManager->defineRole('admin', [
    'create_queue', 'delete_queue', 'list_queues',
    'publish:*', 'consume:*'
]);

$securityManager->defineRole('publisher', [
    'list_queues', 'publish:*'
]);

$securityManager->defineRole('consumer', [
    'list_queues', 'consume:*'
]);

// Create security extension
$securityExtension = new \gCore\Examples\MessageBroker\SecurityExtension(
    $cacheManager,
    $errorManager,
    $securityManager
);

// Load users from YAML file
$usersPath = __DIR__ . '/config/users.yaml';
$users = [];

if (file_exists($usersPath)) {
    $usersConfig = yaml_parse_file($usersPath);
    if ($usersConfig !== false && isset($usersConfig['users'])) {
        $users = $usersConfig['users'];
        echo "Loaded user configuration from YAML file\n";
    } else {
        echo "Error parsing users YAML configuration file\n";
    }
} else {
    echo "Users configuration file not found, using default users\n";
}

// Register default users if none in configuration or add secure API keys
if (empty($users)) {
    // Add demo users with random API keys
    $securityExtension->registerUser('admin_user', [
        'role' => 'admin',
        'api_key' => 'admin-api-key-' . bin2hex(random_bytes(8))
    ]);

    $securityExtension->registerUser('publisher_user', [
        'role' => 'publisher',
        'api_key' => 'pub-api-key-' . bin2hex(random_bytes(8))
    ]);

    $securityExtension->registerUser('consumer_user', [
        'role' => 'consumer',
        'api_key' => 'cons-api-key-' . bin2hex(random_bytes(8))
    ]);
} else {
    // Register users from configuration, but replace example API keys with secure ones
    foreach ($users as $userId => $userData) {
        // Generate a secure API key if none exists or it's the example one
        if (empty($userData['api_key']) || 
            strpos($userData['api_key'], '-example') !== false || 
            strpos($userData['api_key'], '-key-example') !== false) {
            
            $prefix = substr($userData['api_key'], 0, strpos($userData['api_key'], '-key-')) ?: $userId;
            $userData['api_key'] = $prefix . '-key-' . bin2hex(random_bytes(8));
        }
        
        $securityExtension->registerUser($userId, $userData);
    }
}

// Configure API authentication middleware
$apiManager->addMiddleware('auth', function($request, $response, $next) use ($securityExtension, $errorManager) {
    // Check for API key in header
    $apiKey = $request->getHeaderLine('X-API-Key');
    
    if (empty($apiKey)) {
        $errorManager->trackError('security', 'Missing API key', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        return $response->withJson(['error' => 'Unauthorized - Missing API key'], 401);
    }
    
    // Validate API key and get user
    $user = $securityExtension->getUserByApiKey($apiKey);
    
    if ($user === null) {
        $errorManager->trackError('security', 'Invalid API key', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'api_key' => $apiKey
        ]);
        return $response->withJson(['error' => 'Unauthorized - Invalid API key'], 401);
    }
    
    // Add user to request attributes for handlers
    return $next($request->withAttribute('user_id', $user['id']), $response);
});

// Initialize message broker with config from YAML
$messageBroker = new \gCore\Examples\MessageBroker\MessageBroker(
    $cacheManager,
    $errorManager,
    $securityManager,
    $apiManager,
    $messageBrokerConfig
);

// Display the API keys for demo users
echo "\nDemo Users API Keys:\n";
echo "-------------------\n";
foreach (['admin_user', 'publisher_user', 'consumer_user'] as $username) {
    $user = $securityManager->getUser($username);
    echo "{$username}: {$user['api_key']}\n";
}

// Start the API server
$errorManager->log('info', 'Starting Message Broker API server');
$apiManager->start();

// Keep the server running
echo "\nMessage Broker server is running. Press Ctrl+C to stop.\n";
while (true) {
    sleep(1);
}