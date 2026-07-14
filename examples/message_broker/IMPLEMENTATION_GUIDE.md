# gCore Message Broker Implementation Guide

This guide provides a overview of the gCore Message Broker example, which was created to validate the production readiness of the gCore framework.

## Overview

The Message Broker is a complete application that demonstrates the integration of all four base-level managers of the gCore framework:

- **CacheManager**: For persistent queue and message storage
- **ErrorManager**: For logging, error tracking, and notifications
- **SecurityManager**: For authentication, authorization, and input validation
- **APIManager**: For exposing RESTful endpoints with middleware support

## Files and Structure

The example is organized as follows:

```
examples/message_broker/
├── MessageBroker.php            # Core implementation
├── SecurityExtension.php        # API key extension for SecurityManager
├── server.php                   # Full server implementation
├── client.php                   # Interactive client
├── server_simplified.php        # Simplified server (no external dependencies)
├── test_simplified.php          # Manager tests with mock implementations
├── test_managers.php            # Test script for all four managers
├── Dockerfile                   # Production Docker configuration
├── docker-compose.yml           # Multi-container setup with ValKey
├── supervisord.conf             # Process management
├── config/
│   ├── message_broker.yaml      # Core configuration
│   ├── users.yaml               # User and API key configuration
│   └── schemas/
│       └── message_broker.yaml  # JSON Schema for validation
├── README.md                    # documentation
├── GETTING_STARTED.md           # Quick start guide
├── QUICKSTART.md                # Minimal instructions
├── SIMPLIFIED_README.md         # Guide for simplified mode
└── IMPLEMENTATION_GUIDE.md      # This detailed implementation guide
```

## Implementation Details

### Core Message Broker

The `MessageBroker.php` class serves as the core implementation, with the following features:

1. **Queue Management**:
   - Create: `createQueue($request, $response)`
   - List: `listQueues($request, $response)`
   - Delete: `deleteQueue($request, $response, $args)`

2. **Message Operations**:
   - Publish: `publishMessage($request, $response, $args)`
   - Consume: `consumeMessage($request, $response, $args)`

3. **Integration with Managers**:
   - Uses CacheManager for persistent storage of queues and messages
   - Uses ErrorManager for logging and error tracking
   - Uses SecurityManager for authorization checks
   - Registered as an endpoint handler in APIManager

### Security Model

The security model is implemented through:

1. **Role-Based Access Control**:
   - `admin`: Full access to all operations
   - `publisher`: Can list queues and publish messages
   - `consumer`: Can list queues and consume messages

2. **Permission System**:
   - Fine-grained permissions like `create_queue`, `publish:*`, `consume:*`
   - Wildcard support for queue-specific permissions

3. **API Key Authentication**:
   - Custom `SecurityExtension` class extends SecurityManager with API key support
   - Authentication middleware that verifies API keys with every request
   - Secure key storage and user mapping

### Storage Architecture

Data is stored using the CacheManager with ValKey/Redis as the backend:

1. **Queue Storage**:
   - Each queue is stored as a hash under `mb:queue:{queue_name}`
   - Metadata includes creation time, statistics, and configuration

2. **Message Storage**:
   - Messages are stored within the queue data structure
   - Each message has a unique ID, timestamp, and publisher information

3. **Persistence Strategies**:
   - ValKey/Redis for production environments
   - In-memory storage for development/testing in simplified mode

### API Endpoints

The RESTful API exposes the following endpoints:

1. **Queue Management**:
   - `POST /queues`: Create a new queue
   - `GET /queues`: List all available queues
   - `DELETE /queues/{queue}`: Delete a specific queue

2. **Message Operations**:
   - `POST /messages/{queue}`: Publish a message to a queue
   - `GET /messages/{queue}`: Consume a message from a queue

3. **Health and Status**:
   - `GET /api/health`: Check system health and version

## Running the Message Broker

### Option 1: Simplified Mode (No External Dependencies)

The simplified mode demonstrates the functionality without requiring ValKey/Redis:

```bash
# Test the mock managers
php examples/message_broker/test_simplified.php

# Run the simplified server
php examples/message_broker/server_simplified.php
```

This mode:
- Uses mock implementations of all four managers
- Stores data in memory (not persistent across restarts)
- Runs a simulated sequence of operations for testing

### Option 2: Full Mode (With ValKey/Redis)

For full functionality with persistence:

```bash
# Install Redis/ValKey
sudo apt install redis-server
sudo systemctl start redis

# Test the real managers
php examples/message_broker/test_managers.php

# Run the full server
php examples/message_broker/server.php

# In another terminal, run the client
API_KEY=your-api-key php examples/message_broker/client.php
```

This mode:
- Uses real gCore managers with ValKey/Redis backend
- Provides full persistence for queues and messages
- Offers a full interactive client for testing all operations

### Option 3: Docker Deployment

For containerized deployment:

```bash
# Build and run with Docker Compose
cd examples/message_broker
docker-compose up -d

# Use the client to interact
API_KEY=your-api-key php client.php
```

This mode:
- Uses Docker and Docker Compose for containerization
- Includes a ValKey container for persistence
- Manages processes with Supervisor
- Provides proper isolation for production deployment

## Configuration

The Message Broker is configured through YAML files:

1. **message_broker.yaml**:
   ```yaml
   # Core configuration
   core:
     environment: development  # Options: development, staging, production
     debug: true

   # Storage configuration (ValKey/Redis)
   storage:
     host: localhost
     port: 6379
     auth: null
     tls: false
     prefix: mb:

   # Message broker specific configuration
   message_broker:
     queue_prefix: mb:queue:
     max_queue_size: 10000
     message_ttl: 86400  # 1 day in seconds

   # API configuration
   api:
     port: 8080
     cors:
       enabled: true
       origins: ['*']
   ```

2. **users.yaml**:
   ```yaml
   # User configuration
   users:
     admin_user:
       role: admin
       api_key: admin-key-example

     publisher_user:
       role: publisher
       api_key: pub-key-example

     consumer_user:
       role: consumer
       api_key: cons-key-example
   ```

## Testing and Validation

All components have been thoroughly tested:

1. **Manager Tests**:
   - `test_simplified.php`: Tests mock manager implementations
   - `test_managers.php`: Tests real manager implementations with ValKey

2. **Functionality Tests**:
   - Create, list, and delete queues
   - Publish and consume messages
   - Authentication and authorization checks
   - Error handling and recovery

3. **Integration Tests**:
   - Manager interactions and dependencies
   - ValKey/Redis connectivity
   - API endpoint routing and middleware

## Production-Ready Features

The implementation includes several reliable features:

1. **Security**:
   - API key authentication
   - Role-based access control
   - Input validation and sanitization
   - Detailed security logging

2. **Reliability**:
   - error handling
   - Automatic reconnection to ValKey
   - Transaction-based operations
   - Resource cleanup and garbage collection

3. **Deployment**:
   - Docker containerization
   - Supervisor process management
   - Multi-environment configuration
   - Health checks and monitoring

4. **Documentation**:
   - guides
   - API documentation
   - Example code
   - Troubleshooting information

## How It Demonstrates gCore's Production Readiness

The Message Broker example provides concrete evidence of gCore's production readiness:

1. **Manager Integration**:
   - Shows all four managers working together transparently
   - Demonstrates proper dependency handling and initialization
   - Proves the architecture is sound and functional

2. **Extensibility**:
   - SecurityExtension shows how to extend core functionality
   - Configuration demonstrates proper layering and overrides
   - Integration points are well-defined and consistent

3. **Real-World Application**:
   - Implements a useful, practical service using gCore
   - Solves common distributed system challenges
   - Provides a complete working example for users to reference

4. **Deployment Options**:
   - Multiple deployment modes for different environments
   - Docker containerization for production use
   - Local development support with mock implementations

## Conclusion

The Message Broker example conclusively demonstrates that the gCore framework is production-ready, with all components functioning correctly and integrating transparently. With just the installation of ValKey/Redis, anyone can run the full example in a production environment.

For users wanting to explore gCore, this example provides a complete reference implementation that showcases all the framework's capabilities while solving a real-world problem in a clean, maintainable way.