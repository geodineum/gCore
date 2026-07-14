#!/bin/bash
#
# gNode Site Provisioning Script
# Creates ValKey ACL user and password file for a new site
#
# Usage: ./provision-gnode-site.sh <site_id> [environment]
# Example: ./provision-gnode-site.sh toren_global production
#
# This script must be run as root or with sudo

set -e

# Configuration. per-site credentials live in their own per-site
# directory under /etc/geodineum/sites/<site_id>/, NOT in the
# admin-level credentials/ dir. www-data can read its own site's
# credential via gnode:www-data group ownership on the file.
GNODE_DIR="/opt/geodineum/gNode"
GNODE_PASSWORD_DIR="$GNODE_DIR/.gnode"   # = /etc/geodineum/credentials/ (via symlink) — daemon-tier reads
SITES_DIR="/etc/geodineum/sites"          # per-site dir for www-data-accessible creds
VALKEY_PORT=47445
VALKEY_HOST="127.0.0.1"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check arguments
if [ -z "$1" ]; then
    echo -e "${RED}Error: site_id is required${NC}"
    echo "Usage: $0 <site_id> [environment]"
    echo "Example: $0 toren_global production"
    exit 1
fi

SITE_ID="$1"
ENVIRONMENT="${2:-production}"
USER_NAME="gnode_client_${SITE_ID}"
# per-site credential under sites/<id>/ (www-data accessible),
# not credentials/ (locked to geodineum-creds group).
SITE_DIR="${SITES_DIR}/${SITE_ID}"
PASSWORD_FILE="${SITE_DIR}/valkey_client.password"
# Legacy location (previously installs may still have credentials here).
LEGACY_PASSWORD_FILE="$GNODE_PASSWORD_DIR/valkey_client_${SITE_ID}.password"

echo -e "${GREEN}=== gNode Site Provisioning ===${NC}"
echo "Site ID: $SITE_ID"
echo "Environment: $ENVIRONMENT"
echo "ValKey User: $USER_NAME"
echo "Password File: $PASSWORD_FILE"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: This script must be run as root${NC}"
    exit 1
fi

# migrate previously credential to the new per-site location if
# present. Idempotent — preserves the existing password so the
# already-provisioned ACL user still authenticates.
if [ -f "$LEGACY_PASSWORD_FILE" ] && [ ! -f "$PASSWORD_FILE" ]; then
    mkdir -p "$SITE_DIR"
    mv "$LEGACY_PASSWORD_FILE" "$PASSWORD_FILE"
    echo "Migrated credential: $LEGACY_PASSWORD_FILE → $PASSWORD_FILE"
fi

# Check if password file already exists
if [ -f "$PASSWORD_FILE" ]; then
    echo -e "${YELLOW}Warning: Password file already exists${NC}"
    read -p "Overwrite? (y/N): " confirm
    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        echo "Aborted."
        exit 0
    fi
fi

# Create per-site directory — gnode:www-data 0750.
# Each site gets its own subdir so credential ownership scopes per-site.
mkdir -p "$SITE_DIR"
chown gnode:www-data "$SITE_DIR"
chmod 0750 "$SITE_DIR"

# Generate password
echo -e "${GREEN}Generating password...${NC}"
PASSWORD=$(openssl rand -base64 48)
echo -n "$PASSWORD" > "$PASSWORD_FILE"

# Set proper permissions (gnode:www-data 0640 — gnode user
# writes/rotates, www-data reads via direct group ownership;
# previously was hardcoded `august:www-data` which broke on every
# non-author host).
chown gnode:www-data "$PASSWORD_FILE"
chmod 0640 "$PASSWORD_FILE"
echo "Password file created: $PASSWORD_FILE"

# Get daemon password for admin operations
DAEMON_PASSWORD_FILE="$GNODE_PASSWORD_DIR/valkey_daemon.password"
if [ ! -f "$DAEMON_PASSWORD_FILE" ]; then
    echo -e "${RED}Error: Daemon password file not found${NC}"
    exit 1
fi
DAEMON_PASSWORD=$(cat "$DAEMON_PASSWORD_FILE")

# Create ValKey ACL user
echo -e "${GREEN}Creating ValKey ACL user...${NC}"

# Build the ACL command with proper key patterns
ACL_CMD="ACL SETUSER $USER_NAME on sanitize-payload >$PASSWORD"
ACL_CMD="$ACL_CMD ~error:${SITE_ID}:*"
ACL_CMD="$ACL_CMD ~cache:${SITE_ID}:*"
ACL_CMD="$ACL_CMD ~session:${SITE_ID}:*"
ACL_CMD="$ACL_CMD ~${SITE_ID}:error:*"
ACL_CMD="$ACL_CMD ~${SITE_ID}:cache:*"
ACL_CMD="$ACL_CMD ~${SITE_ID}:session:*"
ACL_CMD="$ACL_CMD ~${SITE_ID}:gnode:*"
ACL_CMD="$ACL_CMD ~{${SITE_ID}}:gnode:*"
ACL_CMD="$ACL_CMD ~{${SITE_ID}}:bundle:*"
ACL_CMD="$ACL_CMD ~{${SITE_ID}}:cache:*"
ACL_CMD="$ACL_CMD ~{${SITE_ID}}:metrics:*"
ACL_CMD="$ACL_CMD ~{${SITE_ID}}:*"
ACL_CMD="$ACL_CMD ~${SITE_ID}:*"
ACL_CMD="$ACL_CMD ~{testing}:gnode:*"
ACL_CMD="$ACL_CMD ~{staging}:gnode:*"
ACL_CMD="$ACL_CMD ~{acceptance}:gnode:*"
ACL_CMD="$ACL_CMD ~{production}:gnode:*"
ACL_CMD="$ACL_CMD ~{default}:gnode:*"
ACL_CMD="$ACL_CMD ~{geodineum}:gnode:*"
ACL_CMD="$ACL_CMD ~gnode:*"
ACL_CMD="$ACL_CMD ~gnode:routing:*"
ACL_CMD="$ACL_CMD ~topology:*"
ACL_CMD="$ACL_CMD ~template:*"
ACL_CMD="$ACL_CMD ~membership:*"
ACL_CMD="$ACL_CMD resetchannels"
ACL_CMD="$ACL_CMD &${SITE_ID}:gnode:broadcast:*"
ACL_CMD="$ACL_CMD &${SITE_ID}:gnode:events:*"
ACL_CMD="$ACL_CMD &{${SITE_ID}}:*"
ACL_CMD="$ACL_CMD &{testing}:gnode:broadcast:*"
ACL_CMD="$ACL_CMD &{testing}:gnode:unified:*"
ACL_CMD="$ACL_CMD &{staging}:gnode:broadcast:*"
ACL_CMD="$ACL_CMD &{staging}:gnode:unified:*"
ACL_CMD="$ACL_CMD &{acceptance}:gnode:broadcast:*"
ACL_CMD="$ACL_CMD &{acceptance}:gnode:unified:*"
ACL_CMD="$ACL_CMD &{production}:gnode:broadcast:*"
ACL_CMD="$ACL_CMD &{production}:gnode:unified:*"
ACL_CMD="$ACL_CMD -@all"
ACL_CMD="$ACL_CMD +xread +xreadgroup +xadd +xack +xclaim +xpending +xinfo +xlen +xtrim +xrange +xrevrange +xgroup +xdel"
ACL_CMD="$ACL_CMD +fcall +fcall_ro"
ACL_CMD="$ACL_CMD +get +set +setex +setnx +del +exists +ttl +expire +mget +mset"
ACL_CMD="$ACL_CMD +incr +decr +incrby +decrby"
ACL_CMD="$ACL_CMD +hget +hset +hgetall +hdel +hexists +hkeys +hvals +hincrby +hmget +hmset"
ACL_CMD="$ACL_CMD +sadd +smembers +sismember +srem +scard"
ACL_CMD="$ACL_CMD +lpush +rpush +lpop +rpop +lrange +llen +lindex +ltrim"
ACL_CMD="$ACL_CMD +zadd +zrange +zrevrange +zrem +zscore +zcard"
ACL_CMD="$ACL_CMD +keys +scan +ping +publish +auth +select +info +client +multi +exec +discard +time +type +object"

# Execute ACL command
REDISCLI_AUTH="$DAEMON_PASSWORD" valkey-cli -h $VALKEY_HOST -p $VALKEY_PORT --user gnode_daemon --no-auth-warning $ACL_CMD

if [ $? -eq 0 ]; then
    echo -e "${GREEN}ValKey ACL user created successfully${NC}"
else
    echo -e "${RED}Error creating ValKey ACL user${NC}"
    exit 1
fi

# Save ACL to disk
echo -e "${GREEN}Saving ACL configuration...${NC}"
REDISCLI_AUTH="$DAEMON_PASSWORD" valkey-cli -h $VALKEY_HOST -p $VALKEY_PORT --user gnode_daemon --no-auth-warning ACL SAVE

if [ $? -eq 0 ]; then
    echo -e "${GREEN}ACL saved to disk${NC}"
else
    echo -e "${YELLOW}Warning: Could not save ACL to disk (may require different permissions)${NC}"
fi

# Verify the user was created
echo -e "${GREEN}Verifying user creation...${NC}"
REDISCLI_AUTH="$DAEMON_PASSWORD" valkey-cli -h $VALKEY_HOST -p $VALKEY_PORT --user gnode_daemon --no-auth-warning ACL LIST | grep "$USER_NAME" > /dev/null

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ User $USER_NAME verified in ACL${NC}"
else
    echo -e "${RED}✗ User not found in ACL${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}=== Site Provisioning Complete ===${NC}"
echo ""
echo "Next steps:"
echo "1. Configure the site's .env or WordPress to use site_id: $SITE_ID"
echo "2. The gNode-Client will auto-discover the password from: $PASSWORD_FILE"
echo "3. On first request, the site will register with gNode automatically"
echo ""
echo "Test connection:"
echo "  REDISCLI_AUTH=\$(cat $PASSWORD_FILE) valkey-cli -h $VALKEY_HOST -p $VALKEY_PORT --user $USER_NAME PING"
