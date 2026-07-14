<?php
/**
 * Geodineum Ecosystem Bootstrap Loader (PHP)
 * ==========================================
 *
 * Single-route config loader. Mirrors `lib/bootstrap-loader.sh` (Bash) and
 * `daemon/src/ecosystem_config.rs` (Rust). See REMEDIATION_PLAN_DEEP.md
 * commit 0.1 for the model.
 *
 * Tier 1 (disk):   /etc/geodineum/bootstrap.env  root:geodineum-bootstrap 0640
 *                  (a narrow group of legitimate readers: www-data, gnode,
 *                  deploy user, service users). Mode 0600 also accepted (root-only).
 *                  Strict-deny posture: world-readable / group-writable
 *                  REJECTED. Exactly 3 whitelisted keys: VALKEY_HOST,
 *                  VALKEY_PORT, VALKEY_CREDS_PATH. Strict regex parse.
 *
 * Tier 2 (ValKey): geodineum:bootstrap:<KEY>  +  geodineum:bootstrap:_index
 *
 * Public API:
 *   \gCore\Bootstrap\load_ecosystem_config(): void
 *   \gCore\Bootstrap\load_bootstrap_disk_tier(): void
 *   \gCore\Bootstrap\load_bootstrap_valkey_tier(): void
 *
 * All three throw \RuntimeException on any failure (no silent fallback).
 * Callers populate `$_ENV` / `getenv()` via `putenv()`.
 *
 * This file is `require_once`'d from `gCore/bootstrap.php` BEFORE the
 * Composer autoloader runs, so it cannot rely on PSR-4 autoloading and
 * uses functions (not classes) for direct call.
 */

declare(strict_types=1);

namespace gCore\Bootstrap;

use RuntimeException;

const BOOTSTRAP_DEFAULT_FILE = '/etc/geodineum/bootstrap.env';
const BOOTSTRAP_DISK_KEYS    = ['VALKEY_HOST', 'VALKEY_PORT', 'VALKEY_CREDS_PATH'];
const BOOTSTRAP_VK_PREFIX    = 'geodineum:bootstrap:';
const BOOTSTRAP_VK_INDEX     = 'geodineum:bootstrap:_index';

/**
 * One-call wrapper: disk tier then ValKey tier. The canonical entry point.
 *
 * @throws RuntimeException
 */
function load_ecosystem_config(): void
{
    load_bootstrap_disk_tier();
    load_bootstrap_valkey_tier();
}

/**
 * Verify ownership/mode of the disk file, parse exactly the whitelisted
 * KEY=value lines, putenv() each. Fail-fast on any drift.
 *
 * @throws RuntimeException
 */
function load_bootstrap_disk_tier(): void
{
    $file = getenv('GEODINEUM_BOOTSTRAP_FILE') ?: BOOTSTRAP_DEFAULT_FILE;

    if (!is_file($file)) {
        throw new RuntimeException("bootstrap-loader: $file missing — installer not run?");
    }

    // Ownership + mode check (skip in dev with GEODINEUM_BOOTSTRAP_DEV=1).
    if (getenv('GEODINEUM_BOOTSTRAP_DEV') !== '1') {
        clearstatcache(true, $file);
        $stat = @stat($file);
        if ($stat === false) {
            throw new RuntimeException("bootstrap-loader: stat failed on $file");
        }
        // Owner MUST be root. Group identity is install-defined (canonically
        // `geodineum-bootstrap`, a narrow group containing only the legitimate
        // readers); we don't hardcode the gid here because the PHP process's
        // own user joins that group at install time, and if the group is
        // wrong, file_get_contents() below will fail with permission denied
        // and produce a clearer error than a hardcoded gid comparison.
        if ($stat['uid'] !== 0) {
            throw new RuntimeException(
                "bootstrap-loader: $file ownership drift " .
                "(got uid={$stat['uid']}, want 0; " .
                "owner must be root, group must be geodineum-bootstrap)"
            );
        }
        // Strict-deny mode policy (operator security stance, 2026-06-03):
        // bootstrap.env never world-readable, never group-writable.
        // Accept exactly 0640 (root:geodineum-bootstrap rw-r-----) or
        // 0600 (root-only rw-------). Reject everything else, including the
        // legacy 0644 that exposed deployment topology to "others".
        $mode = $stat['mode'] & 0777;
        if ($mode !== 0640 && $mode !== 0600) {
            throw new RuntimeException(sprintf(
                "bootstrap-loader: %s mode drift (got %o, want 0640 or 0600 " .
                "— strict-deny on world-readable / group-writable)",
                $file,
                $mode
            ));
        }
    }

    $content = @file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException("bootstrap-loader: cannot read $file");
    }

    // Reset disk-tier vars so a partial parse can't inherit stale values.
    foreach (BOOTSTRAP_DISK_KEYS as $key) {
        putenv($key);
        unset($_ENV[$key]);
    }

    $parsed = [];
    $lines = preg_split("/\r?\n/", $content);
    foreach ($lines as $idx => $raw) {
        $line = trim($raw);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Strict shape: KEY=value. Value: no whitespace, no shell metacharacters.
        if (!preg_match(
            '/^([A-Z_][A-Z0-9_]*)=([^[:space:]"\'$`\\\\]+)$/',
            $line,
            $m
        )) {
            $lineno = $idx + 1;
            throw new RuntimeException(
                "bootstrap-loader: $file line $lineno rejected (bad shape): $raw"
            );
        }
        $k = $m[1];
        $v = $m[2];

        if (!in_array($k, BOOTSTRAP_DISK_KEYS, true)) {
            $lineno = $idx + 1;
            throw new RuntimeException(
                "bootstrap-loader: $file line $lineno rejected (key '$k' not whitelisted)"
            );
        }
        $parsed[$k] = $v;
    }

    foreach (BOOTSTRAP_DISK_KEYS as $key) {
        if (!isset($parsed[$key])) {
            throw new RuntimeException(
                "bootstrap-loader: $file missing required key: $key"
            );
        }
    }

    if (!preg_match('/^[1-9][0-9]*$/', $parsed['VALKEY_PORT'])) {
        throw new RuntimeException(
            "bootstrap-loader: VALKEY_PORT not a positive integer: {$parsed['VALKEY_PORT']}"
        );
    }

    foreach ($parsed as $k => $v) {
        putenv("$k=$v");
        $_ENV[$k] = $v;
    }
}

/**
 * Iterate geodineum:bootstrap:_index, GET each key, putenv() under bare name.
 * Assumes load_bootstrap_disk_tier already ran.
 *
 * Empty index (first-boot tolerance) is silently accepted. Any other failure
 * raises — no silent fallback.
 *
 * @throws RuntimeException
 */
function load_bootstrap_valkey_tier(): void
{
    $host = getenv('VALKEY_HOST');
    $port = (int) (getenv('VALKEY_PORT') ?: 0);
    $creds = getenv('VALKEY_CREDS_PATH');
    if ($host === false || $port === 0 || $creds === false) {
        throw new RuntimeException(
            "bootstrap-loader: load_bootstrap_disk_tier must run before load_bootstrap_valkey_tier"
        );
    }

    if (!class_exists('Redis', false)) {
        throw new RuntimeException(
            "bootstrap-loader: ext-redis not loaded; cannot read ValKey tier"
        );
    }

    $pwfile = __resolve_password_file($creds);
    if ($pwfile === null) {
        throw new RuntimeException(
            "bootstrap-loader: no readable ValKey password under $creds " .
            "(set VALKEY_PASSWORD_FILE, or run as a user with creds access)"
        );
    }
    $password = @file_get_contents($pwfile);
    if ($password === false) {
        throw new RuntimeException("bootstrap-loader: cannot read $pwfile");
    }
    $password = rtrim($password, "\r\n");

    $r = new \Redis();
    try {
        if (!$r->connect($host, $port, 2.0)) {
            throw new RuntimeException(
                "bootstrap-loader: ValKey unreachable at $host:$port"
            );
        }
        // User resolution — priority order:
        // 1. VALKEY_USER env var (set by Apache vhost SetEnv via gTemplate
        //      — explicit per-site config beats heuristic). Wins for site
        //      contexts where the MU plugin should auth as that site's client.
        //   2. valkey_daemon.password → auth as gnode_daemon (daemon context).
        //   3. valkey_client_<site>.password basename → auth as
        //      gnode_client_<site>. Handles installs where VALKEY_USER isn't
        //      provided via env (legacy / CLI invocations / standalone PHP).
        //   4. Bare auth (default user) — admin/recovery context.
        //
        // Pre-fix: only paths 2 and 4 existed. A per-site client password
        // file at $pwfile triggered path 4, which sent the client password
        // to the 'default' user — WRONGPASS, gCore couldn't bootstrap, and
        // gTemplate then fataled on missing gCore classes.
        $authBasename = basename($pwfile);
        $envUser      = getenv('VALKEY_USER');
        $authUser     = null;
        if (is_string($envUser) && $envUser !== '') {
            $authUser = $envUser;
        } elseif ($authBasename === 'valkey_daemon.password') {
            $authUser = 'gnode_daemon';
        } elseif (preg_match('/^valkey_client_(.+)\.password$/', $authBasename, $m)) {
            $authUser = 'gnode_client_' . $m[1];
        }

        if ($authUser !== null) {
            $ok = $r->auth([$authUser, $password]);
        } else {
            $ok = $r->auth($password);
        }

        if (!$ok) {
            throw new RuntimeException(
                "bootstrap-loader: ValKey auth failed (user: " .
                ($authUser ?? 'default') .
                ", creds: $authBasename)"
            );
        }

        // Least-privilege boundary: a per-site web client (gnode_client_<site>)
        // is scoped to its OWN keyspace and MUST NOT read the GLOBAL operator
        // keyspace geodineum:bootstrap:* — that is daemon/admin/operator config,
        // not the site's. Its ACL correctly denies it, so SMEMBERS would NOPERM.
        // The global ValKey tier is simply not this context's to read: the site
        // already has VALKEY_HOST/PORT/CREDS_PATH from the disk tier
        // (bootstrap.env) and gets app config from its own {site}:config:*.
        // Skip cleanly — no error, no per-request "ecosystem_config unavailable"
        // noise — while www-data never gains a byte of global-config access.
        // Daemon/admin/default contexts (which DO hold the grant) proceed below,
        // so a genuine NOPERM there still surfaces as a real error.
        if (is_string($authUser) && strpos($authUser, 'gnode_client_') === 0) {
            return;
        }

        $members = $r->sMembers(BOOTSTRAP_VK_INDEX);
        if ($members === false) {
            throw new RuntimeException(
                "bootstrap-loader: SMEMBERS " . BOOTSTRAP_VK_INDEX . " failed"
            );
        }
        if (empty($members)) {
            // First-boot tolerance: index empty means installer hasn't populated yet.
            return;
        }

        foreach ($members as $key) {
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', (string) $key)) {
                throw new RuntimeException(
                    "bootstrap-loader: indexed key name rejected: $key"
                );
            }
            $value = $r->get(BOOTSTRAP_VK_PREFIX . $key);
            if ($value === false) {
                throw new RuntimeException(
                    "bootstrap-loader: GET " . BOOTSTRAP_VK_PREFIX . "$key failed"
                );
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    } finally {
        try { $r->close(); } catch (\Throwable $_) { /* ignore */ }
    }
}

/**
 * Internal: resolve a readable ValKey password file path.
 *
 * Priority:
 *   1. VALKEY_PASSWORD_FILE env override (explicit per-context override).
 *   2. Derive from VALKEY_USER: if the vhost SetEnv'd
 *      `VALKEY_USER=gnode_client_<site>`, compute the cred path
 *      `valkey_client_<site>.password` and try it directly. No glob
 *      needed — works regardless of credentials-dir read perms.
 *      Required for the least-privilege model where www-data has no
 *      `geodineum` group membership and therefore can't read the
 *      credentials directory listing.
 *   3. Daemon credential (gnode_daemon context).
 *   4. Admin credential (recovery/default-user context).
 *   5. Per-site client credential discovered by glob — first readable wins.
 *      Falls through only when steps 1–4 yield nothing AND the PHP
 *      process can actually list `$credsDir`.
 */
function __resolve_password_file(string $credsDir): ?string
{
    $override = getenv('VALKEY_PASSWORD_FILE');
    if (is_string($override) && $override !== '' && is_readable($override)) {
        return $override;
    }
    // Derive from VALKEY_USER (set by vhost SetEnv) before glob.
    // `gnode_client_example_com` ⇒ `valkey_client_example_com.password`.
    // Bypasses glob() entirely so per-site Apache contexts work even when
    // they have only execute (--x) on the credentials directory — the
    // least-privilege model has www-data NOT in the `geodineum` group, so
    // glob() returns empty for that user and the legacy 4th-priority path
    // never finds anything.
    $vkUser = getenv('VALKEY_USER');
    if (is_string($vkUser) && $vkUser !== ''
        && preg_match('/^gnode_client_(.+)$/', $vkUser, $m)) {
        $path = "$credsDir/valkey_client_{$m[1]}.password";
        if (is_readable($path)) {
            return $path;
        }
    }
    // SERVER_NAME-derived fallback for the common Apache-vhost case
    // where no SetEnv VALKEY_USER is configured but the cred file follows
    // the canonical pattern. `example.com` ⇒ `example_com` ⇒
    // `valkey_client_example_com.password`. Domain dots/dashes become
    // underscores. Sites whose site_id doesn't match their domain (e.g. the
    // wp-config domain normalises to one id but the cred uses another) won't
    // be helped here — they need an explicit VALKEY_USER SetEnv.
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    if (is_string($serverName) && $serverName !== '') {
        $siteId = preg_replace('/[^a-z0-9_]/i', '_', strtolower($serverName));
        $siteId = preg_replace('/_+/', '_', $siteId);
        $siteId = trim($siteId, '_');
        if ($siteId !== '') {
            $path = "$credsDir/valkey_client_{$siteId}.password";
            if (is_readable($path)) {
                return $path;
            }
        }
    }
    foreach (['valkey_daemon.password', 'valkey.password'] as $name) {
        $path = "$credsDir/$name";
        if (is_readable($path)) {
            return $path;
        }
    }
    // Fall through: look for any readable valkey_client_*.password.
    $clientFiles = @glob("$credsDir/valkey_client_*.password");
    if (is_array($clientFiles)) {
        foreach ($clientFiles as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
    }
    return null;
}
