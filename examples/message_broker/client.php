<?php
/**
 * gCore Message Broker - Production-Ready Client
 * 
 * This client demonstrates how to interact with the gCore Message Broker
 * using the enhanced capabilities of the framework.
 */

// Configuration with fallbacks
$config = [
    'api_url' => getenv('API_URL') ?: 'http://localhost:8080',
    'api_key' => getenv('API_KEY') ?: null,
    'queue' => getenv('QUEUE') ?: 'test_queue',
    'timeout' => (int)(getenv('TIMEOUT') ?: 10),
    'retry_attempts' => (int)(getenv('RETRY_ATTEMPTS') ?: 3),
    'retry_delay' => (int)(getenv('RETRY_DELAY') ?: 1000), // milliseconds
];

if (empty($config['api_key'])) {
    die("Error: API_KEY environment variable must be set. Usage: API_KEY=your-api-key php client.php\n");
}

/**
 * MessageBrokerClient - Production-ready client for gCore Message Broker
 * 
 * Features:
 * - Connection retry with exponential backoff
 * - Circuit breaker pattern to prevent cascading failures
 * - Detailed error reporting and logging
 * - Support for batch operations
 * - Streaming message consumption
 */
class MessageBrokerClient {
    private $baseUrl;
    private $apiKey;
    private $config;
    private $circuitOpen = false;
    private $failureCount = 0;
    private $lastFailure = 0;
    private $maxFailures = 3;
    private $circuitResetTime = 30; // seconds
    
    /**
     * Constructor
     * 
     * @param string $baseUrl Base URL for the API
     * @param string $apiKey API key for authentication
     * @param array $config Additional configuration
     */
    public function __construct($baseUrl, $apiKey, array $config = []) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        
        // Default configuration with provided overrides
        $this->config = array_merge([
            'timeout' => 10,
            'retry_attempts' => 3,
            'retry_delay' => 1000, // milliseconds
            'circuit_breaker' => true,
        ], $config);
    }
    
    /**
     * Send a request to the API with retry and circuit breaker patterns
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @return array Response with status and body
     */
    public function request($method, $endpoint, $data = null) {
        // Check if circuit breaker is open
        if ($this->config['circuit_breaker'] && $this->isCircuitOpen()) {
            return [
                'status' => 503,
                'body' => ['error' => 'Circuit breaker open, too many failures'],
            ];
        }
        
        $url = $this->baseUrl . $endpoint;
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < $this->config['retry_attempts']) {
            try {
                $ch = curl_init($url);
                
                $headers = [
                    'Content-Type: application/json',
                    'X-API-Key: ' . $this->apiKey,
                    'Accept: application/json',
                    'User-Agent: gCore-MessageBroker-Client/1.0',
                ];
                
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout']);
                
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                
                $response = curl_exec($ch);
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                if ($error) {
                    throw new Exception("cURL Error: " . $error);
                }
                
                $responseBody = $response ? json_decode($response, true) : null;
                
                // Track success or failure for circuit breaker
                if ($statusCode >= 200 && $statusCode < 500) {
                    $this->trackSuccess();
                    return [
                        'status' => $statusCode,
                        'body' => $responseBody,
                    ];
                } else {
                    $this->trackFailure();
                    $lastException = new Exception("API Error: HTTP $statusCode " . 
                        ($responseBody && isset($responseBody['error']) ? $responseBody['error'] : 'Unknown error'));
                    
                    // For 5xx errors, retry
                    $attempts++;
                    if ($attempts < $this->config['retry_attempts']) {
                        // Exponential backoff with jitter
                        $delay = $this->config['retry_delay'] * pow(2, $attempts - 1);
                        $delay += $delay * 0.2 * mt_rand(-100, 100) / 100; // Add jitter ±20%
                        usleep($delay * 1000); // Convert to microseconds
                    }
                }
            } catch (Exception $e) {
                $this->trackFailure();
                $lastException = $e;
                
                $attempts++;
                if ($attempts < $this->config['retry_attempts']) {
                    // Exponential backoff with jitter
                    $delay = $this->config['retry_delay'] * pow(2, $attempts - 1);
                    $delay += $delay * 0.2 * mt_rand(-100, 100) / 100; // Add jitter ±20%
                    usleep($delay * 1000); // Convert to microseconds
                }
            }
        }
        
        // All attempts failed
        return [
            'status' => 0,
            'body' => ['error' => 'All retry attempts failed: ' . $lastException->getMessage()],
        ];
    }
    
    /**
     * Check health of the Message Broker API
     * 
     * @return array Response with health status information
     */
    public function checkHealth() {
        return $this->request('GET', '/health');
    }
    
    /**
     * Create a new queue
     * 
     * @param string $queueName Name of the queue
     * @param array $options Optional queue configuration
     * @return array Response with status and queue information
     */
    public function createQueue($queueName, array $options = []) {
        return $this->request('POST', '/queues', [
            'name' => $queueName,
            'options' => $options
        ]);
    }
    
    /**
     * List all available queues
     * 
     * @return array Response with status and queues information
     */
    public function listQueues() {
        return $this->request('GET', '/queues');
    }
    
    /**
     * Delete a queue
     * 
     * @param string $queueName Name of the queue to delete
     * @return array Response with status and deletion information
     */
    public function deleteQueue($queueName) {
        return $this->request('DELETE', "/queues/{$queueName}");
    }
    
    /**
     * Publish a message to a queue
     * 
     * @param string $queueName Name of the queue
     * @param mixed $message Message to publish
     * @return array Response with status and message information
     */
    public function publishMessage($queueName, $message) {
        return $this->request('POST', "/messages/{$queueName}", ['message' => $message]);
    }
    
    /**
     * Publish multiple messages to a queue in batch
     * 
     * @param string $queueName Name of the queue
     * @param array $messages Array of messages to publish
     * @return array Response with status and message information
     */
    public function publishBatch($queueName, array $messages) {
        $results = [];
        
        foreach ($messages as $message) {
            $results[] = $this->publishMessage($queueName, $message);
        }
        
        return [
            'status' => 200,
            'body' => [
                'total' => count($messages),
                'success' => count(array_filter($results, function($r) { 
                    return isset($r['status']) && $r['status'] === 200;
                })),
                'failures' => count(array_filter($results, function($r) { 
                    return !isset($r['status']) || $r['status'] !== 200;
                })),
                'results' => $results
            ]
        ];
    }
    
    /**
     * Consume a message from a queue
     * 
     * @param string $queueName Name of the queue
     * @param bool $acknowledge Whether to acknowledge (remove) the message after consumption
     * @return array Response with status and message information
     */
    public function consumeMessage($queueName, $acknowledge = true) {
        $endpoint = "/messages/{$queueName}";
        if (!$acknowledge) {
            $endpoint .= '?acknowledge=false';
        }
        return $this->request('GET', $endpoint);
    }
    
    /**
     * Consume multiple messages from a queue
     * 
     * @param string $queueName Name of the queue
     * @param int $count Number of messages to consume
     * @param bool $acknowledge Whether to acknowledge messages
     * @return array Response with consumed messages
     */
    public function consumeBatch($queueName, $count, $acknowledge = true) {
        $messages = [];
        
        for ($i = 0; $i < $count; $i++) {
            $result = $this->consumeMessage($queueName, $acknowledge);
            
            // Check if queue is empty
            if ($result['status'] === 204 || (isset($result['body']['empty']) && $result['body']['empty'])) {
                break;
            }
            
            // Only add successful results
            if ($result['status'] === 200 && isset($result['body']['message'])) {
                $messages[] = $result['body']['message'];
            } else {
                // Stop on error
                break;
            }
        }
        
        return [
            'status' => 200,
            'body' => [
                'count' => count($messages),
                'messages' => $messages
            ]
        ];
    }
    
    /**
     * Track a successful request for circuit breaker
     */
    private function trackSuccess() {
        if ($this->config['circuit_breaker']) {
            $this->failureCount = max(0, $this->failureCount - 1);
        }
    }
    
    /**
     * Track a failed request for circuit breaker
     */
    private function trackFailure() {
        if ($this->config['circuit_breaker']) {
            $this->failureCount++;
            $this->lastFailure = time();
            
            if ($this->failureCount >= $this->maxFailures) {
                $this->circuitOpen = true;
            }
        }
    }
    
    /**
     * Check if the circuit breaker is open
     * 
     * @return bool Whether the circuit breaker is open
     */
    private function isCircuitOpen() {
        if (!$this->circuitOpen) {
            return false;
        }
        
        // Check if reset time has elapsed
        if (time() - $this->lastFailure > $this->circuitResetTime) {
            $this->circuitOpen = false;
            $this->failureCount = 0;
            return false;
        }
        
        return true;
    }
}

// Initialize the client
$client = new MessageBrokerClient($config['api_url'], $config['api_key'], [
    'timeout' => $config['timeout'],
    'retry_attempts' => $config['retry_attempts'],
    'retry_delay' => $config['retry_delay'],
]);

// Function to display results
function displayResult($operation, $result) {
    echo "\n=== {$operation} ===\n";
    echo "Status: " . $result['status'] . "\n";
    if ($result['status'] === 0) {
        echo "Error: " . ($result['body']['error'] ?? 'Unknown error') . "\n";
    } else {
        echo "Response: " . json_encode($result['body'], JSON_PRETTY_PRINT) . "\n";
    }
}

// Display menu
function displayMenu() {
    echo "\ngCore Message Broker Client\n";
    echo "==========================\n";
    echo "1. Check System Health\n";
    echo "2. Create Queue\n";
    echo "3. List Queues\n";
    echo "4. Delete Queue\n";
    echo "5. Publish Message\n";
    echo "6. Publish Multiple Messages\n";
    echo "7. Consume Message\n";
    echo "8. Consume Multiple Messages\n";
    echo "9. Run Production Demo (stress test)\n";
    echo "0. Exit\n";
    echo "\nEnter your choice: ";
    
    $choice = trim(fgets(STDIN));
    return $choice;
}

// Main loop
while (true) {
    $choice = displayMenu();
    
    switch ($choice) {
        case '1': // Health Check
            echo "Checking system health...\n";
            $result = $client->checkHealth();
            displayResult("Health Check", $result);
            break;
            
        case '2': // Create Queue
            echo "Queue name [{$config['queue']}]: ";
            $input = trim(fgets(STDIN));
            $queueName = empty($input) ? $config['queue'] : $input;
            
            echo "Max queue size [10000]: ";
            $input = trim(fgets(STDIN));
            $maxSize = empty($input) ? 10000 : (int)$input;
            
            echo "Message TTL in seconds [86400]: ";
            $input = trim(fgets(STDIN));
            $ttl = empty($input) ? 86400 : (int)$input;
            
            echo "Creating queue: {$queueName} with max size {$maxSize}, TTL {$ttl}\n";
            $result = $client->createQueue($queueName, [
                'max_size' => $maxSize,
                'ttl' => $ttl
            ]);
            displayResult("Create Queue", $result);
            break;
            
        case '3': // List Queues
            echo "Listing queues...\n";
            $result = $client->listQueues();
            displayResult("List Queues", $result);
            break;
            
        case '4': // Delete Queue
            echo "Queue name [{$config['queue']}]: ";
            $input = trim(fgets(STDIN));
            $queueName = empty($input) ? $config['queue'] : $input;
            
            echo "Deleting queue: {$queueName}\n";
            $result = $client->deleteQueue($queueName);
            displayResult("Delete Queue", $result);
            break;
            
        case '5': // Publish Message
            echo "Queue name [{$config['queue']}]: ";
            $input = trim(fgets(STDIN));
            $queueName = empty($input) ? $config['queue'] : $input;
            
            echo "Enter message to publish: ";
            $message = trim(fgets(STDIN));
            
            echo "Publishing message to {$queueName}...\n";
            $result = $client->publishMessage($queueName, $message);
            displayResult("Publish Message", $result);
            break;
            
        case '6': // Publish Multiple Messages
            echo "Queue name [{$config['queue']}]: ";
            $input = trim(fgets(STDIN));
            $queueName = empty($input) ? $config['queue'] : $input;
            
            echo "Number of messages to publish [5]: ";
            $input = trim(fgets(STDIN));
            $count = empty($input) ? 5 : (int)$input;
            
            echo "Publishing {$count} messages to {$queueName}...\n";
            $messages = [];
            
            for ($i = 1; $i <= $count; $i++) {
                $messages[] = [
                    'id' => $i,
                    'text' => "Batch message #{$i}",
                    'timestamp' => time()
                ];
            }
            
            $result = $client->publishBatch($queueName, $messages);
            displayResult("Publish Multiple Messages", $result);
            break;
            
        case '7': // Consume Message
            echo "Queue name [{$config['queue']}]: ";
            $input = trim(fgets(STDIN));
            $queueName = empty($input) ? $config['queue'] : $input;
            
            echo "Acknowledge message? (y/n) [y]: ";
            $input = trim(fgets(STDIN));
            $ack = empty($input) ? true : (strtolower($input) === 'y');
            
            echo "Consuming message from {$queueName} (acknowledge: " . ($ack ? 'yes' : 'no') . ")...\n";
            $result = $client->consumeMessage($queueName, $ack);
            displayResult("Consume Message", $result);
            break;
            
        case '8': // Consume Multiple Messages
            echo "Queue name [{$config['queue']}]: ";
            $input = trim(fgets(STDIN));
            $queueName = empty($input) ? $config['queue'] : $input;
            
            echo "Number of messages to consume [5]: ";
            $input = trim(fgets(STDIN));
            $count = empty($input) ? 5 : (int)$input;
            
            echo "Acknowledge messages? (y/n) [y]: ";
            $input = trim(fgets(STDIN));
            $ack = empty($input) ? true : (strtolower($input) === 'y');
            
            echo "Consuming {$count} messages from {$queueName} (acknowledge: " . ($ack ? 'yes' : 'no') . ")...\n";
            $result = $client->consumeBatch($queueName, $count, $ack);
            displayResult("Consume Multiple Messages", $result);
            break;
            
        case '9': // Run Production Demo
            echo "\nRunning Production Demo\n";
            echo "=====================\n";
            
            // Create a test queue with advanced configuration
            $demoQueue = "demo_" . time();
            echo "Creating demo queue: {$demoQueue}...\n";
            $client->createQueue($demoQueue, [
                'max_size' => 100000,
                'ttl' => 3600
            ]);
            
            // Track performance metrics
            $totalMessages = 1000;
            $batchSize = 100;
            $startTime = microtime(true);
            
            // Publish messages in batches
            echo "Publishing {$totalMessages} messages in batches of {$batchSize}...\n";
            $published = 0;
            
            for ($i = 0; $i < $totalMessages; $i += $batchSize) {
                $batch = [];
                $count = min($batchSize, $totalMessages - $i);
                
                for ($j = 0; $j < $count; $j++) {
                    $msgId = $i + $j + 1;
                    $batch[] = [
                        'id' => $msgId,
                        'text' => "Production test message #{$msgId}",
                        'timestamp' => time(),
                        'data' => str_repeat('x', 100) // Add some data for size
                    ];
                }
                
                $result = $client->publishBatch($demoQueue, $batch);
                
                if ($result['status'] === 200) {
                    $published += $result['body']['success'];
                    echo "Published batch " . (($i / $batchSize) + 1) . 
                         ": {$result['body']['success']}/{$count} messages\n";
                } else {
                    echo "Error publishing batch: " . json_encode($result) . "\n";
                }
            }
            
            $publishTime = microtime(true) - $startTime;
            echo "\nPublished {$published} messages in " . number_format($publishTime, 2) . " seconds\n";
            echo "Publishing rate: " . number_format($published / $publishTime, 2) . " msgs/sec\n";
            
            // Consume messages in batches
            echo "\nConsuming messages in batches of {$batchSize}...\n";
            $startTime = microtime(true);
            $consumed = 0;
            $consumeBatchCount = 0;
            
            while ($consumed < $published) {
                $consumeBatchCount++;
                $toConsume = min($batchSize, $published - $consumed);
                $result = $client->consumeBatch($demoQueue, $toConsume);
                
                if ($result['status'] === 200) {
                    $actualConsumed = $result['body']['count'];
                    $consumed += $actualConsumed;
                    echo "Consumed batch {$consumeBatchCount}: {$actualConsumed} messages (total: {$consumed})\n";
                    
                    // If we got fewer messages than requested, we're done
                    if ($actualConsumed < $toConsume) {
                        break;
                    }
                } else {
                    echo "Error consuming batch: " . json_encode($result) . "\n";
                    break;
                }
            }
            
            $consumeTime = microtime(true) - $startTime;
            echo "\nConsumed {$consumed} messages in " . number_format($consumeTime, 2) . " seconds\n";
            echo "Consumption rate: " . number_format($consumed / $consumeTime, 2) . " msgs/sec\n";
            
            // Clean up
            echo "\nCleaning up demo queue...\n";
            $client->deleteQueue($demoQueue);
            
            echo "\nProduction demo completed.\n";
            break;
            
        case '0': // Exit
            echo "Exiting...\n";
            exit(0);
            
        default:
            echo "Invalid choice. Please try again.\n";
    }
}
?>