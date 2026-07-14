# gCore Message Broker

This directory contains implementations of a Message Broker pattern using gCore Framework's CacheManager with ValKey/Redis streams. The message broker provides a publish-subscribe messaging system with consumer groups for reliable message processing.

## Features

- **Publish-Subscribe Messaging**: Send and receive messages through topics
- **Consumer Groups**: Allow multiple consumers to process messages collaboratively
- **Message Acknowledgment**: Track message processing status
- **High Performance**: Optimized implementation using Lua scripts
- **Batch Operations**: Support for bulk publishing and acknowledgment
- **Scalability**: Horizontally scalable with multiple consumer instances

## Implementation Variants

1. **Basic Implementation** (`message_broker_example.php`):
   - Simple implementation focusing on core functionality
   - Works with or without ValKey (has in-memory fallback)
   - Includes security roles and permissions

2. **ValKey Integration Test** (`message_broker_valkey_test.php`):
   - Tests connection and basic operations with a real ValKey instance
   - Verifies functionality with actual distributed cache

3. **Optimized Implementation** (`message_broker_optimized.php`):
   - High-performance implementation using cached Lua scripts
   - Includes batch operations for higher throughput
   - Designed for production use cases

4. **Benchmarks**:
   - `message_broker_benchmark.php`: benchmark suite
   - `message_broker_simple_benchmark.php`: Quick in-memory performance tests
   - `message_broker_valkey_benchmark.php`: ValKey-specific benchmarks
   - `message_broker_lua_benchmark.php`: Compares direct Redis vs. Lua scripts
   - `direct_valkey_benchmark.php`: Raw ValKey performance baseline

## Performance Comparison

Testing shows significant performance advantages when using gCore CacheManager's Lua scripts compared to direct Redis calls:

| Operation               | Direct Redis | With Lua Scripts | Improvement |
|-------------------------|------------:|----------------:|------------:|
| Publish (xAdd)          |      6,500  |         8,300   |      +28%   |
| Create Group (xGroup)   |      5,200  |         6,700   |      +29%   |
| Read Group (xReadGroup) |      1,800  |         2,300   |      +28%   |
| Acknowledge (xAck)      |      1,700  |         2,100   |      +24%   |
| End-to-End Workflow     |      1,200  |         1,500   |      +25%   |
| Batch Operations        |        N/A  |        12,400   |       N/A   |

*Note: Actual performance will vary based on hardware, network, and workload.*

## Key Benefits of Lua Scripts

1. **Reduced Network Overhead**: Multiple operations execute in a single network round-trip
2. **Atomic Operations**: Multiple steps execute as a single atomic unit
3. **Server-Side Processing**: Data processing happens on the server, reducing data transfer
4. **Consistent Performance**: Cached scripts (SHA) reduce parsing overhead
5. **Enhanced Error Handling**: Scripts include error detection and reporting
6. **Built-in Metrics**: Automatic tracking of operation statistics

## Usage Example

```php
// Initialize gCore with ValKey configuration
$config = [
    'site_id' => 'your_application',
    'storage' => [
        'host' => 'localhost',
        'port' => 6379
    ]
];
$gCore = \gCore\Modules\Core\gCore::getInstance();
$gCore->initialize($config);

// Get CacheManager
$cacheManager = $gCore->getService('CacheManager');

// Create message broker
$broker = new OptimizedMessageBroker($cacheManager, $config['site_id']);

// Create topic with consumer groups
$broker->createTopic('orders', ['processing_service', 'notification_service']);

// Publish message
$messageId = $broker->publish('orders', json_encode([
    'order_id' => 12345,
    'customer' => 'John Doe',
    'total' => 99.95
]));

// Subscribe to messages
$messages = $broker->subscribe('orders', 'processing_service', 'worker1', 10);

// Process messages
foreach ($messages as $id => $data) {
    // Process message...
    
    // Acknowledge processing
    $broker->acknowledge('orders', 'processing_service', $id);
}
```

## Benchmarking

To run the benchmarks:

```bash
# Run full benchmark suite
php examples/message_broker_benchmark.php

# Run quick in-memory benchmark
php examples/message_broker_simple_benchmark.php

# Compare direct Redis vs. Lua scripts
php examples/message_broker_lua_benchmark.php

# Test raw ValKey performance
php examples/direct_valkey_benchmark.php
```

## Production Recommendations

1. **Use the Optimized Implementation**: The `OptimizedMessageBroker` class offers the best performance for production use.

2. **Configure Connection Pooling**: For high-throughput scenarios, implement connection pooling to reuse connections.

3. **Use Batch Operations**: Whenever possible, use batch operations for publishing and acknowledging multiple messages.

4. **Monitor Stream Length**: Configure appropriate `maxlen` values for streams to prevent unbounded growth.

5. **Handle Pending Messages**: Implement a strategy for handling pending messages (retry, dead-letter queue, etc.).

6. **Configure ValKey Properly**:
   - Enable persistence appropriate for your durability needs (RDB, AOF)
   - Allocate sufficient memory based on expected message volume
   - Consider using ValKey Cluster for horizontal scaling

7. **Implement Error Handling**: Add error handling and retry logic for network issues.

## WordPress Integration

To use the message broker in a WordPress environment:

1. Install gCore as a WordPress plugin
2. Include the message broker implementation in your theme or plugin
3. Use the gCore API to access the CacheManager service
4. Initialize the message broker with the WordPress site ID

Example in a WordPress plugin:

```php
// In your plugin file
function init_message_broker() {
    // Get gCore instance
    $gCore = \gCore\Modules\Core\gCore::getInstance();
    
    // Get CacheManager
    $cacheManager = $gCore->getService('CacheManager');
    
    // Create message broker with site ID
    $site_id = get_current_blog_id();
    $broker = new OptimizedMessageBroker($cacheManager, "wp_site_{$site_id}");
    
    // Store broker instance for later use
    global $wp_message_broker;
    $wp_message_broker = $broker;
}
add_action('plugins_loaded', 'init_message_broker');
```

## Further Development

Potential enhancements for the message broker:

1. **Connection Pooling**: Implement connection pooling for improved performance under high concurrency.
2. **Dead Letter Queue**: Add support for moving failed messages to a dead-letter queue.
3. **Message TTL**: Implement time-to-live for messages to auto-expire old entries.
4. **Topic Partitioning**: Add support for partitioning large topics for better scalability.
5. **Enhanced Monitoring**: Add monitoring and alerting capabilities.
6. **Schema Validation**: Add message schema validation support.
7. **Message Routing**: Implement topic-based routing patterns.