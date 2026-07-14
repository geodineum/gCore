#!/bin/bash
# gCore Auto-Deploy - Pulls from main every 10 minutes via cron
# Log: /opt/geodineum/gCore/logs/deploy.log (capped at 1000 lines)
#
# Install: Add to crontab:
#   */10 * * * * /opt/geodineum/gCore/scripts/auto-deploy.sh
#
# Orchestra integration: Works alongside gNode auto-deploy
# - gNode handles daemon/Lua updates
# - gCore handles PHP framework updates
# - Each component manages its own lifecycle

set -uo pipefail

GCORE_DIR="/opt/geodineum/gCore"
LOG_DIR="$GCORE_DIR/logs"
LOG_FILE="$LOG_DIR/deploy.log"
MAX_LOG_ENTRIES=1000
BRANCH="main"
PAT="${GITHUB_PAT:-}"
if [ -z "$PAT" ]; then
    log "ERROR GITHUB_PAT environment variable not set"
    exit 1
fi
REMOTE_URL="https://${PAT}@github.com/geodineum/gCore.git"

# Ensure log directory exists
mkdir -p "$LOG_DIR"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
    # Cap log file
    local lc=$(wc -l < "$LOG_FILE" 2>/dev/null || echo 0)
    [ "$lc" -gt "$MAX_LOG_ENTRIES" ] && tail -n "$MAX_LOG_ENTRIES" "$LOG_FILE" > "${LOG_FILE}.tmp" && mv "${LOG_FILE}.tmp" "$LOG_FILE"
}

cd "$GCORE_DIR" || { log "ERROR dir-not-found"; exit 1; }
git remote set-url origin "$REMOTE_URL" 2>/dev/null || true
git fetch origin "$BRANCH" 2>/dev/null || { log "ERROR fetch-failed"; exit 1; }

LOCAL=$(git rev-parse --short HEAD)
REMOTE=$(git rev-parse --short "origin/$BRANCH")

[ "$LOCAL" = "$REMOTE" ] && exit 0  # No changes, silent exit

CHANGED=$(git diff --name-only HEAD "origin/$BRANCH")
COUNT=$(git rev-list --count HEAD.."origin/$BRANCH")

if git pull origin "$BRANCH" 2>/dev/null; then
    log "PULL $LOCAL→$REMOTE ($COUNT commits)"

    # Track what actions were taken
    ACTIONS=""

    # Clear OPcache if PHP files changed (improves performance, prevents stale code)
    if echo "$CHANGED" | grep -qE '\.php$'; then
        if php -r "opcache_reset();" 2>/dev/null; then
            ACTIONS="${ACTIONS}opcache-cleared "
        fi
    fi

    # Restart PHP-FPM if core framework files changed
    if echo "$CHANGED" | grep -qE '^(Modules/Core/|bootstrap\.php|gcore-)'; then
        if systemctl restart php8.3-fpm 2>/dev/null; then
            ACTIONS="${ACTIONS}php-fpm-restarted "
        else
            log "WARN php-fpm-restart-failed"
        fi
    fi

    # Fix permissions if new PHP files added
    if echo "$CHANGED" | grep -qE '\.php$'; then
        find "$GCORE_DIR" -type f -name "*.php" -newer "$LOG_FILE" -exec chmod 640 {} \; 2>/dev/null
        find "$GCORE_DIR" -type f -name "*.php" -newer "$LOG_FILE" -exec chown august:www-data {} \; 2>/dev/null
    fi

    # Restore execute permissions on scripts if changed
    if echo "$CHANGED" | grep -qE '\.(sh|bash)$'; then
        find "$GCORE_DIR" -type f \( -name "*.sh" -o -name "*.bash" \) -exec chmod +x {} \; 2>/dev/null
        ACTIONS="${ACTIONS}scripts-chmod "
    fi

    # Log actions taken
    [ -n "$ACTIONS" ] && log "ACTIONS $ACTIONS"

    # Recompile config if YAML source files changed
    if echo "$CHANGED" | grep -qE '^config/(default|services)\.(yaml|yml)$'; then
        if php "$GCORE_DIR/scripts/compile-config.php" >> "$LOG_FILE" 2>&1; then
            ACTIONS="${ACTIONS}config-compiled "
        else
            log "WARN config-compile-failed"
        fi
    fi

    # Notify about manual actions that might be needed
    echo "$CHANGED" | grep -q "^config/" && log "NOTICE config-changed-review-recommended"
    echo "$CHANGED" | grep -q "install\.sh" && log "NOTICE installer-updated"
    echo "$CHANGED" | grep -q "\.service$" && log "NOTICE service-file-changed"

else
    log "ERROR pull-failed $LOCAL→$REMOTE"
    exit 1
fi
