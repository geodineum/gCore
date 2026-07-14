# gCore Docker Deployment

This directory contains Docker configuration files for deploying gCore in Docker containers.

## Quick Start

The easiest way to get started with gCore is to use Docker Compose:

```bash
docker-compose up -d
```

This will build and start the gCore container with all necessary components:
- PHP 8.1 with required extensions (including FFI)
- Redis/ValKey for backend storage
- Rust tools for building the geometric topology library
- PHP development server on port 8000

## Configuration Options

### Environment Variables

You can configure gCore by setting environment variables in the `docker-compose.yml` file or passing them to the Docker run command:

#### Core Configuration:
- `APP_ENV`: Application environment (development, staging, production)
- `SITE_ID`: Site identifier for multi-tenant configurations
- `NODE_ID`: Node identifier for distributed setups
- `RUN_SETUP`: Set to "true" to run the setup script on container start

#### ValKey/Redis Configuration:
- `VALKEY_HOST`: Host for the ValKey/Redis server
- `VALKEY_PORT`: Port for the ValKey/Redis server
- `VALKEY_PASSWORD`: Password for the ValKey/Redis server

#### Custom Configuration:
Any environment variable prefixed with `GCORE_` will be added to the .env file with the prefix removed.
Example: `GCORE_DEBUG=true` will set `DEBUG=true` in the .env file.

### Custom Configuration Files

You can mount custom configuration files using Docker volumes:

```bash
docker run -d \
  -p 8000:8000 \
  -v /path/to/custom/config:/var/www/gcore/config \
  gcore
```

## Using an External ValKey/Redis Instance

By default, gCore uses the built-in Redis instance. To use an external Redis or ValKey instance:

1. Uncomment the `valkey` service in `docker-compose.yml`
2. Update the environment variables to point to the external instance:
   ```yaml
   environment:
     - VALKEY_HOST=valkey
     - VALKEY_PORT=6379
   ```

## Development Workflow

For development, you can mount your local code into the container:

```bash
docker run -d \
  -p 8000:8000 \
  -v $(pwd):/var/www/gcore \
  gcore
```

This allows you to make changes to your code without rebuilding the container.

## Troubleshooting

### FFI Issues

If you encounter FFI-related issues, make sure that:
1. The Rust library was built successfully
2. FFI is enabled in PHP (check with `php -i | grep ffi`)

### Redis/ValKey Connection Issues

If gCore cannot connect to Redis/ValKey:
1. Check that Redis is running (`supervisorctl status redis`)
2. Verify the connection settings in the .env file
3. Try connecting manually with `redis-cli`

### Logs

To view container logs:
```bash
docker-compose logs gcore
```

For service-specific logs:
```bash
docker exec -it gcore_gcore_1 cat /var/log/supervisor/php.log
docker exec -it gcore_gcore_1 cat /var/log/supervisor/redis.log
```