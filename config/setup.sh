#!/bin/bash
# =============================================================================
# Geodineum gCore Configuration Setup
# =============================================================================
# Installs gCore configs to /etc/geodineum/components/gCore/
#
# Usage: sudo ./setup.sh [--site SITE_ID]
#
# Examples:
#   sudo ./setup.sh                           # Install gCore defaults only
#   sudo ./setup.sh --site staging_example_com # Also create site config
#
# Location: config/setup.sh
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Paths
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GEODINEUM_ROOT="/etc/geodineum"
GCORE_COMPONENT_DIR="${GEODINEUM_ROOT}/components/gCore"
SITES_DIR="${GEODINEUM_ROOT}/sites"
CREDENTIALS_DIR="${GEODINEUM_ROOT}/credentials"

# Parse arguments
SITE_ID=""
while [[ $# -gt 0 ]]; do
    case $1 in
        --site)
            SITE_ID="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: sudo $0 [--site SITE_ID]"
            echo ""
            echo "Options:"
            echo "  --site SITE_ID    Create site-specific config (e.g., staging_example_com)"
            echo "  -h, --help        Show this help"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

# Check root
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}Error: This script must be run with sudo${NC}"
    exit 1
fi

echo -e "${GREEN}=== Geodineum gCore Configuration Setup ===${NC}"
echo ""

# -----------------------------------------------------------------------------
# Step 1: Verify /etc/geodineum exists
# -----------------------------------------------------------------------------
if [[ ! -d "$GEODINEUM_ROOT" ]]; then
    echo -e "${RED}Error: $GEODINEUM_ROOT does not exist${NC}"
    echo "Please run the ecosystem bootstrap first."
    exit 1
fi

# -----------------------------------------------------------------------------
# Step 2: Create gCore component directory
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Creating gCore component directory...${NC}"
mkdir -p "$GCORE_COMPONENT_DIR"

# -----------------------------------------------------------------------------
# Step 3: Install default.yaml
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Installing gCore default.yaml...${NC}"
if [[ -f "$SCRIPT_DIR/default.yaml" ]]; then
    cp "$SCRIPT_DIR/default.yaml" "$GCORE_COMPONENT_DIR/default.yaml"
    chmod 644 "$GCORE_COMPONENT_DIR/default.yaml"
    echo -e "${GREEN}  ✓ Installed: $GCORE_COMPONENT_DIR/default.yaml${NC}"
else
    echo -e "${RED}  ✗ Source file not found: $SCRIPT_DIR/default.yaml${NC}"
    exit 1
fi

# -----------------------------------------------------------------------------
# Step 4: Create gcore.env (component-specific env vars)
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Creating gCore environment file...${NC}"
if [[ ! -f "$GCORE_COMPONENT_DIR/gcore.env" ]]; then
    cat > "$GCORE_COMPONENT_DIR/gcore.env" << 'EOF'
# =============================================================================
# gCore Component Environment Variables
# =============================================================================
# Component-specific overrides for gCore framework.
# Loaded after bootstrap.env, before site.env
#
# Location: /etc/geodineum/components/gCore/gcore.env
# =============================================================================

# Config path (used by gCore to find default.yaml)
GCORE_CONFIG_PATH="/etc/geodineum/components/gCore"

# Logging
GCORE_LOG_LEVEL="${GNODE_LOG_LEVEL:-info}"
GCORE_DEBUG="${GNODE_DEBUG:-false}"

# Cache settings
GCORE_CACHE_PATH="/var/cache/gcore"
GCORE_CACHE_TTL="3600"

# APCu settings
GCORE_APCU_ENABLED="true"
GCORE_APCU_TTL="300"
EOF
    chmod 644 "$GCORE_COMPONENT_DIR/gcore.env"
    echo -e "${GREEN}  ✓ Created: $GCORE_COMPONENT_DIR/gcore.env${NC}"
else
    echo -e "${YELLOW}  - Skipped (already exists): $GCORE_COMPONENT_DIR/gcore.env${NC}"
fi

# -----------------------------------------------------------------------------
# Step 5: Create site config (if --site specified)
# -----------------------------------------------------------------------------
if [[ -n "$SITE_ID" ]]; then
    echo ""
    echo -e "${YELLOW}Creating site configuration for: $SITE_ID${NC}"

    SITE_FILE="${SITES_DIR}/${SITE_ID}.yaml"

    if [[ ! -f "$SITE_FILE" ]]; then
        cat > "$SITE_FILE" << EOF
# =============================================================================
# Site Configuration: ${SITE_ID}
# =============================================================================
# Override gCore defaults for this site only.
# Only include values that DIFFER from /etc/geodineum/components/gCore/default.yaml
#
# Location: /etc/geodineum/sites/${SITE_ID}.yaml
# =============================================================================

site_id: ${SITE_ID}
environment: production  # testing | staging | acceptance | production
theme: gCube             # gCube | your child theme

# Override specific manager settings (deep merge with defaults)
# managers:
#   APIManager:
#     rate_limiting:
#       max: 100  # Higher limit for this site

# Override capabilities for topology registration
# capabilities:
#   throughput_tier: enterprise

# Site-specific security settings
# security:
#   viewkey: ""  # For non-production access control
EOF
        chmod 644 "$SITE_FILE"
        echo -e "${GREEN}  ✓ Created: $SITE_FILE${NC}"
    else
        echo -e "${YELLOW}  - Skipped (already exists): $SITE_FILE${NC}"
    fi

    # per-site credential location is sites/<id>/valkey_client.password
    # (was credentials/valkey_client_<id>.password before the layout change —
    # that path is no longer readable by www-data on current installs).
    CRED_FILE="${SITES_DIR}/${SITE_ID}/valkey_client.password"
    LEGACY_CRED_FILE="${CREDENTIALS_DIR}/valkey_client_${SITE_ID}.password"
    if [[ -f "$CRED_FILE" ]]; then
        echo -e "${GREEN}  ✓ Credential file exists: $CRED_FILE${NC}"
    elif [[ -f "$LEGACY_CRED_FILE" ]]; then
        echo -e "${YELLOW}  ⚠ Previously, credential found at: $LEGACY_CRED_FILE${NC}"
        echo -e "    Run gNode/scripts/provision-gnode-site.sh $SITE_ID to migrate."
    else
        echo -e "${YELLOW}  ! Warning: Credential file not found at: $CRED_FILE${NC}"
        echo -e "    Create it with: sudo /opt/geodineum/gNode/scripts/provision-gnode-site.sh $SITE_ID"
    fi
fi

# -----------------------------------------------------------------------------
# Step 6: Set ownership
# -----------------------------------------------------------------------------
echo ""
echo -e "${YELLOW}Setting permissions...${NC}"
chown -R root:www-data "$GCORE_COMPONENT_DIR"
chmod 755 "$GCORE_COMPONENT_DIR"
echo -e "${GREEN}  ✓ Permissions set${NC}"

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
echo ""
echo -e "${GREEN}=== Setup Complete ===${NC}"
echo ""
echo "Installed files:"
echo "  - $GCORE_COMPONENT_DIR/default.yaml  (gCore defaults)"
echo "  - $GCORE_COMPONENT_DIR/gcore.env     (component env vars)"
if [[ -n "$SITE_ID" ]]; then
    echo "  - ${SITES_DIR}/${SITE_ID}.yaml       (site config)"
fi
echo ""
echo "Next steps:"
echo "  1. Edit site config: sudo nano ${SITES_DIR}/<site_id>.yaml"
echo "  2. Create credentials: sudo /opt/gNode/scripts/setup-site-acl.sh <site_id>"
echo "  3. Restart PHP-FPM: sudo systemctl restart php8.2-fpm"
echo ""
