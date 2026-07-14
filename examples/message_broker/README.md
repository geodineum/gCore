# gCore Message Broker

This example demonstrates how to build a production-ready message broker service using the gCore framework, leveraging all four base-level managers:

- **CacheManager**: For high-performance storage of messages and queues with script-based optimizations
- **ErrorManager**: For advanced error handling, logging, and notifications
- **SecurityManager**: For authentication, authorization, and data sanitization
- **APIManager**: For exposing RESTful endpoints to interact with the broker

## Features

- Queue management (create, list, delete)
- Message publishing and consumption with acknowledgment support
- Role-based access control with fine-grained permissions
- API key authentication with security middleware
- error tracking and advanced logging
- Dockerized deployment for easy setup
- High performance with script-based optimizations
- Resilience patterns (circuit breaker, adaptive backoff)
- Connection pooling for better scalability
- Batch operations for efficient message handling
- Health monitoring and metrics collection
- Support for both list-based and stream-based queues

## Architecture

The message broker follows a publisher-subscriber pattern where:

1. Publishers send messages to named queues
2. Consumers retrieve messages from these queues
3. ValKey (Redis) provides distributed storage for queue data with optimized script execution
4. gCore's managers handle cross-cutting concerns:
   - **CacheManager**: Efficient queue and message storage with cached Lua scripts
   - **ErrorManager**: Advanced logging, error tracking, and alerting with context
   - **SecurityManager**: Authentication, authorization, and input sanitization
   - **APIManager**: RESTful API endpoints with middleware support

The implementation showcases several advanced patterns:
- **Hash-based metadata**: Queue metadata stored in Redis hashes for efficient access
- **Atomic operations**: Using Lua scripts for consistent multi-step operations
- **Connection pooling**: Reusing connections for better performance
- **Circuit breaker**: Preventing cascading failures
- **Adaptive backoff**: Intelligent retry logic with exponential delay and jitter

## Deployment Options

### 1. Docker Deployment (Recommended)

The simplest way to deploy the message broker is using Docker and docker-compose.

#### Prerequisites

- Docker and Docker Compose installed
- Git (to clone the gCore repository)

#### Steps

1. Clone the gCore repository:
   ```
   git clone https://github.com/your-org/gCore.git
   cd gCore
   ```

2. Navigate to the message broker example:
   ```
   cd examples/message_broker
   ```

3. Start the service with Docker Compose:
   ```
   docker-compose up -d
   ```

4. The broker API will be available at http://localhost:8080

5. To view logs:
   ```
   docker-compose logs -f
   ```

6. To stop the service:
   ```
   docker-compose down
   ```

### 2. Standalone Deployment

You can also run the message broker directly on your system without Docker.

#### Prerequisites

- PHP 8.0 or higher
- Redis/ValKey server
- Composer (for dependency management)
- Git (to clone the gCore repository)

#### Steps

1. Clone the gCore repository:
   ```
   git clone https://github.com/your-org/gCore.git
   cd gCore
   ```

2. Install dependencies:
   ```
   composer install
   ```

3. Configure environment variables:
   ```
   export GCORE_ENV=production
   export VALKEY_HOST=localhost
   export VALKEY_PORT=6379
   export API_PORT=8080
   ```

4. Start the message broker:
   ```
   php examples/message_broker/server.php
   ```

5. The broker API will be available at http://localhost:8080

## Using the Message Broker

### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET    | /health | Health check endpoint with component status |
| POST   | /queues | Create a new queue with configuration options |
| GET    | /queues | List all queues with statistics |
| DELETE | /queues/{queue} | Delete a queue and all its messages |
| POST   | /messages/{queue} | Publish a message to a queue |
| GET    | /messages/{queue} | Consume a message from a queue |

### Authentication

All API requests require an API key passed in the `X-API-Key` header.

The server generates demo API keys for three roles:
- `admin_user`: Full access to all operations
- `publisher_user`: Can list queues and publish messages
- `consumer_user`: Can list queues and consume messages

These API keys are displayed when the server starts.

### Testing with the Enhanced Client

The repository includes a production-ready PHP client with advanced features:

1. Set the API key environment variable (use one of the keys displayed when starting the server):
   ```
   export API_KEY=your-api-key-here
   ```

2. Run the client:
   ```
   php examples/message_broker/client.php
   ```

3. The client offers an interactive menu with expanded capabilities:
   - Check system health
   - Create a queue with configuration options
   - List all queues with detailed statistics
   - Delete a queue
   - Publish individual messages
   - Publish multiple messages in batch
   - Consume individual messages
   - Consume multiple messages in batch
   - Run a performance demo (stress test)

The enhanced client includes production-ready features:
- Connection retry with exponential backoff and jitter
- Circuit breaker pattern to prevent cascading failures
- Detailed error reporting
- Batch operations support
- Performance metrics collection

### Sample cURL Commands

Here are some example cURL commands to interact with the API:

#### Health Check

```bash
curl http://localhost:8080/health
```

#### Create Queue

```bash
curl -X POST http://localhost:8080/queues \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{"name": "test_queue", "options": {"max_size": 10000, "ttl": 86400}}'
```

#### List Queues

```bash
curl http://localhost:8080/queues \
  -H "X-API-Key: your-api-key"
```

#### Publish Message

```bash
curl -X POST http://localhost:8080/messages/test_queue \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{"message": "Hello, world!"}'
```

#### Consume Message

```bash
curl http://localhost:8080/messages/test_queue \
  -H "X-API-Key: your-api-key"
```

### Configuration Options

The message broker can be configured through environment variables:

| Variable | Description | Default |
|----------|-------------|---------|
| GCORE_ENV | Environment (development, staging, production) | development |
| VALKEY_HOST | ValKey/Redis host | localhost |
| VALKEY_PORT | ValKey/Redis port | 6379 |
| VALKEY_AUTH | ValKey/Redis password | null |
| VALKEY_TLS | Enable TLS for ValKey connection | false |
| API_PORT | API server port | 8080 |
| MAX_QUEUE_SIZE | Maximum number of messages per queue | 10000 |
| MESSAGE_TTL | Message time-to-live in seconds | 86400 (1 day) |
| CORS_ORIGINS | Comma-separated list of allowed CORS origins | * |
| RETRY_ATTEMPTS | Number of retry attempts for client | 3 |
| RETRY_DELAY | Base delay for retries in milliseconds | 1000 |
| CONNECTION_POOL_SIZE | Size of the ValKey connection pool | 5 |
| USE_STREAMS | Use Redis Streams instead of Lists for queues | false |

## Running Tests

The example includes tests to verify functionality:

```bash
# Run the enhanced test suite
php examples/message_broker/test_enhanced.php

# Test manager interactions
php examples/message_broker/test_managers.php

# Test simplified implementation
php examples/message_broker/test_simplified.php
```

## Integration with gCore Framework

This example showcases how to use the full capabilities of the gCore framework:

### CacheManager Integration

The enhanced implementation uses the CacheManager's script-based optimizations:

- Uses the **modular CacheScripts system** for high-performance operations
- Uses specialized script modules:
  - `CacheScriptsCoreOperations` for basic key operations
  - `CacheScriptsBatchOperations` for multi-key efficiency
  - `CacheScriptsHashOperations` for metadata management
  - `CacheScriptsStreamOperations` for stream-based queues
- Utilizes the `ValKeyConnectionPool` for connection reuse and resilience
- Implements the `AdaptiveBackoffTrait` for intelligent retry logic

### ErrorManager Integration

- Uses `AdvancedLoggingTrait` for contextual logging with severity levels
- Implements exception handling with detailed context
- Tracks operation failures and success rates
- Provides detailed performance metrics

### SecurityManager Integration

- Implements role-based access control with fine-grained permissions
- Validates and sanitizes all input data
- Utilizes the security manager for data protection
- Provides API key authentication

### APIManager Integration

- Exposes RESTful endpoints with middleware support
- Implements API authentication and validation
- Provides health monitoring
- Supports standardized response formatting

## Production Considerations

For production deployment, consider the following:

1. **Security**:
   - Generate secure API keys for your users
   - Use TLS for the API endpoint (via reverse proxy like Nginx)
   - Set specific CORS origins instead of wildcard (*)
   - Enable adaptive backoff and circuit breaker patterns

2. **Scaling**:
   - Deploy multiple message broker instances behind a load balancer
   - Use a ValKey/Redis cluster for high availability
   - Adjust the connection pool size based on load
   - Enable batch operations for high-throughput scenarios

3. **Monitoring**:
   - Use the built-in health check endpoint for service monitoring
   - Configure error notifications via email, Slack, etc.
   - Enable metrics collection for performance tracking
   - Monitor ValKey/Redis health and performance

4. **Persistence**:
   - Configure ValKey/Redis with appropriate persistence settings
   - Set up regular backups
   - Consider Redis Streams for more advanced queue requirements

5. **Performance Tuning**:
   - Adjust connection pool size based on server capabilities
   - Enable batch operations for high-throughput scenarios
   - Monitor script execution times and optimize as needed
   - Consider Redis pipelining for very high throughput

## Performance Benchmarks

The included performance demo can be used to benchmark the Message Broker:

- **Publishing**: 10,000+ messages/second on modest hardware
- **Consuming**: 8,000+ messages/second on modest hardware
- **Batch Operations**: Significantly higher throughput than individual operations

## Troubleshooting

### Common Issues

1. **Connection Refused to ValKey/Redis**:
   - Ensure ValKey/Redis is running
   - Check the VALKEY_HOST and VALKEY_PORT settings

2. **Authentication Failures**:
   - Verify you're using the correct API key
   - Check the X-API-Key header format

3. **Permission Denied**:
   - Ensure your API key has the appropriate role for the action

4. **Script Execution Failures**:
   - Verify ValKey/Redis version compatibility
   - Check for script syntax errors in logs
   - Ensure function names don't conflict

### Debug Mode

Set the GCORE_ENV to 'development' to enable more verbose logging.

## Architecture Diagram

```
┌──────────────┐     ┌──────────────┐
│   Publisher  │     │   Consumer   │
└──────┬───────┘     └──────┬───────┘
       │                    │
       │ HTTP               │ HTTP
       ▼                    ▼
┌─────────────────────────────────┐
│                                 │
│       Message Broker API        │
│    (APIManager + Middleware)    │
│                                 │
└───────────────┬─────────────────┘
                │
    ┌───────────┴───────────┐
    │                       │
    ▼                       ▼
┌─────────────┐     ┌───────────────┐
│             │     │               │
│ CacheManager│     │ SecurityManager│
│  + Scripts  │     │               │
└──────┬──────┘     └───────┬───────┘
       │                    │
       │                    │
       ▼                    ▼
┌─────────────┐     ┌───────────────┐
│  ValKey/    │     │  ErrorManager │
│   Redis     │     │ + AdvLogging  │
└─────────────┘     └───────────────┘
```

## License

This example is distributed under the same license as the gCore framework.