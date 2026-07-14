#!/bin/bash

# gNode Daemon installation script
# This script installs the gNode Daemon and creates necessary configuration

# Set up variables
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
GCORE_ROOT="$(dirname "$SCRIPT_DIR")"
GNODE_DAEMON_DIR="$GCORE_ROOT/bin"
GNODE_CLIENT_DIR="$HOME/gnode-client"

# Ensure the bin directory exists
mkdir -p "$GNODE_DAEMON_DIR"

echo "Installing gNode daemon from client directory: $GNODE_CLIENT_DIR"

# Check if the gNode client directory exists
if [ ! -d "$GNODE_CLIENT_DIR" ]; then
    echo "Error: gNode client directory not found at $GNODE_CLIENT_DIR"
    exit 1
fi

# Check if the daemon binary exists
if [ -x "$GNODE_CLIENT_DIR/bin/gnode-daemon" ]; then
    cp "$GNODE_CLIENT_DIR/bin/gnode-daemon" "$GNODE_DAEMON_DIR/gnode-daemon"
    chmod +x "$GNODE_DAEMON_DIR/gnode-daemon"
    echo "gNode daemon installed to $GNODE_DAEMON_DIR/gnode-daemon"
else
    echo "Warning: gNode daemon binary not found in $GNODE_CLIENT_DIR/bin/gnode-daemon"
    echo "Looking for daemon in alternate locations..."
    
    # Look for daemon in tools directory
    if [ -x "$GNODE_CLIENT_DIR/tools/gnode-daemon" ]; then
        cp "$GNODE_CLIENT_DIR/tools/gnode-daemon" "$GNODE_DAEMON_DIR/gnode-daemon"
        chmod +x "$GNODE_DAEMON_DIR/gnode-daemon"
        echo "gNode daemon installed to $GNODE_DAEMON_DIR/gnode-daemon from tools directory"
    else
        echo "Error: Could not find gNode daemon binary in client directory"
        exit 1
    fi
fi

# Create autoloader script to register external gNode client library
cat > "$GCORE_ROOT/Modules/Core/Client/gNodeAutoloader.php" << 'EOF'
<?php
namespace gCore\Modules\Core\Client;

/**
 * Autoloader for External gNode Client
 */
class gNodeAutoloader {
    /**
     * Register autoloader for external gNode client
     */
    public static function register(): void {
        $gNodeClientDir = getenv('HOME') . '/gnode-client';
        $autoloaderPath = $gNodeClientDir . '/vendor/autoload.php';
        
        if (file_exists($autoloaderPath)) {
            require_once $autoloaderPath;
        } else {
            error_log("Warning: External gNode client autoloader not found at {$autoloaderPath}");
        }
    }
}
EOF

echo "Created autoloader script at $GCORE_ROOT/Modules/Core/Client/gNodeAutoloader.php"

# Update composer.json to include dependency
COMPOSER_JSON="$GCORE_ROOT/composer.json"

if [ -f "$COMPOSER_JSON" ]; then
    # Check if the repository already exists in composer.json
    if ! grep -q '"url": "~/gnode-client"' "$COMPOSER_JSON"; then
        # Create a temporary file
        TMP_FILE=$(mktemp)
        
        # Use jq to add the repository if it doesn't exist
        jq '.repositories += [{"type": "path", "url": "~/gnode-client"}]' "$COMPOSER_JSON" > "$TMP_FILE"
        mv "$TMP_FILE" "$COMPOSER_JSON"
        
        echo "Added gNode client repository to composer.json"
    else
        echo "gNode client repository already exists in composer.json"
    fi
    
    # Check if the dependency already exists
    if ! grep -q '"gcore/gnode-client"' "$COMPOSER_JSON"; then
        # Add the dependency
        TMP_FILE=$(mktemp)
        
        # Use jq to add the dependency
        jq '.require += {"gcore/gnode-client": "*"}' "$COMPOSER_JSON" > "$TMP_FILE"
        mv "$TMP_FILE" "$COMPOSER_JSON"
        
        echo "Added gNode client dependency to composer.json"
    else
        echo "gNode client dependency already exists in composer.json"
    fi
    
    # Run composer install/update
    echo "Running composer update to install gNode client dependency"
    cd "$GCORE_ROOT" && composer update
else
    echo "Warning: composer.json not found at $COMPOSER_JSON"
fi

echo "gNode daemon installation complete"
echo "You can now use the gNode daemon with gCore"