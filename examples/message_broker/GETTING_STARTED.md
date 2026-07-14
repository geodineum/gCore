# gCore Message Broker - Getting Started

This guide walks you through setting up and running the gCore Message Broker example.

## Prerequisites

Before getting started, make sure you have:

1. PHP 8.0+ with these extensions:
   - PHP-FFI
   - PHP-Redis
   - PHP-YAML

2. ValKey or Redis server running on localhost:6379

3. Composer for dependency management

## Installation Steps

1. Make sure you're in the gCore root directory:

```bash
cd /path/to/gCore
```

2. Install PHP dependencies:

```bash
composer install
```

3. Build the Rust GeometricTopology library:

```bash
./compile.sh
```

## Testing the Managers

Before running the message broker, it's important to test that all four base managers are working correctly:

```bash
php examples/message_broker/test_managers.php
```

All tests should pass. If not, check the error messages and make sure all dependencies are installed correctly.

## Running the Message Broker

Once all tests pass, you can start the message broker:

```bash
php examples/message_broker/server.php
```

You should see output confirming:
- gCore initialization
- Configuration loading
- Security roles setup
- API keys generation

Keep this terminal window open as the server continues to run.

## Using the Client

In a separate terminal, run the client to interact with the message broker:

```bash
# Copy one of the API keys displayed by the server
export API_KEY=admin-api-key-xxxx
php examples/message_broker/client.php
```

This will display an interactive menu where you can:
- Create queues
- List queues
- Publish messages
- Consume messages
- Run a complete demo

## Troubleshooting

If you encounter issues:

1. **ValKey/Redis Connection Errors**:
   - Make sure ValKey/Redis is running: `redis-cli ping` should return PONG
   - Check connection settings in config/message_broker.yaml

2. **PHP Extensions Missing**:
   - Check installed extensions: `php -m | grep -E 'redis|ffi|yaml'`
   - Install missing extensions: 
     - `sudo apt-get install php-redis php-ffi php-yaml` (Ubuntu/Debian)
     - `sudo yum install php-redis php-ffi php-yaml` (CentOS/RHEL)

3. **YAML Parsing Errors**:
   - Make sure the YAML files are properly formatted
   - Check if PHP-YAML extension is installed

4. **Compilation Issues**:
   - Make sure Rust is installed: `rustc --version`
   - Check for compilation errors in the output

## Next Steps

Once you have the message broker running, explore:

1. The code in `MessageBroker.php` to understand the implementation
2. The configuration in `config/message_broker.yaml` to see available options
3. The RESTful API endpoints that can be called via cURL or other HTTP clients
4. How the four managers work together to provide a complete solution