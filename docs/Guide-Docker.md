# gCore Docker Setup Guide

This guide provides instructions for running gCore in a Docker environment, including basic setup, configuration options, and how to use the capability checker to optimize your installation.

## Quick Start

The easiest way to start using gCore with Docker:

```bash
# Clone the repository (if you haven't already)
git clone https://github.com/yourusername/gCore.git
cd gCore

# Start the container
docker-compose up -d
```

This will:
1. Build the gCore Docker image with all required dependencies
2. Start the container with necessary services
3. Make gCore available at http://localhost:8000

## Docker Configuration Options

### Basic Configuration

The default `docker-compose.yml` includes:

```yaml
version: '3'

services:
  gcore:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "8000:8000"  # HTTP server
      - "6380:6379"  # Redis/ValKey
    volumes:
      - ./config:/var/www/gcore/config
      - ./examples:/var/www/gcore/examples
    environment:
      - APP_ENV=development
      - SITE_ID=default
      - NODE_ID=docker
      - VALKEY_HOST=localhost
      - VALKEY_PORT=6379
      - GCORE_DEBUG=true
      - GCORE_LOG_LEVEL=debug
    restart: unless-stopped
```

### Environment Variables

You can configure the gCore Docker container using environment variables:

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_ENV` | Application environment | `development` |
| `SITE_ID` | Site identifier for multi-tenant setups | `default` |
| `NODE_ID` | Node identifier for distributed systems | `docker` |
| `VALKEY_HOST` | ValKey/Redis server host | `localhost` |
| `VALKEY_PORT` | ValKey/Redis server port | `6379` |
| `VALKEY_PASSWORD` | ValKey/Redis authentication password | None |
| `GCORE_*` | Any variable with this prefix will be added to .env | Varies |

### Volume Mounts

Mount specific directories for development or to use your own code:

```yaml
volumes:
  - ./my-project:/var/www/custom-project # Your project code
  - ./config:/var/www/gcore/config       # Custom configuration
  - ./examples:/var/www/gcore/examples   # Custom examples
```

## Using the Capability Checker

The capability checker can scan your project to identify which gCore capabilities are being used and generate a minimal configuration file.

### Basic Usage

```bash
# Access the container shell
docker exec -it gcore bash

# Run the capability checker on your project
php gCoreCLI.php check-capabilities /var/www/custom-project --recursive --output-config=/var/www/gcore/config/my-config.yaml
```

### Options

- `--recursive` or `-r`: Scan subdirectories
- `--verbose` or `-v`: Show detailed scan information
- `--output-config=PATH`: Generate minimal configuration file

### Example Workflow

1. Mount your project into the container:

```yaml
volumes:
  - ./my-project:/var/www/custom-project
```

2. Scan for capabilities:

```bash
docker exec -it gcore php gCoreCLI.php check-capabilities /var/www/custom-project -r -v --output-config=/var/www/gcore/config/my-config.yaml
```

3. Use the generated configuration in your application:

```php
$configLoader = new \gCore\Modules\Core\Utils\ConfigLoader();
$config = $configLoader->loadYamlFile('config/my-config.yaml');
$gCore->initialize($config);
```

## Docker Setup Examples

### Development Setup

```yaml
version: '3'

services:
  gcore:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    volumes:
      - ./:/var/www/gcore
    environment:
      - APP_ENV=development
      - SITE_ID=dev-site
      - NODE_ID=dev-node
      - GCORE_DEBUG=true
```

### Production Setup

```yaml
version: '3'

services:
  gcore:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    volumes:
      - ./config:/var/www/gcore/config
    environment:
      - APP_ENV=production
      - SITE_ID=prod-site
      - NODE_ID=prod-node
      - VALKEY_HOST=valkey
      - VALKEY_PORT=6379
      - GCORE_DEBUG=false
    depends_on:
      - valkey
    restart: always

  valkey:
    image: redis:7-alpine
    volumes:
      - valkey-data:/data
    command: redis-server --appendonly yes
    restart: always

volumes:
  valkey-data:
```

### WordPress Integration Setup

```yaml
version: '3'

services:
  wordpress:
    image: wordpress:latest
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wordpress
      WORDPRESS_DB_PASSWORD: wordpress
      WORDPRESS_DB_NAME: wordpress
    volumes:
      - ./wp-content:/var/www/html/wp-content
      - ./gcore:/var/www/html/wp-content/plugins/gcore
    depends_on:
      - db
      - gcore-services

  db:
    image: mysql:5.7
    environment:
      MYSQL_ROOT_PASSWORD: rootpassword
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
    volumes:
      - db_data:/var/lib/mysql

  gcore-services:
    build:
      context: ./gcore
      dockerfile: Dockerfile
    environment:
      - APP_ENV=development
      - SITE_ID=wordpress-site
      - NODE_ID=wordpress-node
      - GCORE_DEBUG=true

volumes:
  db_data:
```

## Troubleshooting

### Container Won't Start

Check the container logs:
```bash
docker-compose logs gcore
```

### Connection Issues to Redis/ValKey

1. Verify Redis is running inside the container:
```bash
docker exec -it gcore supervisorctl status redis
```

2. Check Redis connection:
```bash
docker exec -it gcore redis-cli ping
```

## Advanced Configuration

### External ValKey/Redis

To use an external Redis/ValKey instance:

```yaml
environment:
  - VALKEY_HOST=my-redis-server.example.com
  - VALKEY_PORT=6379
  - VALKEY_PASSWORD=secure_password
```

### Custom Startup Commands

Create a custom entrypoint script:

```bash
#!/bin/bash

# Custom setup
echo "Running custom setup..."
php /var/www/gcore/gCoreCLI.php check-capabilities /var/www/custom-project -r --output-config=/var/www/gcore/config/auto-config.yaml

# Continue with original entrypoint
exec /var/www/gcore/docker/entrypoint.sh "$@"
```

Then update your Dockerfile:
```dockerfile
COPY custom-entrypoint.sh /var/www/gcore/custom-entrypoint.sh
RUN chmod +x /var/www/gcore/custom-entrypoint.sh
ENTRYPOINT ["/var/www/gcore/custom-entrypoint.sh"]
```

## Next Steps

After setting up gCore with Docker:

1. Check out the example applications in `/var/www/gcore/examples`
2. Explore the API documentation
3. View the dashboard at http://localhost:8000
4. Use the CLI tools for management tasks

For more information, see:
- [gCore Documentation](INDEX.md)
- [API Manager Guide](Component-APIManager.md)
- [Security Manager Guide](managers/SecurityManager/README_SecurityManager.md)
- [CLI Usage Guide](Guide-CLI.md)