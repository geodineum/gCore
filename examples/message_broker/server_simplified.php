<?php
/**
 * gCore Framework - Message Broker Server (Simplified)
 * 
 * This version uses the mock gCore framework components to demonstrate functionality.
 */

// Set error reporting for better debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constants if not already defined
if (!defined('GCORE_CONFIG_PATH')) {
    define('GCORE_CONFIG_PATH', __DIR__ . '/../../config');
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../..');
}

// Require the necessary files
require_once __DIR__ . '/test_simplified.php';  // This provides our mock managers
require_once __DIR__ . '/SecurityExtension.php';

echo "Starting Message Broker Server (Simplified Mode)...\n";
echo "=================================================\n\n";

// Initialize gCore
$gCore = gcore_init();

// Get managers
$cacheManager = gcore_get_cache_manager();
$errorManager = gcore_get_error_manager();
$securityManager = gcore_get_security_manager();
$apiManager = gcore_get_api_manager();

// Configure security
$errorManager->log('info', 'Configuring security roles and permissions');

// Set up security roles and permissions
$securityManager->defineRole('admin', [
    'create_queue', 'delete_queue', 'list_queues', 'publish:*', 'consume:*'
]);

$securityManager->defineRole('publisher', [
    'list_queues', 'publish:*'
]);

$securityManager->defineRole('consumer', [
    'list_queues', 'consume:*'
]);

// Create security extension
class SimplifiedSecurityExtension {
    private $securityManager;
    private $userRoles = [];
    private $apiKeys = [];
    
    public function __construct($securityManager) {
        $this->securityManager = $securityManager;
    }
    
    public function registerUser($userId, array $userData) {
        // Store user data
        if (isset($userData['role'])) {
            $this->userRoles[$userId] = $userData['role'];
            $this->securityManager->assignRole($userId, $userData['role']);
        }
        
        // Store API key
        if (isset($userData['api_key'])) {
            $this->apiKeys[$userData['api_key']] = $userId;
        }
        
        return true;
    }
    
    public function getUserByApiKey($apiKey) {
        if (!isset($this->apiKeys[$apiKey])) {
            return null;
        }
        
        $userId = $this->apiKeys[$apiKey];
        
        return [
            'id' => $userId,
            'role' => $this->userRoles[$userId] ?? 'guest'
        ];
    }
}

// Create our simplified security extension
$securityExtension = new SimplifiedSecurityExtension($securityManager);

// Create some demo users
$securityExtension->registerUser('admin_user', [
    'role' => 'admin',
    'api_key' => 'admin-key-12345'
]);

$securityExtension->registerUser('publisher_user', [
    'role' => 'publisher',
    'api_key' => 'publish-key-12345'
]);

$securityExtension->registerUser('consumer_user', [
    'role' => 'consumer',
    'api_key' => 'consume-key-12345'
]);

// Mock message broker implementation
class SimplifiedMessageBroker {
    private $cacheManager;
    private $errorManager;
    private $securityManager;
    private $apiManager;
    private $queues = [];
    private $queuePrefix = 'mb:queue:';
    
    public function __construct($cacheManager, $errorManager, $securityManager, $apiManager) {
        $this->cacheManager = $cacheManager;
        $this->errorManager = $errorManager;
        $this->securityManager = $securityManager;
        $this->apiManager = $apiManager;
        
        // Register endpoints
        $this->apiManager->registerEndpoint('POST', '/queues', [$this, 'createQueue']);
        $this->apiManager->registerEndpoint('GET', '/queues', [$this, 'listQueues']);
        $this->apiManager->registerEndpoint('DELETE', '/queues/{queue}', [$this, 'deleteQueue']);
        $this->apiManager->registerEndpoint('POST', '/messages/{queue}', [$this, 'publishMessage']);
        $this->apiManager->registerEndpoint('GET', '/messages/{queue}', [$this, 'consumeMessage']);
    }
    
    public function createQueue($request, $response) {
        $queueName = $request->getParsedBody()['name'] ?? null;
        
        if (empty($queueName)) {
            return ['error' => 'Queue name is required'];
        }
        
        $queueKey = $this->queuePrefix . $queueName;
        
        if (isset($this->queues[$queueKey])) {
            return ['error' => 'Queue already exists'];
        }
        
        $this->queues[$queueKey] = [
            'created' => time(),
            'messages' => [],
            'stats' => [
                'publish_count' => 0,
                'consume_count' => 0
            ]
        ];
        
        $this->cacheManager->set($queueKey, $this->queues[$queueKey]);
        
        return [
            'success' => true,
            'queue' => $queueName
        ];
    }
    
    public function listQueues($request, $response) {
        $result = [];
        
        foreach ($this->queues as $key => $data) {
            $name = substr($key, strlen($this->queuePrefix));
            $result[] = [
                'name' => $name,
                'message_count' => count($data['messages']),
                'created' => $data['created'],
                'stats' => $data['stats']
            ];
        }
        
        return [
            'queues' => $result,
            'count' => count($result)
        ];
    }
    
    public function deleteQueue($request, $response, $args) {
        $queueName = $args['queue'] ?? null;
        
        if (empty($queueName)) {
            return ['error' => 'Queue name is required'];
        }
        
        $queueKey = $this->queuePrefix . $queueName;
        
        if (!isset($this->queues[$queueKey])) {
            return ['error' => 'Queue not found'];
        }
        
        unset($this->queues[$queueKey]);
        $this->cacheManager->delete($queueKey);
        
        return ['success' => true];
    }
    
    public function publishMessage($request, $response, $args) {
        $queueName = $args['queue'] ?? null;
        $message = $request->getParsedBody()['message'] ?? null;
        
        if (empty($queueName)) {
            return ['error' => 'Queue name is required'];
        }
        
        if ($message === null) {
            return ['error' => 'Message is required'];
        }
        
        $queueKey = $this->queuePrefix . $queueName;
        
        if (!isset($this->queues[$queueKey])) {
            return ['error' => 'Queue not found'];
        }
        
        $messageId = uniqid('msg-');
        $messageObj = [
            'id' => $messageId,
            'timestamp' => time(),
            'data' => $message,
            'publisher' => $request->getAttribute('user_id')
        ];
        
        $this->queues[$queueKey]['messages'][] = $messageObj;
        $this->queues[$queueKey]['stats']['publish_count']++;
        
        $this->cacheManager->set($queueKey, $this->queues[$queueKey]);
        
        return [
            'success' => true,
            'message_id' => $messageId
        ];
    }
    
    public function consumeMessage($request, $response, $args) {
        $queueName = $args['queue'] ?? null;
        $acknowledge = true; // In a real implementation, get this from request
        
        if (empty($queueName)) {
            return ['error' => 'Queue name is required'];
        }
        
        $queueKey = $this->queuePrefix . $queueName;
        
        if (!isset($this->queues[$queueKey])) {
            return ['error' => 'Queue not found'];
        }
        
        if (empty($this->queues[$queueKey]['messages'])) {
            return ['empty' => true];
        }
        
        $message = array_shift($this->queues[$queueKey]['messages']);
        
        if (!$acknowledge) {
            $this->queues[$queueKey]['messages'][] = $message;
        } else {
            $this->queues[$queueKey]['stats']['consume_count']++;
        }
        
        $this->cacheManager->set($queueKey, $this->queues[$queueKey]);
        
        return [
            'message' => $message,
            'acknowledged' => $acknowledge
        ];
    }
}

// Initialize message broker
$messageBroker = new SimplifiedMessageBroker(
    $cacheManager,
    $errorManager,
    $securityManager,
    $apiManager
);

// Display the API keys
echo "Demo Users API Keys:\n";
echo "-------------------\n";
echo "admin_user: admin-key-12345\n";
echo "publisher_user: publish-key-12345\n";
echo "consumer_user: consume-key-12345\n";

// Start the API server
$errorManager->log('info', 'Message Broker server started');
$apiManager->start();

echo "\nSimulated Message Broker running...\n";
echo "Sample operations:\n";

// Create a mock request class
class MockRequest {
    private $body;
    private $attributes = [];
    
    public function __construct($body = [], $attributes = []) {
        $this->body = $body;
        $this->attributes = $attributes;
    }
    
    public function getParsedBody() {
        return $this->body;
    }
    
    public function getAttribute($name) {
        return $this->attributes[$name] ?? null;
    }
}

// Simulate some operations
echo "\n1. Creating test queue...\n";
$createResult = $messageBroker->createQueue(
    new MockRequest(['name' => 'test_queue']),
    null
);
echo "Result: " . json_encode($createResult, JSON_PRETTY_PRINT) . "\n";

echo "\n2. Publishing a message...\n";
$publishResult = $messageBroker->publishMessage(
    new MockRequest(['message' => 'Hello, this is a test message'], ['user_id' => 'admin_user']),
    null,
    ['queue' => 'test_queue']
);
echo "Result: " . json_encode($publishResult, JSON_PRETTY_PRINT) . "\n";

echo "\n3. Listing queues...\n";
$listResult = $messageBroker->listQueues(
    new MockRequest(),
    null
);
echo "Result: " . json_encode($listResult, JSON_PRETTY_PRINT) . "\n";

echo "\n4. Consuming a message...\n";
$consumeResult = $messageBroker->consumeMessage(
    new MockRequest([], ['user_id' => 'consumer_user']),
    null,
    ['queue' => 'test_queue']
);
echo "Result: " . json_encode($consumeResult, JSON_PRETTY_PRINT) . "\n";

echo "\nSimulation completed! The Message Broker is working correctly in simplified mode.\n";
echo "You can now try running the full version with ValKey/Redis once it's available.\n";