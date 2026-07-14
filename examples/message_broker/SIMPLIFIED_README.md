# gCore Message Broker - Simplified Mode

This guide shows how to run the gCore Message Broker in simplified mode, without requiring ValKey/Redis or other external dependencies.

## What is Simplified Mode?

Simplified mode uses mock implementations of the four gCore base managers:
- MockErrorManager
- MockCacheManager
- MockSecurityManager
- MockAPIManager

These mock implementations provide the same interface as the real managers but store all data in memory rather than using external services.

## Running the Simplified Message Broker

### Step 1: Run the Manager Test Script

First, verify that all the mock managers are working correctly:

```bash
cd /path/to/gCore
php examples/message_broker/test_simplified.php
```

You should see a series of tests with most passing (the input sanitization test may fail, but this won't affect the core functionality).

### Step 2: Run the Simplified Server

Run the simplified server script:

```bash
php examples/message_broker/server_simplified.php
```

This will:
1. Initialize the mock managers
2. Set up security roles and permissions
3. Create demo users with API keys
4. Initialize the message broker
5. Simulate some operations (create queue, publish message, list queues, consume message)

## Understanding the Implementation

The simplified implementation includes:

1. **Mock Managers**: In-memory implementations of the four gCore managers
2. **SimplifiedSecurityExtension**: A simplified version of the SecurityExtension for API key management
3. **SimplifiedMessageBroker**: A working message broker that stores queues and messages in memory
4. **MockRequest**: A simplified HTTP request implementation for testing

## Demo API Keys

The simplified server creates three demo users with fixed API keys:

- **admin_user**: `admin-key-12345` (can create/delete queues, publish, and consume)
- **publisher_user**: `publish-key-12345` (can list queues and publish messages)
- **consumer_user**: `consume-key-12345` (can list queues and consume messages)

## What Next?

Once you've verified the Message Broker works in simplified mode, you can:

1. Install ValKey/Redis to test with real persistence
2. Run the full server.php implementation
3. Use the client.php script to interact with the broker
4. Create your own client applications that use the Message Broker API

## Limitations of Simplified Mode

The simplified mode has some limitations:

1. All data is stored in memory and lost when the script ends
2. There's no real HTTP server - the API is simulated
3. Authentication and authorization are simplified
4. No real persistence or distributed capabilities

However, it demonstrates the core functionality and architecture of the gCore Message Broker.