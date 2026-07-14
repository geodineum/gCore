<?php
/**
 * gCore Framework - Cache Manager Example
 * 
 * This example demonstrates the Cache Manager capabilities
 * including basic caching, stream operations, and batch operations.
 */

// Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

echo "gCore Cache Manager Example\n";
echo "==========================\n\n";

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $env = file_get_contents(__DIR__ . '/../.env');
    $lines = explode("\n", $env);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . "=" . trim($value));
        }
    }
}

// Initialize gCore with our configuration
$config = [
    'core' => [
        'environment' => 'development',
        'debug' => true,
    ],
    'site_id' => 'cache_example',
    'node_id' => 'cache_node',
    'storage' => [
        'host' => getenv('VALKEY_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('VALKEY_PORT') ?: 6379),
        'auth' => getenv('VALKEY_AUTH') ?: null,
        'tls' => getenv('VALKEY_TLS') === 'true',
    ]
];

echo "Initializing gCore...\n";
// Use the standalone initialization pattern
require_once __DIR__ . '/../gcore-standalone.php';
$gCore = gcore_init($config);

// Get the Cache Manager instance
echo "Loading Cache Manager...\n";
$cache = gcore_get_cache_manager();

// Demo 1: Basic Caching Operations
echo "\n=== Basic Caching Operations Demo ===\n";

// Set a cache value
$cache->set('user:1', ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'], 3600);
echo "Set cache for 'user:1' with TTL of 3600 seconds\n";

// Get a cache value
$user = $cache->get('user:1');
echo "Retrieved user from cache: " . ($user ? json_encode($user) : 'Not found') . "\n";

// Check if a key exists
$exists = $cache->exists('user:1');
echo "Key 'user:1' exists: " . ($exists ? 'Yes' : 'No') . "\n";

// Get TTL of a key
$ttl = $cache->ttl('user:1');
echo "Time-to-live for 'user:1': $ttl seconds\n";

// Set another key
$cache->set('user:2', ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com'], 3600);
echo "Set cache for 'user:2'\n";

// Count keys with pattern
$count = $cache->countKeys('user:*');
echo "Number of user keys: $count\n";

// Delete a key
$cache->delete('user:2');
echo "Deleted 'user:2' from cache\n";

// Count again to verify deletion
$count = $cache->countKeys('user:*');
echo "Number of user keys after deletion: $count\n";

// Demo 2: Hash Operations
echo "\n=== Hash Operations Demo ===\n";

// Set multiple hash fields
$cache->hSet('profile:1', [
    'username' => 'johndoe',
    'full_name' => 'John Doe',
    'age' => 30,
    'location' => 'New York',
    'skills' => json_encode(['PHP', 'JavaScript', 'MySQL'])
]);
echo "Set hash fields for 'profile:1'\n";

// Get a single hash field
$username = $cache->hGet('profile:1', 'username');
echo "Username from hash: $username\n";

// Get all hash fields
$profile = $cache->hGetAll('profile:1');
echo "Full profile: " . json_encode($profile) . "\n";

// Check if a hash field exists
$hasLocation = $cache->hExists('profile:1', 'location');
echo "Profile has location field: " . ($hasLocation ? 'Yes' : 'No') . "\n";

// Delete a hash field
$cache->hDel('profile:1', 'age');
echo "Deleted 'age' field from profile\n";

// Get all hash fields again to verify deletion
$profile = $cache->hGetAll('profile:1');
echo "Updated profile: " . json_encode($profile) . "\n";

// Demo 3: Stream Operations
echo "\n=== Stream Operations Demo ===\n";

// Create a stream for logging events
$streamKey = 'logs:application';

// Add entries to the stream
echo "Adding entries to the '$streamKey' stream...\n";
$cache->streamAdd($streamKey, ['event' => 'user_login', 'user_id' => 1, 'timestamp' => time()]);
$cache->streamAdd($streamKey, ['event' => 'user_action', 'user_id' => 1, 'action' => 'view_profile', 'timestamp' => time()]);
$cache->streamAdd($streamKey, ['event' => 'user_action', 'user_id' => 2, 'action' => 'update_settings', 'timestamp' => time()]);
$cache->streamAdd($streamKey, ['event' => 'user_logout', 'user_id' => 1, 'timestamp' => time()]);

// Read from the stream
$events = $cache->streamRead($streamKey, 10);
echo "Read " . count($events) . " events from the stream:\n";
foreach ($events as $id => $data) {
    echo "  • [$id] Event: {$data['event']} - ";
    
    if (isset($data['action'])) {
        echo "Action: {$data['action']} - ";
    }
    
    echo "User: {$data['user_id']}\n";
}

// Get stream length
$length = $cache->streamLength($streamKey);
echo "Stream length: $length entries\n";

// Create consumer group for the stream
$cache->streamCreateGroup($streamKey, 'analytics_group');
echo "Created consumer group 'analytics_group' for stream\n";

// Read as part of a consumer group
$consumerEvents = $cache->streamReadGroup($streamKey, 'analytics_group', 'consumer1', 2);
echo "Consumer 'consumer1' read " . count($consumerEvents) . " events\n";

// Demo 4: Batch Operations
echo "\n=== Batch Operations Demo ===\n";

// Set multiple keys at once
$userData = [
    'batch:user:1' => json_encode(['id' => 1, 'name' => 'John Doe']),
    'batch:user:2' => json_encode(['id' => 2, 'name' => 'Jane Smith']),
    'batch:user:3' => json_encode(['id' => 3, 'name' => 'Bob Johnson']),
    'batch:user:4' => json_encode(['id' => 4, 'name' => 'Alice Williams']),
    'batch:user:5' => json_encode(['id' => 5, 'name' => 'Charlie Brown'])
];

$cache->mSet($userData);
echo "Set " . count($userData) . " user records in a batch operation\n";

// Get multiple keys at once
$keys = ['batch:user:1', 'batch:user:3', 'batch:user:5'];
$multiUsers = $cache->mGet($keys);
echo "Retrieved " . count($multiUsers) . " users in a batch operation:\n";
foreach ($multiUsers as $key => $value) {
    $userData = json_decode($value, true);
    echo "  • $key: {$userData['name']}\n";
}

// Delete multiple keys at once
$cache->mDel(['batch:user:2', 'batch:user:4']);
echo "Deleted 2 users in a batch operation\n";

// Count the remaining keys
$count = $cache->countKeys('batch:user:*');
echo "Remaining batch users: $count\n";

// Demo 5: Pub/Sub (if supported)
echo "\n=== Publish Messages Demo ===\n";

// Publish some messages
echo "Publishing messages to channels...\n";
$cache->publish('channel:notifications', json_encode([
    'type' => 'notification',
    'title' => 'New Feature Available',
    'message' => 'Check out our new reporting dashboard!',
    'timestamp' => time()
]));
echo "Published notification message\n";

$cache->publish('channel:system', json_encode([
    'type' => 'system',
    'level' => 'info',
    'message' => 'System maintenance scheduled',
    'timestamp' => time()
]));
echo "Published system message\n";

echo "\nNote: To see subscriptions in action, you would need a separate process\n";
echo "to subscribe to these channels.\n";

echo "\nThis example demonstrates several key features of the gCore Cache Manager.\n";
echo "In a real application, these capabilities would be integrated into your\n";
echo "business logic, API layer, and event processing systems.\n";