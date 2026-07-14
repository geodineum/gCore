#!/bin/bash
#
# Seed gCore manager defaults into ValKey.
#
# Reads config/manager-defaults.json and calls
# FCALL GCORE_MGR_CONFIG_SEED for each manager entry.
#
# Idempotent: uses NX mode (default) — existing values are preserved.
# Re-running on an already-seeded install changes nothing.
#
# Prerequisites:
#   - ValKey running and accessible
#   - gnode_gcore_config.lua loaded (GCORE_MGR_CONFIG_SEED available)
#   - jq installed (for JSON parsing)
#
# Usage:
#   # Fresh install (NX mode — skip existing values):
#   bash /opt/geodineum/gCore/scripts/seed-manager-defaults.sh
#
#   # Force re-seed (OVERWRITE mode — reset to defaults):
#   SEED_MODE=OVERWRITE bash /opt/geodineum/gCore/scripts/seed-manager-defaults.sh
#
#   # Custom ValKey connection:
#   VALKEY_PORT=47445 VALKEY_USER=admin VALKEY_PASSWORD_FILE=/etc/geodineum/credentials/valkey_admin.password \
#     bash /opt/geodineum/gCore/scripts/seed-manager-defaults.sh
#

set -euo pipefail

# ── Configuration ───────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GCORE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
DEFAULTS_FILE="${DEFAULTS_FILE:-${GCORE_DIR}/config/manager-defaults.json}"
SEED_MODE="${SEED_MODE:-NX}"

# ValKey connection
VALKEY_HOST="${VALKEY_HOST:-127.0.0.1}"
VALKEY_PORT="${VALKEY_PORT:-47445}"
VALKEY_USER="${VALKEY_USER:-}"
VALKEY_PASSWORD="${VALKEY_PASSWORD:-}"
VALKEY_PASSWORD_FILE="${VALKEY_PASSWORD_FILE:-}"

# Resolve password from file — try multiple locations
if [[ -n "$VALKEY_PASSWORD_FILE" ]] && [[ -r "$VALKEY_PASSWORD_FILE" ]]; then
    VALKEY_PASSWORD="$(cat "$VALKEY_PASSWORD_FILE")"
elif [[ -z "$VALKEY_PASSWORD" ]]; then
    # Auto-discover password from standard locations
    for candidate in \
        /etc/geodineum/credentials/valkey_admin.password \
        /etc/geodineum/credentials/valkey.password; do
        if [[ -r "$candidate" ]]; then
            VALKEY_PASSWORD="$(cat "$candidate")"
            # valkey.password = default user (no --user flag)
            # valkey_admin.password = admin user
            if [[ "$(basename "$candidate")" == "valkey.password" ]]; then
                VALKEY_USER=""
            fi
            break
        fi
    done
fi

# Build redis-cli args — only use --user if VALKEY_USER is explicitly set
# (fresh installs use the default user with requirepass, not an admin ACL user)
CLI_ARGS=(-p "$VALKEY_PORT" -h "$VALKEY_HOST" --no-auth-warning)
if [[ -n "$VALKEY_USER" ]] && [[ -n "$VALKEY_PASSWORD" ]]; then
    CLI_ARGS+=(--user "$VALKEY_USER" -a "$VALKEY_PASSWORD")
elif [[ -n "$VALKEY_PASSWORD" ]]; then
    CLI_ARGS+=(-a "$VALKEY_PASSWORD")
fi

# ── Validation ──────────────────────────────────────────────

if [[ ! -f "$DEFAULTS_FILE" ]]; then
    echo "ERROR: defaults file not found: $DEFAULTS_FILE" >&2
    exit 1
fi

if ! command -v jq &>/dev/null; then
    echo "ERROR: jq is required but not installed. Install with: apt install jq" >&2
    exit 1
fi

if ! command -v redis-cli &>/dev/null; then
    echo "ERROR: redis-cli (valkey-cli) not found" >&2
    exit 1
fi

# Verify FCALL surface is loaded
if ! redis-cli "${CLI_ARGS[@]}" FCALL GCORE_MGR_CONFIG_VERSION 0 _probe _probe &>/dev/null; then
    echo "ERROR: GCORE_MGR_CONFIG_VERSION FCALL not available." >&2
    echo "       Ensure gnode_gcore_config.lua is loaded (phase_functions must run first)." >&2
    exit 1
fi

# ── Seed ────────────────────────────────────────────────────

echo "=== gCore Manager Config Bootloader ==="
echo "  Defaults file: $DEFAULTS_FILE"
echo "  ValKey: ${VALKEY_HOST}:${VALKEY_PORT} (user: ${VALKEY_USER:-default})"
echo "  Seed mode: $SEED_MODE"
echo

# Read manager names (skip _meta key)
MANAGERS=$(jq -r 'keys[] | select(. != "_meta")' "$DEFAULTS_FILE")

total=0
seeded=0
skipped=0

for manager in $MANAGERS; do
    total=$((total + 1))

    # Extract manager's defaults as a compact JSON object
    manager_json=$(jq -c ".\"$manager\"" "$DEFAULTS_FILE")

    # Call FCALL GCORE_MGR_CONFIG_SEED 0 default <Manager> <json> <mode>
    result=$(redis-cli "${CLI_ARGS[@]}" FCALL GCORE_MGR_CONFIG_SEED 0 \
        "default" "$manager" "$manager_json" "$SEED_MODE" 2>&1)

    if [[ "$result" =~ ^[0-9]+$ ]]; then
        if [[ "$result" -gt 0 ]]; then
            echo "  ✓ $manager: seeded $result key(s)"
            seeded=$((seeded + 1))
        else
            echo "  · $manager: already seeded (0 new keys)"
            skipped=$((skipped + 1))
        fi
    else
        echo "  ✗ $manager: FCALL error: $result" >&2
    fi
done

echo
echo "Done: $total managers checked, $seeded newly seeded, $skipped already present."
echo
echo "Verify with:"
echo "  redis-cli ${CLI_ARGS[*]} FCALL GCORE_MGR_CONFIG_HGETALL 0 default CacheManager"
