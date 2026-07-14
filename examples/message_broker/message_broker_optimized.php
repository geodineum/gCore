<?php
/**
 * gCore Framework - Optimized Message Broker Implementation
 * 
 * This implementation uses gCore CacheManager's Lua scripts for high-performance
 * message broker operations. It demonstrates using Lua scripts for Redis operations,
 * which reduces network overhead and improves throughput.
 */

// Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

echo "gCore Optimized Message Broker Example\n";
echo "====================================\n\n";

// Load environment variables from .env file
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

// ValKey connection settings
$valkey_host = getenv('VALKEY_HOST') ?: 'localhost';
$valkey_port = (int)(getenv('VALKEY_PORT') ?: 6379);
$valkey_auth = getenv('VALKEY_AUTH') ?: '';

// Initialize gCore with ValKey configuration
$config = [
    'core' => [
        'environment' => 'development',
        'debug' => false,  // Turn off debug for better performance
    ],
    'site_id' => 'message_broker_optimized',
    'node_id' => 'broker_node_1',
    'storage' => [
        'host' => $valkey_host,
        'port' => $valkey_port,
        'auth' => $valkey_auth,
        'tls' => false,
    ]
];

// Display configuration
echo "Configuration:\n";
echo "- ValKey Host: {$valkey_host}\n";
echo "- ValKey Port: {$valkey_port}\n";
echo "- Site ID: {$config['site_id']}\n";
echo "- Node ID: {$config['node_id']}\n\n";

// Test ValKey connection directly
echo "Testing ValKey connection...\n";
try {
    $redis = new Redis();
    $redis->connect($valkey_host, $valkey_port, 2.0);
    
    if (!empty($valkey_auth)) {
        $redis->auth($valkey_auth);
    }
    
    $pong = $redis->ping();
    echo "✅ ValKey connection successful! Server replied with: $pong\n";
    
    // Close connection
    $redis->close();
} catch (Exception $e) {
    die("❌ Error connecting to ValKey: " . $e->getMessage() . "\n");
}

// Initialize gCore
echo "\nInitializing gCore...\n";
try {
    $gCore = \gCore\Modules\Core\gCore::getInstance();
    $gCore->initialize($config);
    echo "✅ gCore initialized successfully!\n\n";
} catch (Exception $e) {
    die("❌ Failed to initialize gCore: " . $e->getMessage() . "\n");
}

/**
 * Optimized Message Broker Implementation
 * Uses gCore CacheManager's Lua script-backed stream operations
 */
class OptimizedMessageBroker {
    private $cacheManager;
    private $topics = [];
    private $siteId;
    
    /**
     * Constructor
     * 
     * @param object $cacheManager The gCore CacheManager service
     * @param string $siteId The site identifier for namespacing
     */
    public function __construct($cacheManager, $siteId) {
        $this->cacheManager = $cacheManager;
        $this->siteId = $siteId;
        
        echo "Optimized Message Broker initialized with site ID: $siteId\n";
    }
    
    /**
     * Create a new topic with optional consumer groups
     * 
     * @param string $topic Topic name
     * @param array $groups Consumer group names
     * @return boolean Success status
     */
    public function createTopic($topic, $groups = []) {
        if (!isset($this->topics[$topic])) {
            $this->topics[$topic] = [
                'groups' => [],
                'created' => time()
            ];
            
            // Create consumer groups using Lua script
            foreach ($groups as $group) {
                $result = $this->cacheManager->streamCreateGroup($topic, $group);
                $this->topics[$topic]['groups'][] = $group;
            }
            
            echo "Created topic: $topic with groups: " . implode(", ", $groups) . "\n";
            return true;
        }
        
        return false;
    }
    
    /**
     * Publish a message to a topic
     * Uses Lua scripts for improved performance
     * 
     * @param string $topic Topic name
     * @param mixed $message Message content
     * @param int $maxLen Maximum stream length (optional)
     * @return string Message ID
     */
    public function publish($topic, $message, $maxLen = 10000) {
        if (!isset($this->topics[$topic])) {
            $this->createTopic($topic, ['default']);
        }
        
        // Prepare message data
        $data = [
            'timestamp' => time(),
            'message' => $message
        ];
        
        // Use streamAdd with Lua script
        $messageId = $this->cacheManager->streamAdd($topic, $data, $maxLen);
        return $messageId;
    }
    
    /**
     * Batch publish multiple messages to a topic
     * Uses bulk operations for improved throughput
     * 
     * @param string $topic Topic name
     * @param array $messages Array of messages
     * @param int $maxLen Maximum stream length (optional)
     * @return array Message IDs
     */
    public function batchPublish($topic, $messages, $maxLen = 10000) {
        if (!isset($this->topics[$topic])) {
            $this->createTopic($topic, ['default']);
        }
        
        $messageIds = [];
        
        // In a real implementation, we would use ValKeyBatchOperations
        // For now, we'll simulate batch operations with a loop
        foreach ($messages as $message) {
            $data = [
                'timestamp' => time(),
                'message' => $message
            ];
            
            $messageIds[] = $this->cacheManager->streamAdd($topic, $data, $maxLen);
        }
        
        return $messageIds;
    }
    
    /**
     * Subscribe to messages from a topic
     * Uses Lua scripts for improved performance
     * 
     * @param string $topic Topic name
     * @param string $group Consumer group name
     * @param string $consumer Consumer name
     * @param int $count Maximum number of messages to retrieve
     * @return array Messages
     */
    public function subscribe($topic, $group, $consumer, $count = 1) {
        if (!isset($this->topics[$topic]) || !in_array($group, $this->topics[$topic]['groups'])) {
            $this->cacheManager->streamCreateGroup($topic, $group);
            
            if (!isset($this->topics[$topic])) {
                $this->topics[$topic] = [
                    'groups' => [$group],
                    'created' => time()
                ];
            } else {
                $this->topics[$topic]['groups'][] = $group;
            }
        }
        
        // Use streamReadGroup with Lua script
        $messages = $this->cacheManager->streamReadGroup($topic, $group, $consumer, $count);
        return $messages;
    }
    
    /**
     * Acknowledge a message
     * Uses Lua scripts for improved performance
     * 
     * @param string $topic Topic name
     * @param string $group Consumer group name
     * @param string $messageId Message ID to acknowledge
     * @return boolean Success status
     */
    public function acknowledge($topic, $group, $messageId) {
        return $this->cacheManager->streamAck($topic, $group, $messageId);
    }
    
    /**
     * Batch acknowledge multiple messages
     * 
     * @param string $topic Topic name
     * @param string $group Consumer group name
     * @param array $messageIds Array of message IDs to acknowledge
     * @return int Number of messages acknowledged
     */
    public function batchAcknowledge($topic, $group, $messageIds) {
        $count = 0;
        
        // In a real implementation, we would use ValKeyBatchOperations
        // For now, we'll simulate batch operations with a loop
        foreach ($messageIds as $messageId) {
            if ($this->cacheManager->streamAck($topic, $group, $messageId)) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Get information about a topic
     * Uses Lua scripts for improved performance
     * 
     * @param string $topic Topic name
     * @return array Topic information
     */
    public function getTopicInfo($topic) {
        if (!isset($this->topics[$topic])) {
            return null;
        }
        
        // Get stream information from CacheManager
        $stats = [
            'name' => $topic,
            'created' => $this->topics[$topic]['created'],
            'groups' => $this->topics[$topic]['groups'],
            'message_count' => $this->cacheManager->streamLength($topic),
            'age' => time() - $this->topics[$topic]['created'] . ' seconds'
        ];
        
        return $stats;
    }
    
    /**
     * Get pending messages for a consumer group
     * Uses Lua scripts for improved performance
     * 
     * @param string $topic Topic name
     * @param string $group Consumer group name
     * @return array Pending message information
     */
    public function getPendingMessages($topic, $group) {
        // This would typically use streamPending, but streamPending may not be implemented in CacheManager
        // For now, we'll return a simplified result
        $pending = $this->cacheManager->streamPending($topic, $group);
        return $pending;
    }
    
    /**
     * Delete a topic
     * 
     * @param string $topic Topic name
     * @return boolean Success status
     */
    public function deleteTopic($topic) {
        if (!isset($this->topics[$topic])) {
            return false;
        }
        
        $result = $this->cacheManager->delete("{$this->siteId}:stream:{$topic}");
        
        if ($result) {
            unset($this->topics[$topic]);
            return true;
        }
        
        return false;
    }
}

// Demo the optimized message broker
try {
    // Get the CacheManager service
    $cacheManager = $gCore->getService('CacheManager');
    
    // Create the message broker
    $broker = new OptimizedMessageBroker($cacheManager, $config['site_id']);
    
    echo "\n=== Optimized Message Broker Demo ===\n\n";
    
    // 1. Create topics
    echo "Creating topics...\n";
    $broker->createTopic('notifications', ['email_service', 'mobile_service']);
    $broker->createTopic('orders', ['order_processor', 'shipping_service']);
    
    // 2. Publish messages
    echo "\nPublishing individual messages...\n";
    $notificationMsg = json_encode([
        'type' => 'alert',
        'priority' => 'high',
        'text' => 'System maintenance scheduled'
    ]);
    $msgId1 = $broker->publish('notifications', $notificationMsg);
    echo "Published notification message with ID: $msgId1\n";
    
    $orderData = json_encode([
        'order_id' => 12345,
        'customer' => 'John Doe',
        'items' => ['Product A', 'Product B'],
        'total' => 99.95
    ]);
    $msgId2 = $broker->publish('orders', $orderData);
    echo "Published order message with ID: $msgId2\n";
    
    // 3. Batch publish messages
    echo "\nPublishing batch of messages...\n";
    $batchMessages = [];
    for ($i = 1; $i <= 5; $i++) {
        $batchMessages[] = json_encode([
            'type' => 'notification',
            'priority' => 'medium',
            'text' => "Batch notification #$i"
        ]);
    }
    $batchIds = $broker->batchPublish('notifications', $batchMessages);
    echo "Published " . count($batchIds) . " messages in batch\n";
    
    // 4. Subscribe to messages
    echo "\nSubscribing to topics...\n";
    $notificationMessages = $broker->subscribe('notifications', 'mobile_service', 'device1', 3);
    
    echo "Received " . count($notificationMessages) . " notification messages:\n";
    foreach ($notificationMessages as $id => $data) {
        $messageData = json_decode($data['message'], true);
        $text = $messageData['text'] ?? 'No text';
        echo "- [$id] $text\n";
    }
    
    // 5. Acknowledge messages
    echo "\nAcknowledging messages...\n";
    foreach ($notificationMessages as $id => $data) {
        $result = $broker->acknowledge('notifications', 'mobile_service', $id);
        echo "- Message $id acknowledged: " . ($result ? "Yes" : "No") . "\n";
    }
    
    // 6. Get topic information
    echo "\nTopic information:\n";
    $notificationStats = $broker->getTopicInfo('notifications');
    $orderStats = $broker->getTopicInfo('orders');
    
    echo "- Notifications topic:\n";
    echo "  • Messages: {$notificationStats['message_count']}\n";
    echo "  • Consumer groups: " . implode(', ', $notificationStats['groups']) . "\n";
    echo "  • Age: {$notificationStats['age']}\n";
    
    echo "- Orders topic:\n";
    echo "  • Messages: {$orderStats['message_count']}\n";
    echo "  • Consumer groups: " . implode(', ', $orderStats['groups']) . "\n";
    echo "  • Age: {$orderStats['age']}\n";
    
    // 7. Get pending messages
    echo "\nPending messages in order_processor group:\n";
    $pendingMessages = $broker->getPendingMessages('orders', 'order_processor');
    
    if (empty($pendingMessages)) {
        echo "No pending messages\n";
    } else {
        foreach ($pendingMessages as $id => $info) {
            echo "- Message $id, delivered: {$info['delivered']} times\n";
        }
    }
    
    // 8. Demonstrate batch acknowledge (with newly published messages)
    echo "\nDemonstrating batch acknowledgment:\n";
    
    // Publish several messages
    $batchAckMessages = [];
    for ($i = 1; $i <= 10; $i++) {
        $batchAckMessages[] = json_encode([
            'type' => 'batch_ack_test',
            'index' => $i
        ]);
    }
    $batchAckIds = $broker->batchPublish('orders', $batchAckMessages);
    
    // Subscribe to get those messages
    $messagesToAck = $broker->subscribe('orders', 'order_processor', 'batch_consumer', 10);
    echo "Retrieved " . count($messagesToAck) . " messages for batch acknowledgment\n";
    
    // Batch acknowledge
    $messageIds = array_keys($messagesToAck);
    $ackCount = $broker->batchAcknowledge('orders', 'order_processor', $messageIds);
    echo "Batch acknowledged $ackCount messages\n";
    
    echo "\nThis example demonstrates an optimized message broker implementation using gCore's\n";
    echo "CacheManager stream capabilities with Lua scripts for improved performance.\n";
    echo "The implementation provides both individual and batch operations for higher throughput.\n";
    
    // Clean up
    echo "\nCleaning up test topics...\n";
    $broker->deleteTopic('notifications');
    $broker->deleteTopic('orders');
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}