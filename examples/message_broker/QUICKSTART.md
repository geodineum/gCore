# gCore Message Broker - Quick Start Guide

This guide provides step-by-step instructions for getting the gCore Message Broker up and running quickly.

## Quick Start with Docker

The easiest way to start the Message Broker is with Docker and Docker Compose.

### Prerequisites

- Docker and Docker Compose installed
- Git to clone the gCore repository

### Steps

1. Clone the gCore repository:
   ```bash
   git clone https://github.com/your-org/gCore.git
   cd gCore
   ```

2. Navigate to the message broker example:
   ```bash
   cd examples/message_broker
   ```

3. Start the service with Docker Compose:
   ```bash
   docker-compose up -d
   ```

4. Check the logs to see the generated API keys:
   ```bash
   docker-compose logs | grep "Demo Users API Keys"
   ```

5. Use the client to interact with the broker:
   ```bash
   # Replace with the admin API key from the logs
   export API_KEY=admin-api-key-xxx
   php client.php
   ```

6. After testing, you can stop the service:
   ```bash
   docker-compose down
   ```

## Direct Execution (No Docker)

If you prefer to run without Docker, follow these steps:

### Prerequisites

- PHP 8.0+ with Redis extension
- Redis/ValKey server running
- Composer (for dependency management)

### Steps

1. Clone the gCore repository:
   ```bash
   git clone https://github.com/your-org/gCore.git
   cd gCore
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure environment variables:
   ```bash
   export GCORE_ENV=development
   export VALKEY_HOST=localhost
   export VALKEY_PORT=6379
   export API_PORT=8080
   ```

4. Start the message broker:
   ```bash
   cd examples/message_broker
   php server.php
   ```

5. In a separate terminal, run the client:
   ```bash
   # Replace with the admin API key from the server output
   export API_KEY=admin-api-key-xxx
   php client.php
   ```

## Testing with cURL

You can also use cURL to test the API directly:

### Health Check

```bash
curl http://localhost:8080/api/health
```

### Create Queue

```bash
curl -X POST http://localhost:8080/queues \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{"name": "test_queue"}'
```

### List Queues

```bash
curl http://localhost:8080/queues \
  -H "X-API-Key: your-api-key"
```

### Publish Message

```bash
curl -X POST http://localhost:8080/messages/test_queue \
  -H "Content-Type: application/json" \
  -H "X-API-Key: your-api-key" \
  -d '{"message": "Hello, world!"}'
```

### Consume Message

```bash
curl http://localhost:8080/messages/test_queue \
  -H "X-API-Key: your-api-key"
```

## Demo Script

Here's a complete demo script you can run after installing curl:

```bash
#!/bin/bash
# Replace with your actual API key
API_KEY="your-api-key"

# Set API URL
API_URL="http://localhost:8080"

# Health check
echo "Checking health..."
curl -s "$API_URL/api/health" | jq .

# Create queue
echo -e "\nCreating queue..."
curl -s -X POST \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d '{"name": "demo_queue"}' \
  "$API_URL/queues" | jq .

# List queues
echo -e "\nListing queues..."
curl -s -H "X-API-Key: $API_KEY" "$API_URL/queues" | jq .

# Publish messages
for i in {1..3}; do
  echo -e "\nPublishing message $i..."
  curl -s -X POST \
    -H "Content-Type: application/json" \
    -H "X-API-Key: $API_KEY" \
    -d "{\"message\": \"Test message #$i\"}" \
    "$API_URL/messages/demo_queue" | jq .
done

# Consume messages
for i in {1..3}; do
  echo -e "\nConsuming message $i..."
  curl -s -H "X-API-Key: $API_KEY" "$API_URL/messages/demo_queue" | jq .
done

# Delete queue
echo -e "\nDeleting queue..."
curl -s -X DELETE \
  -H "X-API-Key: $API_KEY" \
  "$API_URL/queues/demo_queue" | jq .
```

Save this as `demo.sh`, make it executable (`chmod +x demo.sh`), and run it to see the Message Broker in action.

## Next Steps

For more detailed information, refer to:

- [README.md](README.md): Full documentation on the Message Broker
- [Dockerfile](Dockerfile): Docker configuration details
- [docker-compose.yml](docker-compose.yml): Service orchestration configuration
- [MessageBroker.php](MessageBroker.php): Core implementation
- [server.php](server.php): Server initialization
- [client.php](client.php): Sample client