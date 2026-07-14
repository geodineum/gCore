# gCore MessageBroker: Production-Ready Message Queue System

## Overview

The MessageBroker is a complete, production-ready message queue system built on gCore's framework. It demonstrates how to integrate all four core managers (CacheManager, ErrorManager, SecurityManager, and APIManager) to create a reliable distributed messaging solution.

## Key Features

- **Multiple Queue Support**: Create and manage multiple message queues
- **Persistent Storage**: Message durability using ValKey/Redis
- **Role-Based Access Control**: Fine-grained permissions for operations
- **RESTful API**: HTTP-based API with endpoints
- **Security**: API key authentication with strict validation
- **Rate Limiting**: Protection against excessive requests
- **High Performance**: Connection pooling and batch operations
- **Atomic Operations**: Script-based atomic queue operations
- **Resilience Patterns**: Circuit breaker and adaptive backoff
- **Multiple Backends**: Support for both list-based and stream-based queues

## Architecture

The MessageBroker uses gCore's modular architecture:

1. **CacheManager**: Provides persistent message storage and atomic operations
2. **ErrorManager**: Handles error tracking, logging, and notifications
3. **SecurityManager**: Manages authentication, authorization, and input validation
4. **APIManager**: Exposes RESTful API endpoints with middleware support

### Implementation Structure

```
examples/message_broker/
├── MessageBroker.php            # Core implementation
├── SecurityExtension.php        # API key extension
├── server.php                   # Full server implementation
├── client.php                   # Interactive client
├── config/
│   ├── message_broker.yaml      # Configuration
│   ├── users.yaml               # User definitions
```

## Getting Started

### Prerequisites

- gCore framework installed
- ValKey/Redis server running (for full mode)

### Installation

1. **Install ValKey/Redis**:
   ```bash
   docker run -d --name valkey -p 127.0.0.1:6379:6379 valkey/valkey
   ```

2. **Verify gCore Framework**:
   ```bash
   php test-all.php
   ```

3. **Configure the MessageBroker**:
   ```bash
   cp examples/message_broker/config/users.yaml.example examples/message_broker/config/users.yaml
   # Edit users.yaml to add your API keys
   ```

### Starting the Server

```bash
# Full mode with ValKey/Redis
php examples/message_broker/server.php

# Simplified mode (in-memory storage)
php examples/message_broker/server_simplified.php
```

### Using the Client

```bash
# Use the interactive client
API_KEY=your-api-key php examples/message_broker/client.php
```

## API Endpoints

### Queue Management

- **Create Queue**:
  - `POST /queues`
  - Parameters: `name` (string)
  - Requires: `admin` role

- **List Queues**:
  - `GET /queues`
  - Requires: Any valid API key

- **Delete Queue**:
  - `DELETE /queues/{queue}`
  - Requires: `admin` role

### Message Operations

- **Publish Message**:
  - `POST /messages/{queue}`
  - Body: JSON message data
  - Requires: `publisher` or `admin` role

- **Consume Message**:
  - `GET /messages/{queue}`
  - Parameters: `acknowledge` (boolean, default: true)
  - Requires: `consumer` or `admin` role

- **Acknowledge Message**:
  - `PUT /messages/{queue}/{messageId}`
  - Requires: `consumer` or `admin` role

## Security Model

The MessageBroker implements a security model:

### Role-Based Access Control

Three primary roles are supported:
- **admin**: Full access to all operations
- **publisher**: Can send messages to queues
- **consumer**: Can receive messages from queues

### API Key Authentication

All requests require an API key provided via the `X-API-Key` header.

```php
// In SecurityExtension.php
public function validateAPIKey(string $key): ?array
{
    $userData = $this->getUserByApiKey($key);
    
    if (!$userData) {
        return null;
    }
    
    return [
        'id' => $userData['id'],
        'roles' => $userData['roles'],
        'name' => $userData['name']
    ];
}
```

### Permission Checking

```php
// Check if user has required role
private function hasRole(array $user, string $role): bool
{
    return in_array($role, $user['roles']) || in_array('admin', $user['roles']);
}
```

## Implementation Details

### Message Storage

Messages are stored in ValKey/Redis with two mechanisms:

1. **List-Based Queues** (Default):
   - Uses Redis Lists for message queueing
   - Provides FIFO (First In, First Out) behavior
   - Uses LPUSH/RPOP for queue operations

2. **Stream-Based Queues**:
   - Uses Redis Streams for advanced message handling
   - Supports consumer groups and message acknowledgment
   - Better for high-volume, multi-consumer scenarios

### Atomic Operations

Queue operations are implemented using atomic Lua scripts:

```php
// Script-based atomic operations
private function enqueueMessage(string $queue, array $message): string
{
    // Prepare message with metadata
    $data = [
        'id' => $this->generateId(),
        'timestamp' => microtime(true),
        'data' => $message,
        'publisher' => $this->getCurrentUser()['id']
    ];
    
    // Use CacheManager's script system for atomic operation
    return $this->cacheManager->runScript(
        'QUEUE_PUSH',
        ["{$this->queuePrefix}:{$queue}"],
        [json_encode($data)]
    );
}
```

### Connection Pooling

The MessageBroker uses gCore's connection pooling for performance:

```php
// Connection pool is accessed through CacheManager
$pool = $this->cacheManager->getConnectionPool();

// Execute operations with connection from pool
$result = $pool->executeWithConnection(function($redis) use ($queue) {
    // Use connection for complex operations
    return $redis->hGetAll("{$this->queuePrefix}:{$queue}:meta");
});
```

### Resilience Patterns

Multiple resilience patterns are implemented:

1. **Circuit Breaker**:
   ```php
   if ($pool->isCircuitOpen('redis_connection')) {
       // Circuit is open, use fallback mechanism
       return $this->useFallbackStorage();
   }
   ```

2. **Adaptive Backoff**:
   ```php
   $result = $pool->executeWithRetry(
       function($redis) use ($queue, $message) {
           // Operation that might fail
           return $redis->lPush($queue, json_encode($message));
       },
       3,    // Max retries
       100   // Initial delay (ms)
   );
   ```

## Performance Considerations

1. **Batch Operations**:
   - Use batch operations for high-throughput scenarios
   - Uses CacheManager's batch operations

2. **Connection Pooling**:
   - Reuses connections to reduce overhead
   - Configured based on expected traffic

3. **Script-Based Operations**:
   - Uses ValKey/Redis Lua scripts for atomic operations
   - Reduces network roundtrips

4. **Caching Metadata**:
   - Caches queue metadata to reduce lookups
   - Implements efficient invalidation strategies

## Production Deployment

### Docker Deployment

```bash
# Build and run with Docker Compose
cd examples/message_broker
docker-compose up -d
```

### Monitoring

Monitor the MessageBroker with the built-in health endpoints:

```
GET /health
```

Sample output:
```json
{
  "status": "healthy",
  "queues": 5,
  "messages_total": 1250,
  "messages_processed": 1200,
  "uptime": 3600,
  "version": "1.0"
}
```

### Scaling

1. **Horizontal Scaling**:
   - Deploy multiple broker instances
   - Use shared ValKey/Redis cluster
   - Implement proper load balancing

2. **Queue Partitioning**:
   - Partition queues for higher throughput
   - Use consistent hashing for message distribution

## Using as a Template

The MessageBroker serves as an excellent template for building your own gCore applications:

1. **Manager Integration**: Shows how to properly integrate all core managers
2. **API Design**: Demonstrates RESTful API design with gCore
3. **Security Model**: Provides a complete security implementation
4. **Resilience Patterns**: Shows how to implement error handling

## Customization

### Adding Custom Queue Types

```php
// Add a new queue type implementation
public function registerQueueType(string $type, array $handlers): void
{
    $this->queueTypes[$type] = $handlers;
}

// Example: Register custom priority queue
$messageBroker->registerQueueType('priority', [
    'push' => [$priorityQueue, 'push'],
    'pop' => [$priorityQueue, 'pop'],
    'size' => [$priorityQueue, 'size'],
    'clear' => [$priorityQueue, 'clear']
]);
```

### Custom Message Processing

```php
// Register a message processor
public function registerProcessor(string $queue, callable $processor): void
{
    $this->processors[$queue] = $processor;
}

// Example: Register a processor for order queue
$messageBroker->registerProcessor('orders', function($message) {
    // Process order message
    processOrder($message['data']);
    return true; // Acknowledge message
});
```

## Conclusion

The gCore MessageBroker demonstrates the framework's capabilities for building production-ready applications. By leveraging the four core managers and gCore's architectural principles, it provides a reliable, high-performance message queue system with security and resilience features.

---

*Created: March 2025*