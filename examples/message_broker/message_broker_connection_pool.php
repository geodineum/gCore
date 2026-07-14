<?php
/**
 * gCore Framework - Message Broker Connection Pool
 * 
 * This class implements a connection pool for the message broker to improve
 * performance in high-concurrency scenarios. It manages multiple ValKey
 * connections and distributes operations across them.
 */

namespace gCore\Examples;

/**
 * Message Broker Connection Pool
 */
class MessageBrokerConnectionPool {
    private $connections = [];
    private $maxConnections;
    private $host;
    private $port;
    private $auth;
    private $connectTimeout;
    private $readTimeout;
    private $persistent;
    private $metrics = [
        'operations' => 0,
        'connections_created' => 0,
        'operations_per_connection' => [],
        'errors' => 0,
        'last_error' => null
    ];
    
    /**
     * Constructor
     * 
     * @param array $config Connection configuration
     */
    public function __construct(array $config = []) {
        $this->maxConnections = $config['max_connections'] ?? 5;
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 6379;
        $this->auth = $config['auth'] ?? '';
        $this->connectTimeout = $config['connect_timeout'] ?? 2.0;
        $this->readTimeout = $config['read_timeout'] ?? 2.0;
        $this->persistent = $config['persistent'] ?? false;
        
        // Initialize metrics tracking
        for ($i = 0; $i < $this->maxConnections; $i++) {
            $this->metrics['operations_per_connection'][$i] = 0;
        }
        
        // Preallocate connections if requested
        if (isset($config['preallocate']) && $config['preallocate']) {
            $this->preallocateConnections();
        }
    }
    
    /**
     * Preallocate all connections in the pool
     */
    public function preallocateConnections() {
        for ($i = 0; $i < $this->maxConnections; $i++) {
            $this->getConnection(true);
        }
    }
    
    /**
     * Get a connection from the pool
     * 
     * @param bool $forceNew Force creation of a new connection
     * @return \Redis|null Redis connection or null on failure
     */
    public function getConnection($forceNew = false) {
        try {
            // If we have available connections and not forcing new, use least used one
            if (!$forceNew && count($this->connections) > 0) {
                // Find connection with fewest operations
                $leastUsedIndex = $this->findLeastUsedConnectionIndex();
                
                // Test connection before returning
                if ($this->testConnection($this->connections[$leastUsedIndex])) {
                    return $this->connections[$leastUsedIndex];
                } else {
                    // Connection failed, remove it and try again
                    unset($this->connections[$leastUsedIndex]);
                    $this->connections = array_values($this->connections); // Reindex array
                    return $this->getConnection(true);
                }
            }
            
            // Check if we can create a new connection
            if (count($this->connections) < $this->maxConnections) {
                $redis = new \Redis();
                
                // Connect to ValKey server
                if ($this->persistent) {
                    $connected = $redis->pconnect(
                        $this->host,
                        $this->port,
                        $this->connectTimeout
                    );
                } else {
                    $connected = $redis->connect(
                        $this->host, 
                        $this->port,
                        $this->connectTimeout,
                        null,
                        0,
                        $this->readTimeout
                    );
                }
                
                if (!$connected) {
                    throw new \Exception("Failed to connect to ValKey at {$this->host}:{$this->port}");
                }
                
                // Authenticate if needed
                if (!empty($this->auth)) {
                    if (!$redis->auth($this->auth)) {
                        throw new \Exception("Authentication failed for ValKey at {$this->host}:{$this->port}");
                    }
                }
                
                // Set client name for easier debugging
                $redis->client('SETNAME', 'message_broker_pool_' . count($this->connections));
                
                // Add to pool
                $this->connections[] = $redis;
                $this->metrics['connections_created']++;
                
                return $redis;
            } else {
                // All connections in use, return least used one
                $leastUsedIndex = $this->findLeastUsedConnectionIndex();
                return $this->connections[$leastUsedIndex];
            }
        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->metrics['last_error'] = $e->getMessage();
            return null;
        }
    }
    
    /**
     * Find the index of the connection with the fewest operations
     * 
     * @return int Connection index
     */
    private function findLeastUsedConnectionIndex() {
        $leastUsed = 0;
        $minOperations = PHP_INT_MAX;
        
        for ($i = 0; $i < count($this->connections); $i++) {
            if ($this->metrics['operations_per_connection'][$i] < $minOperations) {
                $minOperations = $this->metrics['operations_per_connection'][$i];
                $leastUsed = $i;
            }
        }
        
        return $leastUsed;
    }
    
    /**
     * Test if a connection is still valid
     * 
     * @param \Redis $connection Connection to test
     * @return bool Connection is valid
     */
    private function testConnection($connection) {
        try {
            return $connection->ping() !== false;
        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->metrics['last_error'] = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Execute a command on a connection from the pool
     * 
     * @param string $method Redis method to call
     * @param array $params Parameters for the method
     * @return mixed Command result
     * @throws \Exception If command execution fails
     */
    public function executeCommand($method, $params = []) {
        $connection = $this->getConnection();
        
        if (!$connection) {
            throw new \Exception("Could not get a valid connection from the pool");
        }
        
        // Find the connection index for metrics
        $connectionIndex = array_search($connection, $this->connections, true);
        
        try {
            $result = call_user_func_array([$connection, $method], $params);
            
            // Update metrics
            $this->metrics['operations']++;
            if ($connectionIndex !== false) {
                $this->metrics['operations_per_connection'][$connectionIndex]++;
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->metrics['last_error'] = $e->getMessage();
            throw $e;
        }
    }
    
    /**
     * Execute a Lua script directly on a connection from the pool
     * 
     * @param string $script Lua script to execute
     * @param array $keys Keys for the script
     * @param array $args Arguments for the script
     * @return mixed Script result
     * @throws \Exception If script execution fails
     */
    public function executeScript($script, $keys = [], $args = []) {
        $connection = $this->getConnection();
        
        if (!$connection) {
            throw new \Exception("Could not get a valid connection from the pool");
        }
        
        // Find the connection index for metrics
        $connectionIndex = array_search($connection, $this->connections, true);
        
        try {
            $result = $connection->eval($script, array_merge($keys, $args), count($keys));
            
            // Update metrics
            $this->metrics['operations']++;
            if ($connectionIndex !== false) {
                $this->metrics['operations_per_connection'][$connectionIndex]++;
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->metrics['last_error'] = $e->getMessage();
            throw $e;
        }
    }
    
    /**
     * Execute a script using its SHA1 hash, with automatic fallback to EVAL
     * 
     * @param string $sha Script SHA1 hash
     * @param string $script Original script (for fallback)
     * @param array $keys Keys for the script
     * @param array $args Arguments for the script
     * @return mixed Script result
     * @throws \Exception If script execution fails
     */
    public function executeScriptBySha($sha, $script, $keys = [], $args = []) {
        $connection = $this->getConnection();
        
        if (!$connection) {
            throw new \Exception("Could not get a valid connection from the pool");
        }
        
        // Find the connection index for metrics
        $connectionIndex = array_search($connection, $this->connections, true);
        
        try {
            try {
                // Try to execute by SHA1 first
                $result = $connection->evalSha($sha, array_merge($keys, $args), count($keys));
            } catch (\Exception $e) {
                // If NOSCRIPT error, load the script and retry
                if (strpos($e->getMessage(), 'NOSCRIPT') !== false) {
                    // Load the script
                    $sha = $connection->script('LOAD', $script);
                    // Retry with the newly loaded script
                    $result = $connection->evalSha($sha, array_merge($keys, $args), count($keys));
                } else {
                    throw $e; // Re-throw if not a NOSCRIPT error
                }
            }
            
            // Update metrics
            $this->metrics['operations']++;
            if ($connectionIndex !== false) {
                $this->metrics['operations_per_connection'][$connectionIndex]++;
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->metrics['last_error'] = $e->getMessage();
            throw $e;
        }
    }
    
    /**
     * Load a Lua script on all connections in the pool
     * 
     * @param string $script Lua script to load
     * @return string SHA1 hash of the script
     * @throws \Exception If script loading fails
     */
    public function loadScript($script) {
        $sha = null;
        
        foreach ($this->connections as $connection) {
            try {
                $result = $connection->script('LOAD', $script);
                if (!$sha) {
                    $sha = $result; // Store first result
                } elseif ($sha !== $result) {
                    throw new \Exception("Inconsistent SHA1 hashes across connections");
                }
            } catch (\Exception $e) {
                $this->metrics['errors']++;
                $this->metrics['last_error'] = $e->getMessage();
                throw $e;
            }
        }
        
        // If no connections, create one and load the script
        if (!$sha) {
            $connection = $this->getConnection(true);
            if (!$connection) {
                throw new \Exception("Could not create connection to load script");
            }
            
            try {
                $sha = $connection->script('LOAD', $script);
            } catch (\Exception $e) {
                $this->metrics['errors']++;
                $this->metrics['last_error'] = $e->getMessage();
                throw $e;
            }
        }
        
        return $sha;
    }
    
    /**
     * Execute a pipeline of commands
     * 
     * @param callable $callback Function that receives a pipeline object to add commands
     * @return array Pipeline results
     * @throws \Exception If pipeline execution fails
     */
    public function pipeline(callable $callback) {
        $connection = $this->getConnection();
        
        if (!$connection) {
            throw new \Exception("Could not get a valid connection from the pool");
        }
        
        // Find the connection index for metrics
        $connectionIndex = array_search($connection, $this->connections, true);
        
        try {
            $pipeline = $connection->pipeline();
            $callback($pipeline);
            $results = $pipeline->exec();
            
            // Update metrics
            $this->metrics['operations']++;
            if ($connectionIndex !== false) {
                $this->metrics['operations_per_connection'][$connectionIndex]++;
            }
            
            return $results;
        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->metrics['last_error'] = $e->getMessage();
            throw $e;
        }
    }
    
    /**
     * Get connection pool metrics
     * 
     * @return array Metrics
     */
    public function getMetrics() {
        $metrics = $this->metrics;
        $metrics['active_connections'] = count($this->connections);
        $metrics['max_connections'] = $this->maxConnections;
        
        // Calculate average operations per connection
        if (count($this->connections) > 0) {
            $metrics['avg_operations_per_connection'] = $this->metrics['operations'] / count($this->connections);
        } else {
            $metrics['avg_operations_per_connection'] = 0;
        }
        
        return $metrics;
    }
    
    /**
     * Close all connections in the pool
     */
    public function close() {
        foreach ($this->connections as $connection) {
            try {
                $connection->close();
            } catch (\Exception $e) {
                // Ignore errors during close
            }
        }
        
        $this->connections = [];
    }
}

/**
 * Message Broker with Connection Pooling
 */
class PooledMessageBroker {
    private $connectionPool;
    private $topics = [];
    private $siteId;
    private $scripts = [];
    
    /**
     * Constructor
     * 
     * @param array $config Connection configuration
     * @param string $siteId Site identifier for namespacing
     */
    public function __construct(array $config, string $siteId) {
        $this->connectionPool = new MessageBrokerConnectionPool($config);
        $this->siteId = $siteId;
        
        // Load and cache Lua scripts
        $this->loadScripts();
    }
    
    /**
     * Load and cache Lua scripts
     */
    private function loadScripts() {
        // Stream add script
        $this->scripts['streamAdd'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            local message = ARGV[1]
            local maxLen = tonumber(ARGV[2]) or 1000
            
            -- Add message to stream with approximate trimming
            local result = redis.call('XADD', streamKey, 'MAXLEN', '~', maxLen, '*', 'data', message)
            
            return result
            LUA,
            'sha' => null
        ];
        
        // Stream create group script
        $this->scripts['streamCreateGroup'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            local groupName = ARGV[1]
            local startId = ARGV[2] or '0'
            
            -- Check if stream exists, create if not
            if redis.call('EXISTS', streamKey) == 0 then
                redis.call('XADD', streamKey, '*', 'init', 'true')
            end
            
            -- Try to create the consumer group
            local ok, err = pcall(function()
                return redis.call('XGROUP', 'CREATE', streamKey, groupName, startId, 'MKSTREAM')
            end)
            
            -- Return success or failure reason
            if ok then
                return 1
            else
                -- Check if it's already exists error (ignore that)
                if string.find(err, "Consumer Group name already exists") then
                    return 1
                else
                    return {err=err}
                end
            end
            LUA,
            'sha' => null
        ];
        
        // Stream read group script
        $this->scripts['streamReadGroup'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            local groupName = ARGV[1]
            local consumerName = ARGV[2]
            local count = tonumber(ARGV[3]) or 1
            local blockMs = tonumber(ARGV[4]) or 0
            
            -- Read messages from the stream
            local messages = redis.call('XREADGROUP', 'GROUP', groupName, consumerName, 
                                        'COUNT', count, 'BLOCK', blockMs, 'STREAMS', streamKey, '>')
            
            -- Format the results
            local result = {}
            if messages and #messages > 0 and #messages[1][2] > 0 then
                for i, msg in ipairs(messages[1][2]) do
                    local id = msg[1]
                    local values = {}
                    
                    -- Convert array of alternating keys/values to a map
                    for j = 1, #msg[2], 2 do
                        values[msg[2][j]] = msg[2][j+1]
                    end
                    
                    result[id] = values
                end
            end
            
            return cjson.encode(result)
            LUA,
            'sha' => null
        ];
        
        // Stream acknowledge script
        $this->scripts['streamAck'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            local groupName = ARGV[1]
            local messageId = ARGV[2]
            
            -- Acknowledge the message
            local result = redis.call('XACK', streamKey, groupName, messageId)
            
            return result
            LUA,
            'sha' => null
        ];
        
        // Batch stream add script
        $this->scripts['batchStreamAdd'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            local messages = cjson.decode(ARGV[1])
            local maxLen = tonumber(ARGV[2]) or 1000
            
            local result = {}
            
            -- Add each message to the stream
            for i, message in ipairs(messages) do
                local id = redis.call('XADD', streamKey, 'MAXLEN', '~', maxLen, '*', 'data', message)
                result[i] = id
            end
            
            return cjson.encode(result)
            LUA,
            'sha' => null
        ];
        
        // Batch stream acknowledge script
        $this->scripts['batchStreamAck'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            local groupName = ARGV[1]
            local messageIds = cjson.decode(ARGV[2])
            
            local result = {}
            local totalAcked = 0
            
            -- Acknowledge each message
            for i, messageId in ipairs(messageIds) do
                local acked = redis.call('XACK', streamKey, groupName, messageId)
                result[messageId] = acked
                totalAcked = totalAcked + acked
            end
            
            return totalAcked
            LUA,
            'sha' => null
        ];
        
        // Stream length script
        $this->scripts['streamLength'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            
            -- Get the stream length
            local len = redis.call('XLEN', streamKey)
            
            return len
            LUA,
            'sha' => null
        ];
        
        // Stream pending messages script
        $this->scripts['streamPending'] = [
            'script' => <<<'LUA'
            local streamKey = KEYS[1]
            local groupName = ARGV[1]
            
            -- Get the pending messages summary
            local pendingSummary = redis.call('XPENDING', streamKey, groupName)
            
            -- If no pending messages, return empty result
            if not pendingSummary or pendingSummary[1] == 0 then
                return '{}'
            end
            
            -- Get detailed information about pending messages
            local pendingDetails = redis.call('XPENDING', streamKey, groupName, '-', '+', 10)
            
            -- Format the results
            local result = {}
            for i, item in ipairs(pendingDetails) do
                local messageId = item[1]
                local consumerName = item[2]
                local idleTimeMs = item[3]
                local deliveryCount = item[4]
                
                result[messageId] = {
                    consumer = consumerName,
                    idle_time = idleTimeMs,
                    delivered = deliveryCount
                }
            end
            
            return cjson.encode(result)
            LUA,
            'sha' => null
        ];
        
        // Load all scripts on the connection pool
        foreach ($this->scripts as $name => &$script) {
            $script['sha'] = $this->connectionPool->loadScript($script['script']);
        }
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
            
            // Create consumer groups using script
            foreach ($groups as $group) {
                $streamKey = "{$this->siteId}:stream:{$topic}";
                
                $result = $this->connectionPool->executeScriptBySha(
                    $this->scripts['streamCreateGroup']['sha'],
                    $this->scripts['streamCreateGroup']['script'],
                    [$streamKey],
                    [$group, '0']
                );
                
                if ($result) {
                    $this->topics[$topic]['groups'][] = $group;
                }
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Publish a message to a topic
     * 
     * @param string $topic Topic name
     * @param mixed $message Message content
     * @param int $maxLen Maximum stream length
     * @return string Message ID
     */
    public function publish($topic, $message, $maxLen = 10000) {
        if (!isset($this->topics[$topic])) {
            $this->createTopic($topic, ['default']);
        }
        
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Use Lua script to add the message
        $messageId = $this->connectionPool->executeScriptBySha(
            $this->scripts['streamAdd']['sha'],
            $this->scripts['streamAdd']['script'],
            [$streamKey],
            [$message, $maxLen]
        );
        
        return $messageId;
    }
    
    /**
     * Batch publish multiple messages to a topic
     * 
     * @param string $topic Topic name
     * @param array $messages Array of messages
     * @param int $maxLen Maximum stream length
     * @return array Message IDs
     */
    public function batchPublish($topic, $messages, $maxLen = 10000) {
        if (!isset($this->topics[$topic])) {
            $this->createTopic($topic, ['default']);
        }
        
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Use Lua script to add multiple messages in one call
        $result = $this->connectionPool->executeScriptBySha(
            $this->scripts['batchStreamAdd']['sha'],
            $this->scripts['batchStreamAdd']['script'],
            [$streamKey],
            [json_encode($messages), $maxLen]
        );
        
        return json_decode($result, true);
    }
    
    /**
     * Subscribe to messages from a topic
     * 
     * @param string $topic Topic name
     * @param string $group Consumer group name
     * @param string $consumer Consumer name
     * @param int $count Maximum number of messages to retrieve
     * @param int $blockMs Time to block in milliseconds (0 = no blocking)
     * @return array Messages
     */
    public function subscribe($topic, $group, $consumer, $count = 1, $blockMs = 0) {
        if (!isset($this->topics[$topic]) || !in_array($group, $this->topics[$topic]['groups'])) {
            $this->createTopic($topic, [$group]);
        }
        
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Use Lua script to read from the group
        $result = $this->connectionPool->executeScriptBySha(
            $this->scripts['streamReadGroup']['sha'],
            $this->scripts['streamReadGroup']['script'],
            [$streamKey],
            [$group, $consumer, $count, $blockMs]
        );
        
        return json_decode($result, true) ?: [];
    }
    
    /**
     * Acknowledge a message
     * 
     * @param string $topic Topic name
     * @param string $group Consumer group name
     * @param string $messageId Message ID to acknowledge
     * @return boolean Success status
     */
    public function acknowledge($topic, $group, $messageId) {
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Use Lua script to acknowledge the message
        $result = $this->connectionPool->executeScriptBySha(
            $this->scripts['streamAck']['sha'],
            $this->scripts['streamAck']['script'],
            [$streamKey],
            [$group, $messageId]
        );
        
        return $result > 0;
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
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Use Lua script to acknowledge multiple messages at once
        $result = $this->connectionPool->executeScriptBySha(
            $this->scripts['batchStreamAck']['sha'],
            $this->scripts['batchStreamAck']['script'],
            [$streamKey],
            [$group, json_encode($messageIds)]
        );
        
        return $result;
    }
    
    /**
     * Get the length of a stream/topic
     * 
     * @param string $topic Topic name
     * @return int Number of messages in the stream
     */
    public function getTopicLength($topic) {
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Use Lua script to get the stream length
        $result = $this->connectionPool->executeScriptBySha(
            $this->scripts['streamLength']['sha'],
            $this->scripts['streamLength']['script'],
            [$streamKey],
            []
        );
        
        return $result;
    }
    
    /**
     * Get information about a topic
     * 
     * @param string $topic Topic name
     * @return array Topic information
     */
    public function getTopicInfo($topic) {
        if (!isset($this->topics[$topic])) {
            return null;
        }
        
        $length = $this->getTopicLength($topic);
        
        // Build the result
        $stats = [
            'name' => $topic,
            'created' => $this->topics[$topic]['created'],
            'groups' => $this->topics[$topic]['groups'],
            'message_count' => $length,
            'age' => time() - $this->topics[$topic]['created'] . ' seconds'
        ];
        
        return $stats;
    }
    
    /**
     * Get pending messages for a consumer group
     * 
     * @param string $topic Topic name
     * @param string $group Consumer group name
     * @return array Pending message information
     */
    public function getPendingMessages($topic, $group) {
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Use Lua script to get pending messages
        $result = $this->connectionPool->executeScriptBySha(
            $this->scripts['streamPending']['sha'],
            $this->scripts['streamPending']['script'],
            [$streamKey],
            [$group]
        );
        
        return json_decode($result, true) ?: [];
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
        
        $streamKey = "{$this->siteId}:stream:{$topic}";
        
        // Delete the stream
        $result = $this->connectionPool->executeCommand('del', [$streamKey]);
        
        if ($result) {
            unset($this->topics[$topic]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get metrics about the connection pool
     * 
     * @return array Metrics
     */
    public function getMetrics() {
        return [
            'connection_pool' => $this->connectionPool->getMetrics(),
            'topics' => count($this->topics),
            'scripts' => array_map(function($script) {
                return [
                    'sha' => $script['sha']
                ];
            }, $this->scripts)
        ];
    }
    
    /**
     * Close all connections
     */
    public function close() {
        $this->connectionPool->close();
    }
}

// Example usage
if (!empty($argv[0]) && basename($argv[0]) === basename(__FILE__)) {
    // This is being executed directly, run the example
    echo "gCore Message Broker Connection Pool Example\n";
    echo "==========================================\n\n";
    
    // Connection settings
    $host = getenv('VALKEY_HOST') ?: 'localhost';
    $port = (int)(getenv('VALKEY_PORT') ?: 6379);
    $auth = getenv('VALKEY_AUTH') ?: '';
    
    echo "Connection settings:\n";
    echo "- Host: $host\n";
    echo "- Port: $port\n";
    echo "- Auth: " . (!empty($auth) ? "Provided" : "None") . "\n\n";
    
    try {
        // Create connection pool configuration
        $config = [
            'host' => $host,
            'port' => $port,
            'auth' => $auth,
            'max_connections' => 5,
            'preallocate' => true
        ];
        
        // Create broker with connection pool
        $broker = new PooledMessageBroker($config, 'connection_pool_test');
        
        echo "Connection pool created successfully\n\n";
        
        // Create topics and groups
        echo "Creating topics...\n";
        $broker->createTopic('pooled_topic', ['consumer_group_1', 'consumer_group_2']);
        
        // Publish a message
        echo "Publishing messages...\n";
        $msg1 = $broker->publish('pooled_topic', json_encode([
            'id' => 1,
            'text' => 'Test message'
        ]));
        echo "- Published message with ID: $msg1\n";
        
        // Batch publish
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $messages[] = json_encode([
                'id' => $i + 1,
                'text' => "Batch message #$i"
            ]);
        }
        
        $batchIds = $broker->batchPublish('pooled_topic', $messages);
        echo "- Batch published " . count($batchIds) . " messages\n";
        
        // Subscribe to messages
        echo "\nSubscribing to messages...\n";
        $receivedMessages = $broker->subscribe('pooled_topic', 'consumer_group_1', 'consumer1', 3);
        
        echo "Received " . count($receivedMessages) . " messages:\n";
        foreach ($receivedMessages as $id => $data) {
            $messageData = json_decode($data['data'], true);
            echo "- [$id] Message {$messageData['id']}: {$messageData['text']}\n";
            
            // Acknowledge the message
            $broker->acknowledge('pooled_topic', 'consumer_group_1', $id);
        }
        
        // Get topic info
        $info = $broker->getTopicInfo('pooled_topic');
        echo "\nTopic information:\n";
        echo "- Name: {$info['name']}\n";
        echo "- Message count: {$info['message_count']}\n";
        echo "- Consumer groups: " . implode(', ', $info['groups']) . "\n";
        
        // Get pool metrics
        $metrics = $broker->getMetrics();
        echo "\nConnection pool metrics:\n";
        echo "- Active connections: {$metrics['connection_pool']['active_connections']}\n";
        echo "- Total operations: {$metrics['connection_pool']['operations']}\n";
        echo "- Topics managed: {$metrics['topics']}\n";
        
        // Clean up
        echo "\nCleaning up...\n";
        $broker->deleteTopic('pooled_topic');
        $broker->close();
        
        echo "Example completed successfully\n";
        
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}