#!/bin/bash

# Update script for gNode integration
# This script updates gCore to use the external gNode client and daemon

# Set up variables
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
GCORE_ROOT="$(dirname "$SCRIPT_DIR")"
GNODE_DAEMON_DIR="$GCORE_ROOT/bin"
GNODE_CLIENT_DIR="$HOME/gnode-client"

# Check for gNode client directory
if [ ! -d "$GNODE_CLIENT_DIR" ]; then
    echo "Error: gNode client directory not found at $GNODE_CLIENT_DIR"
    echo "Please clone the gNode client repository to $GNODE_CLIENT_DIR"
    exit 1
fi

# Check if the gNode daemon installation script exists
if [ -f "$GNODE_DAEMON_DIR/gnode-daemon-install.sh" ]; then
    echo "Running gNode daemon installation script..."
    bash "$GNODE_DAEMON_DIR/gnode-daemon-install.sh"
else
    echo "Error: gNode daemon installation script not found at $GNODE_DAEMON_DIR/gnode-daemon-install.sh"
    exit 1
fi

# Update configuration to use gNode daemon
echo "Updating configuration to use gNode daemon..."

CONFIG_DIR="$GCORE_ROOT/config"
TOPOLOGY_CONFIG="$CONFIG_DIR/geometric_topology.yaml"

# Create topology config if it doesn't exist
if [ ! -f "$TOPOLOGY_CONFIG" ]; then
    echo "Creating topology configuration..."
    mkdir -p "$CONFIG_DIR"
    cat > "$TOPOLOGY_CONFIG" << 'EOF'
# Geometric Topology Configuration
# This file defines the configuration for the Rust-based geometric topology engine.

# Library configuration
library:
  path: "/home/nielstoren/gCore/lib/libgeometric_topology.so"
  enabled: false
  use_for_bootstrap: false
  fallback_supported: true

# Daemon configuration (for gNode mode)
daemon:
  enabled: true
  path: "/home/nielstoren/gCore/bin/gnode-daemon"
  stream_prefix: "gnode"
  auto_start: true
  timeout: 5.0
  retry_attempts: 3
  retry_delay: 0.5
  use_fallback: true
  cache_expiration: 300
  debug: false
  
# Use the external gNode client from ~/gnode-client
external_client:
  enabled: true
  path: "/home/nielstoren/gnode-client"

# Capability space configuration
dimensions: 8
capability_dimensions:
  security: 0
  auth: 1
  crypto: 2
  rules: 3
  cache: 4
  storage: 5
  errors: 6
  logging: 7
EOF
else
    # Update existing config
    echo "Updating existing topology configuration..."
    
    # Check if external_client section exists
    if ! grep -q "external_client:" "$TOPOLOGY_CONFIG"; then
        # Add external_client section
        sed -i '/daemon:/,/debug: false/{s/debug: false/debug: false\n  \n# Use the external gNode client from ~\/gnode-client\nexternal_client:\n  enabled: true\n  path: "\/home\/nielstoren\/gnode-client"/}' "$TOPOLOGY_CONFIG"
    fi
    
    # Update library enabled to false
    sed -i 's/enabled: true/enabled: false/' "$TOPOLOGY_CONFIG"
    sed -i 's/use_for_bootstrap: true/use_for_bootstrap: false/' "$TOPOLOGY_CONFIG"
    
    # Make sure daemon is enabled
    sed -i '/daemon:/,/enabled:/{s/enabled: false/enabled: true/}' "$TOPOLOGY_CONFIG"
fi

# Update composer dependencies
echo "Updating composer dependencies..."
cd "$GCORE_ROOT" && composer update

echo "gNode update completed successfully"
echo "gCore is now configured to use the external gNode client and daemon"