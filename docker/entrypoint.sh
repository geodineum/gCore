#!/bin/bash
set -e

# Function to replace or add environment variables to .env file
update_env_var() {
    local key=$1
    local value=$2
    local env_file="/var/www/gcore/.env"
    
    # If the key exists, replace it
    if grep -q "^${key}=" "$env_file"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$env_file"
    else
        # If the key doesn't exist, add it
        echo "${key}=${value}" >> "$env_file"
    fi
}

# Process environment variables and update .env file
for var in $(env | grep -E "^GCORE_"); do
    key=$(echo "$var" | cut -d= -f1 | sed 's/^GCORE_//')
    value=$(echo "$var" | cut -d= -f2-)
    update_env_var "$key" "$value"
done

# Update ValKey/Redis config if provided
if [ -n "$VALKEY_HOST" ]; then
    update_env_var "VALKEY_HOST" "$VALKEY_HOST"
fi

if [ -n "$VALKEY_PORT" ]; then
    update_env_var "VALKEY_PORT" "$VALKEY_PORT"
else
    # Default to the local Redis instance
    update_env_var "VALKEY_PORT" "6379"
fi

if [ -n "$VALKEY_PASSWORD" ]; then
    update_env_var "VALKEY_PASSWORD" "$VALKEY_PASSWORD"
fi

# Update site configuration
if [ -n "$SITE_ID" ]; then
    update_env_var "SITE_ID" "$SITE_ID"
fi

if [ -n "$NODE_ID" ]; then
    update_env_var "NODE_ID" "$NODE_ID"
fi

# Set environment
if [ -n "$APP_ENV" ]; then
    update_env_var "APP_ENV" "$APP_ENV"
fi

# Additional initialization if needed
if [ "$RUN_SETUP" = "true" ]; then
    echo "Running setup script..."
    php /var/www/gcore/setup.sh
fi

# Execute the command passed to this script
exec "$@"