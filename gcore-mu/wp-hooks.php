<?php
/**
 * gCore WordPress Hooks
 *
 * WordPress-specific hook registrations for gCore framework.
 * This file is loaded by gcore-loader.php after the framework bootstrap.
 *
 * Features:
 * - gNode status monitoring with heartbeat integration
 * - Admin dashboard with real-time status
 * - Translation meta boxes for posts/pages
 * - REST API endpoints for status checks
 *
 * @package gCore
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// ONE-TIME ADMIN LOGIN LINK
// ============================================================================
// Redeems a server-minted magic-login token (`?gcore_admin_login=<hex64>`) and
// logs the bound admin in — non-disruptive operator access that needs no
// password change and bypasses the .htaccess wp-login secret-key gate. Tokens
// are minted ONLY via the CLI (`geodineum wp <site> admin login-link`), which
// requires server/sudo access; they are 256-bit, single-use, 5-minute TTL, and
// stored hashed (the DB never holds the raw token). Priority 0 so it redeems
// before the gTemplate environment gate (init priority 1) can intercept.
add_action('init', function (): void {
    if (empty($_GET['gcore_admin_login']) || !is_string($_GET['gcore_admin_login'])) {
        return;
    }
    $token = (string) $_GET['gcore_admin_login'];
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        return; // malformed — ignore silently
    }
    $key = 'gcore_login_' . hash('sha256', $token);
    $uid = get_transient($key);
    delete_transient($key); // single-use: consume regardless of outcome
    if ($uid && ($user = get_user_by('id', (int) $uid)) && user_can($user, 'manage_options')) {
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        wp_safe_redirect(admin_url());
        exit;
    }
    wp_safe_redirect(home_url('/?gcore_login=expired'));
    exit;
}, 0);

// ============================================================================
// SERVICE INITIALIZATION
// ============================================================================

// (Previously this file initialized a `TemplateLibrary` service from
// `Modules/Managers/Base/TemplateLibrary/TemplateLibrary.php`. That file
// only exists under `.archive/`, so the init block was a no-op every
// request — class_exists returned false, the service never instantiated,
// and downstream gtemplate_* helpers fell through to their wp_*
// fallbacks. Removed in ROADMAP §B.5 closure; CSRF token generation
// now routes through SecurityManager directly. If TemplateLibrary
// returns from `.archive/` it should ship with its own loader rather
// than relying on a wp-hooks.php no-op.)

// ============================================================================
// ADMIN DATA-PLANE ACCESSORS (the canonical wp-admin → constellation surface)
// ============================================================================
//
// gCore is FRONTEND-ONLY-INITIALIZED — in wp-admin the full framework + its
// frontend gNode client are absent. Any admin page that needs the constellation
// (status, comms, cache, schemas, topology, …) MUST go through these THREE
// shared accessors instead of reaching for a frontend global (the recurring
// "X may not be initialized" bug). They are loaded on every site via the gCore
// mu-plugin, so other components (e.g. gTemplate) call them too:
//
//   gcore_admin_site_id()          → the per-site identity (one resolver)
//   gcore_get_admin_gnode_client() → lightweight gNodeClient::forSite (FCALL surface)
//   gcore_admin_site_redis()       → raw \Redis as this site's ACL user (raw cmds)

/**
 * Canonical wp-admin site-id resolver — the single source of truth every admin
 * accessor shares: registration config first, then the WP site domain,
 * sanitised to [a-z0-9_]. Cached per request.
 *
 * @return string|null
 */
function gcore_admin_site_id(): ?string {
    static $sid = false; // false = unresolved (null is a valid "unknown" result)
    if ($sid !== false) {
        return $sid;
    }
    $sid = null;

    foreach ([
        ABSPATH . '/wp-config-geodineum.yaml',
        get_template_directory() . '/registration.yaml',
        get_template_directory() . '/registration.local.yaml',
    ] as $configFile) {
        if (is_readable($configFile)) {
            $yaml = (string) @file_get_contents($configFile);
            if (preg_match('/^site_id:\s*["\']?([a-z0-9_]+)["\']?/m', $yaml, $m)) {
                $sid = $m[1];
                return $sid;
            }
        }
    }

    // Fallback: derive from the site domain.
    $domain = (string) (parse_url(get_site_url(), PHP_URL_HOST) ?: '');
    if ($domain !== '') {
        $sid = trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9_]/i', '_', strtolower($domain))), '_');
    }
    return $sid;
}

/**
 * Canonical lightweight gNode-Client for admin context. Builds a
 * gNodeClient::forSite directly (no full gCore init) bound to this site's
 * ACL user. THE accessor every wp-admin page (gCore or gTemplate) should use
 * for the FCALL/cache/topology surface.
 *
 * @return \gCore\gNode\gNodeClient|null
 */
function gcore_get_admin_gnode_client() {
    static $adminGNodeClient = null;

    if ($adminGNodeClient !== null) {
        return $adminGNodeClient;
    }

    try {
        $siteId = gcore_admin_site_id() ?: 'localhost';

        // Detect environment from WP_ENVIRONMENT_TYPE (local/dev → testing).
        $environment = defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production';
        if ($environment === 'local' || $environment === 'development') {
            $environment = 'testing';
        }

        $gNodeClientClass = '\\gCore\\gNode\\gNodeClient';
        if (class_exists($gNodeClientClass)) {
            $adminGNodeClient = $gNodeClientClass::forSite($siteId, $environment);
            return $adminGNodeClient;
        }
    } catch (Throwable $e) {
        error_log('[gCore Admin] Failed to create gNode client: ' . $e->getMessage());
    }

    return null;
}

/**
 * Raw \Redis authed as THIS site's own ValKey user (gnode_client_<site_id>),
 * reading the per-site credential — the same identity the Status page uses via
 * the gNode-Client. Admin pages needing raw commands (HGETALL on a global key,
 * SCAN) use this instead of a separate operator token. Cross-cutting/cross-site
 * keys (geodineum:config_schema:*, other sites' keyspaces) are deliberately OUT
 * of this scope; callers MUST degrade gracefully on NOPERM. The cross-site
 * operator console is the standalone gDash with its own broad-read user.
 */
function gcore_admin_site_redis(): ?\Redis {
    if (!class_exists('\Redis', false) && !extension_loaded('redis')) {
        return null;
    }
    // Shared canonical resolver (same identity the gNode client binds to).
    $siteId = gcore_admin_site_id();
    if (!$siteId) {
        return null;
    }
    $credFile = '/etc/geodineum/credentials/valkey_client_' . $siteId . '.password';
    if (!is_readable($credFile)) {
        return null;
    }
    $auth = trim((string) @file_get_contents($credFile));
    if ($auth === '') {
        return null;
    }
    $host = (string) (getenv('VALKEY_HOST') ?: '127.0.0.1');
    $port = (int)    (getenv('VALKEY_PORT') ?: 47445);
    $r = new \Redis();
    if (!@$r->connect($host, $port, 1.0)) {
        return null;
    }
    try {
        $r->auth(['gnode_client_' . $siteId, $auth]);
    } catch (Throwable $e) {
        try { $r->close(); } catch (Throwable $_) { /* noop */ }
        return null;
    }
    return $r;
}

/**
 * Authoritative DTAP environment for this site, read from ValKey.
 *
 * `geodineum env set` writes gnode:site:{site}:meta field active_environment.
 * This reads it back through the site's own ACL-scoped connection (the ~gnode:*
 * grant covers gnode:site:*:meta), so the runtime env follows the switch
 * instantly — no config-file edit, no cache dance, no wp-config/.geodineum
 * two-file drift. THE single source of truth env consumers should prefer.
 *
 * Returns null when the key is unset or unreadable, so callers fall back to the
 * config file. Only a successful, valid read is memoised (a null is retried on a
 * later call, once gCore/ValKey is reachable).
 *
 * @return string|null Lowercased DTAP environment, or null.
 */
function gcore_site_active_environment(): ?string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $siteId = function_exists('gcore_admin_site_id') ? gcore_admin_site_id() : null;
    $r = $siteId ? gcore_admin_site_redis() : null;
    if ($r) {
        try {
            $env = $r->hGet('gnode:site:' . $siteId . ':meta', 'active_environment');
            $env = is_string($env) ? strtolower(trim($env)) : '';
            $valid = ['testing', 'staging', 'acceptance', 'production', 'development', 'local'];
            if (in_array($env, $valid, true)) {
                $resolved = $env;
                return $resolved;
            }
        } catch (\Throwable $e) {
            // fall through to null → caller uses the config-file fallback
        }
    }
    return null;
}

/**
 * Fetch topology services from gNode
 *
 * Queries gNode directly for registered services, grouped by tier.
 * Works in admin context without requiring full gCore initialization.
 *
 * @return array{services: array, error: ?string}
 */
/**
 * Classify the live topology into Sites vs Services.
 *
 * The ecosystem invariant: every service exposed to the internet IS a site;
 * a service with no public surface (Geodineum-COMMS, -BAK, the daemon, …) is
 * just a service. Web profiles register with metadata.type === 'web'.
 */
function gcore_topology_entry_is_site(array $metadata): bool {
    $type = strtolower((string) ($metadata['type'] ?? ''));
    return $type === 'web'
        || $type === 'site'
        || !empty($metadata['url'])
        || !empty($metadata['domain']);
}

function gcore_fetch_topology_services(): array {
    $result = [
        'services' => [],
        'by_class' => [
            'sites'    => [],   // internet-exposed services
            'services' => [],   // internal services (no public surface)
        ],
        'counts' => ['sites' => 0, 'services' => 0],
        'error' => null,
    ];

    $gNodeClient = gcore_get_admin_gnode_client();
    if (!$gNodeClient) {
        $result['error'] = 'gNode client not available';
        return $result;
    }

    try {
        $topology = $gNodeClient->getTopology();

        if (empty($topology) || !isset($topology['services'])) {
            $result['error'] = 'No services in topology';
            return $result;
        }

        foreach ($topology['services'] as $serviceId => $service) {
            $metadata = $service['metadata'] ?? [];
            $class = gcore_topology_entry_is_site($metadata) ? 'sites' : 'services';

            $serviceInfo = [
                'id' => $serviceId,
                'type' => $metadata['type'] ?? 'unknown',
                'tier' => strtolower((string) ($metadata['tier'] ?? '')),
                'description' => $metadata['description'] ?? '',
                'class' => $class,
            ];

            $result['services'][$serviceId] = $serviceInfo;
            $result['by_class'][$class][] = $serviceInfo;
            $result['counts'][$class]++;
        }

    } catch (Throwable $e) {
        $result['error'] = 'Failed to fetch topology: ' . $e->getMessage();
    }

    return $result;
}

/**
 * Read this site's visitor analytics from the beacon-fed aggregates via the
 * GNODE_ANALYTICS_SUMMARY Lua function (one no-writes FCALL over the last 7
 * UTC days). This surfaces real page views — pages served, avg pages/visitor,
 * top pages, visitor paths, external referrers, human-vs-bot — not raw FCALL
 * counts. Read-only, via the per-site admin accessor; degrades silently if the
 * function or keys are out of ACL scope rather than erroring the dashboard.
 */
function gcore_fetch_visitor_analytics(): array {
    $out = [
        'available'       => false,
        'pages_served'    => null,
        'unique_visitors' => null,
        'avg_pages'       => null,
        'human_hits'      => null,
        'bot_hits'        => null,
        'top_pages'       => [],
        'top_referrers'   => [],
        'top_paths'       => [],
        'daily'           => [],
        'error'           => null,
    ];

    $redis = gcore_admin_site_redis();
    $site  = gcore_admin_site_id();
    if (!$redis || !$site) {
        $out['error'] = 'metrics backend unavailable';
        return $out;
    }

    // Match the beacon: UTC Ymd, most-recent day first.
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = gmdate('Ymd', time() - $i * 86400);
    }

    try {
        $raw = $redis->fcall('GNODE_ANALYTICS_SUMMARY', [], array_merge([$site], $days));
        $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (is_array($data)) {
            $out['pages_served']    = (int) ($data['pages_served'] ?? 0);
            $out['unique_visitors'] = (int) ($data['unique_visitors'] ?? 0);
            $out['avg_pages']       = (float) ($data['avg_pages_per_visitor'] ?? 0);
            $out['human_hits']      = (int) ($data['human_hits'] ?? 0);
            $out['bot_hits']        = (int) ($data['bot_hits'] ?? 0);
            $out['top_pages']       = is_array($data['top_pages'] ?? null) ? $data['top_pages'] : [];
            $out['top_referrers']   = is_array($data['top_referrers'] ?? null) ? $data['top_referrers'] : [];
            $out['top_paths']       = is_array($data['top_paths'] ?? null) ? $data['top_paths'] : [];
            $out['daily']           = is_array($data['daily'] ?? null) ? $data['daily'] : [];
        }
    } catch (\Throwable $e) {
        $out['error'] = 'analytics function unavailable';
    }

    $out['available'] = ($out['pages_served'] !== null)
        && (($out['pages_served'] > 0) || ($out['bot_hits'] ?? 0) > 0);

    return $out;
}

/**
 * Read live component-liveness heartbeats the daemons write to
 * {geodineum}:gnode:heartbeat:{env}:{component} (SETEX 120s TTL, refreshed
 * ~60s). The per-site cred covers these via its ~{geodineum}:gnode:* grant, so
 * this uses direct GETs (no SCAN). Also reports which PHP libraries are loaded
 * on this host via class_exists. Read-only; degrades silently on NOPERM.
 *
 * findings-data-server is intentionally absent — it has no ValKey client yet
 * (tracked as a follow-up), so listing it would show a false "down".
 */
function gcore_fetch_component_health(): array {
    $out = ['env' => 'production', 'components' => [], 'libraries' => [], 'error' => null];

    // Env precedence mirrors DashboardAdmin::resolveEnvironment().
    $env = 'production';
    if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE !== '') {
        $env = (string) WP_ENVIRONMENT_TYPE;
    } elseif (($ge = (string) getenv('GEODINEUM_ENV')) !== '') {
        $env = $ge;
    }
    $out['env'] = $env;

    // Libraries loaded on THIS host (class/extension presence).
    $libs = [
        'gCore framework'   => class_exists('\\gCore\\Modules\\Core\\gCore'),
        'gNode-Client'      => class_exists('\\gCore\\gNode\\gNodeClient'),
        'ValKey (phpredis)' => extension_loaded('redis'),
        'COMMS integration' => class_exists('\\gCore\\Modules\\Comms\\Admin\\CommsAdmin'),
    ];
    foreach ($libs as $label => $present) {
        $out['libraries'][] = ['label' => $label, 'loaded' => (bool) $present];
    }

    $redis = gcore_admin_site_redis();
    if (!$redis) {
        $out['error'] = 'heartbeat backend unavailable';
        return $out;
    }

    // Writer and reader can resolve the env string differently (e.g. Geodine
    // defaults to 'testing', the gNode daemon serves every env as 'all');
    // check the resolved env, production, then 'all', taking the freshest key
    // found so a mismatch never shows a false "down".
    $envs = array_values(array_unique([$env, 'production', 'all']));

    // The daemons that emit heartbeats. Keyed under {geodineum} to match every
    // writer's GNODE_TOPOLOGY_NAMESPACE default (and the topology key already
    // read elsewhere in this file).
    $components = [
        'gnode-daemon' => 'gNode daemon',
        'comms'        => 'Geodineum-COMMS',
        'geodine'      => 'Geodine inference',
        'gshield'      => 'gShield',
    ];

    $now = time();
    foreach ($components as $key => $label) {
        $best_ts = null;
        foreach ($envs as $e) {
            try {
                $val = $redis->get('{geodineum}:gnode:heartbeat:' . $e . ':' . $key);
            } catch (\Throwable $ex) {
                $val = false;
            }
            if ($val === false || $val === null) {
                continue;
            }
            $ts = null;
            $dec = json_decode((string) $val, true);
            if (is_array($dec) && isset($dec['ts'])) {
                $ts = (int) $dec['ts'];
            } elseif (is_numeric($val)) {
                $ts = (int) $val;
            }
            if ($ts !== null && ($best_ts === null || $ts > $best_ts)) {
                $best_ts = $ts;
            }
        }

        if ($best_ts === null) {
            $status = 'down';
            $age = null;
        } else {
            $age = max(0, $now - $best_ts);
            $status = ($age <= 75) ? 'up' : 'lagging';
        }
        $out['components'][] = ['key' => $key, 'label' => $label, 'status' => $status, 'age' => $age];
    }

    return $out;
}

/**
 * Read this site's captured form submissions from the per-form streams
 * {site}:forms:<id> (written by gTemplate's [gform] submit endpoint): per-form
 * counts + the most recent submissions across all forms. Read-only, bounded.
 */
function gcore_fetch_form_captures(): array {
    $out = ['forms' => [], 'recent' => [], 'error' => null];
    $redis = gcore_admin_site_redis();
    $site  = gcore_admin_site_id();
    if (!$redis || !$site) { $out['error'] = 'unavailable'; return $out; }

    $base = '{' . $site . '}:forms:';
    try {
        $streams = [];
        $it = null; $scanned = 0;
        if (defined('\Redis::OPT_SCAN')) { $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY); }
        do {
            $keys = $redis->scan($it, $base . '*', 200);
            if (is_array($keys)) {
                foreach ($keys as $k) {
                    if (strpos($k, $base . 'rl:') === 0) { continue; } // skip rate-limit counters
                    $streams[] = $k;
                }
                $scanned += count($keys);
            }
        } while ($it > 0 && $scanned < 1000);

        $recent = [];
        foreach ($streams as $k) {
            $fid = substr($k, strlen($base));
            try { $len = (int) $redis->xLen($k); } catch (\Throwable $e) { continue; } // not a stream
            if ($len <= 0) { continue; }
            $out['forms'][$fid] = $len;
            try {
                $entries = $redis->xRevRange($k, '+', '-', 3);
                if (is_array($entries)) {
                    foreach ($entries as $fields) {
                        $flds = (!empty($fields['fields'])) ? (json_decode((string) $fields['fields'], true) ?: []) : [];
                        $preview = [];
                        foreach (array_slice($flds, 0, 3, true) as $fk => $fv) {
                            $preview[] = $fk . ': ' . mb_substr((string) $fv, 0, 40);
                        }
                        $recent[] = [
                            'form'    => $fid,
                            'ts'      => (int) ($fields['ts'] ?? 0),
                            'fp'      => substr((string) ($fields['fp'] ?? ''), 0, 10),
                            'preview' => implode(' · ', $preview),
                        ];
                    }
                }
            } catch (\Throwable $e) { /* skip */ }
        }
        usort($recent, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
        $out['recent'] = array_slice($recent, 0, 8);
    } catch (\Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/**
 * Get gNode connection status
 *
 * Works in admin context using lightweight gNode-Client.
 *
 * @return array Status information
 */
function gcore_get_gnode_status(): array {
    $status = [
        'connected' => false,
        'latency_ms' => null,
        'error' => null,
        'last_check' => time(),
        'daemon_version' => null,
        'site_id' => null,
    ];

    // Use lightweight admin gNode client (works without full gCore init)
    $gNodeClient = gcore_get_admin_gnode_client();
    if (!$gNodeClient) {
        $status['error'] = 'gNode client not available';
        return $status;
    }

    try {
        // Time the ping
        $start = microtime(true);
        $pong = $gNodeClient->ping();
        $latency = (microtime(true) - $start) * 1000;

        if ($pong === 'PONG' || $pong === true) {
            $status['connected'] = true;
            $status['latency_ms'] = round($latency, 2);

            // Get site_id
            if (method_exists($gNodeClient, 'getSiteId')) {
                $status['site_id'] = $gNodeClient->getSiteId();
            }

            // Try to get daemon info
            try {
                $info = $gNodeClient->fcall('GNODE_INFO', [], []);
                if (is_array($info)) {
                    $status['daemon_version'] = $info['version'] ?? null;
                }
            } catch (Throwable $e) {
                // gNode_INFO might not exist in older daemons
            }
        } else {
            $status['error'] = 'Ping failed';
        }
    } catch (Throwable $e) {
        $status['error'] = $e->getMessage();
    }

    return $status;
}

// ============================================================================
// REST API ENDPOINTS
// ============================================================================

add_action('rest_api_init', function() {
    // Public status endpoint
    register_rest_route('gcore/v1', '/status', [
        'methods' => 'GET',
        'callback' => 'gcore_rest_status',
        'permission_callback' => '__return_true'
    ]);

    // gNode status endpoint (admin only)
    register_rest_route('gcore/v1', '/gnode-status', [
        'methods' => 'GET',
        'callback' => 'gcore_rest_gnode_status',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Services endpoint (admin only)
    register_rest_route('gcore/v1', '/services', [
        'methods' => 'GET',
        'callback' => 'gcore_rest_services',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Health check endpoint
    register_rest_route('gcore/v1', '/health', [
        'methods' => 'GET',
        'callback' => 'gcore_rest_health',
        'permission_callback' => '__return_true'
    ]);

    // Topology endpoint (admin only) - for 3D visualizer
    register_rest_route('gcore/v1', '/topology', [
        'methods' => 'GET',
        'callback' => 'gcore_rest_topology',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);

    // Topology schema endpoint (admin only)
    register_rest_route('gcore/v1', '/topology/schema', [
        'methods' => 'GET',
        'callback' => 'gcore_rest_topology_schema',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});

function gcore_rest_status(): WP_REST_Response {
    global $gCore;

    $status = [
        'status' => 'ok',
        'version' => defined('GCORE_VERSION') ? GCORE_VERSION : 'unknown',
        'php_version' => PHP_VERSION,
        'timestamp' => time()
    ];

    if ($gCore && method_exists($gCore, 'isInitialized')) {
        $status['initialized'] = $gCore->isInitialized();
    }

    return new WP_REST_Response($status, 200);
}

function gcore_rest_gnode_status(): WP_REST_Response {
    return new WP_REST_Response(gcore_get_gnode_status(), 200);
}

function gcore_rest_services(): WP_REST_Response {
    global $gCore;

    if (!$gCore) {
        return new WP_REST_Response(['error' => 'gCore not available'], 500);
    }

    try {
        $services = method_exists($gCore, 'getRegisteredServices')
            ? $gCore->getRegisteredServices()
            : [];

        return new WP_REST_Response([
            'services' => $services,
            'count' => count($services)
        ], 200);
    } catch (Throwable $e) {
        return new WP_REST_Response(['error' => $e->getMessage()], 500);
    }
}

function gcore_rest_health(): WP_REST_Response {
    $health = [
        'status' => 'healthy',
        'checks' => []
    ];

    // Check gNode connection
    $gnode_status = gcore_get_gnode_status();
    $health['checks']['gnode'] = $gnode_status['connected'] ? 'ok' : 'disconnected';
    if ($gnode_status['latency_ms']) {
        $health['checks']['gnode_latency_ms'] = $gnode_status['latency_ms'];
    }

    // Check framework paths
    $health['checks']['framework'] = is_readable(GCORE_FRAMEWORK_PATH . '/Modules/Core/gCore.php') ? 'ok' : 'missing';

    if (!$gnode_status['connected']) {
        $health['status'] = 'degraded';
    }

    return new WP_REST_Response($health, $health['status'] === 'healthy' ? 200 : 503);
}

/**
 * Get topology data for 3D visualization.
 *
 * Query params:
 *   tier=tool    → ecosystem-wide tool pyramid (16D)
 *   tier=service → per-site service topology (30D, default)
 */
function gcore_rest_topology(\WP_REST_Request $request): WP_REST_Response {
    try {
        // gCore is frontend-only-initialized; in the REST/admin context reach
        // the constellation through the canonical admin accessor (per-site
        // FCALL client), never the frontend $gCore global.
        $gNodeClient = function_exists('gcore_get_admin_gnode_client')
            ? gcore_get_admin_gnode_client()
            : null;
        if (!$gNodeClient) {
            return new WP_REST_Response(['error' => 'gNode client not available'], 500);
        }

        // Read the live daemon topology snapshot — the HASH at
        // {geodineum}:gnode:topology:services that each capability-vector
        // registration maintains. getTopology() reads it via FCALL
        // GNODE_HASH_HGETALL (the FCALL-only path the per-site cred is
        // granted). Each entry carries the service's full point vector +
        // metadata, so we expand the point into named dimensions the
        // visualizer plots by axis. (The former DESCRIBE_ALL/LIST_SERVICES
        // path read a string-blob model removed from the daemon.)
        $tier = $request->get_param('tier') ?: 'service';

        $topology = method_exists($gNodeClient, 'getTopology')
            ? $gNodeClient->getTopology()
            : ['services' => []];
        $snapshot = is_array($topology['services'] ?? null) ? $topology['services'] : [];

        // Dimension name -> index map from the static schema, to label each
        // coordinate of the point vector for axis selection.
        $dimMap = [];
        try {
            $schemaRaw = $gNodeClient->fcall('GNODE_TOPOLOGY_GET_SCHEMA', [], []);
            $schema = is_string($schemaRaw) ? json_decode($schemaRaw, true) : $schemaRaw;
            if (is_array($schema['dimensions'] ?? null)) {
                $dimMap = $schema['dimensions'];
            }
        } catch (Throwable $e) {
            // Axes degrade to raw indices if the schema is unavailable.
        }

        $services = [];
        foreach ($snapshot as $id => $entry) {
            $point = is_array($entry['point'] ?? null) ? $entry['point'] : [];
            $meta  = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];

            // The snapshot tags each entry with a tier (e.g. SERVICE); honour
            // the tool filter so the picker degrades gracefully.
            if ($tier === 'tool' && strtoupper((string) ($meta['tier'] ?? '')) !== 'TOOL') {
                continue;
            }

            $isSite = gcore_topology_entry_is_site($meta);
            $row = [
                'id'           => (string) $id,
                'type'         => $meta['type'] ?? 'unknown',
                'service_tier' => $meta['tier'] ?? '',
                'description'  => $meta['description'] ?? '',
                'is_site'      => $isSite,
                'node_class'   => $isSite ? 'site' : 'service',
                'point'        => $point,
                'point_data'   => ['point' => $point, 'metadata' => $meta],
            ];
            foreach ($dimMap as $name => $idx) {
                if (is_int($idx) && array_key_exists($idx, $point)) {
                    $row[$name] = $point[$idx];
                }
            }
            $services[] = $row;
        }

        return new WP_REST_Response([
            'tier'          => $tier,
            'topology_key'  => '{geodineum}:gnode:topology:services',
            'services'      => $services,
            'service_count' => count($services),
            'timestamp'     => time()
        ], 200);

    } catch (Throwable $e) {
        return new WP_REST_Response(['error' => $e->getMessage()], 500);
    }
}

/**
 * Get topology dimension schema
 */
function gcore_rest_topology_schema(): WP_REST_Response {
    try {
        // Canonical admin accessor — gCore is frontend-only-initialized.
        $gNodeClient = function_exists('gcore_get_admin_gnode_client')
            ? gcore_get_admin_gnode_client()
            : null;
        if (!$gNodeClient) {
            return new WP_REST_Response(['error' => 'gNode client not available'], 500);
        }

        // Get the full schema with dimension names and values
        $result = $gNodeClient->fcall('GNODE_TOPOLOGY_GET_SCHEMA', [], []);

        if (!$result) {
            return new WP_REST_Response(['error' => 'Failed to fetch schema'], 500);
        }

        $schema = is_string($result) ? json_decode($result, true) : $result;

        return new WP_REST_Response($schema, 200);

    } catch (Throwable $e) {
        return new WP_REST_Response(['error' => $e->getMessage()], 500);
    }
}

// ============================================================================
// HEARTBEAT INTEGRATION
// ============================================================================

/**
 * Add gNode status to WordPress heartbeat
 * This allows real-time status updates in the admin
 */
add_filter('heartbeat_received', function($response, $data) {
    // Only process if gcore_gnode_status was requested
    if (!empty($data['gcore_gnode_status'])) {
        $response['gcore_gnode_status'] = gcore_get_gnode_status();
    }
    return $response;
}, 10, 2);

/**
 * Enqueue heartbeat script for admin pages
 */
add_action('admin_enqueue_scripts', function($hook) {
    // Only on gCore admin pages
    if (strpos($hook, 'gcore') === false) {
        return;
    }

    wp_enqueue_script('heartbeat');

    // Add inline script for gNode status updates
    wp_add_inline_script('heartbeat', "
        (function($) {
            var restUrl = '" . esc_url(rest_url('gcore/v1/gnode-status')) . "';
            var nonce = '" . wp_create_nonce('wp_rest') . "';

            // Function to update status display
            function updateStatusDisplay(status) {
                var indicator = $('#gcore-gnode-status-indicator');
                var details = $('#gcore-gnode-status-details');
                var lastCheck = $('#gcore-last-check');

                indicator.attr('data-checking', 'false');
                indicator.find('.dashicons').removeClass('dashicons-update spin');

                if (status.connected) {
                    indicator.removeClass('disconnected').addClass('connected');
                    indicator.find('.dashicons').addClass('dashicons-yes-alt');
                    indicator.find('.status-text').text('Connected');
                    details.html(
                        '<table class=\"gcore-table\">' +
                        '<tr><th>Latency</th><td>' + status.latency_ms + 'ms</td></tr>' +
                        (status.site_id ? '<tr><th>Site ID</th><td><code>' + status.site_id + '</code></td></tr>' : '') +
                        (status.daemon_version ? '<tr><th>Daemon Version</th><td>v' + status.daemon_version + '</td></tr>' : '') +
                        '</table>'
                    );
                } else {
                    indicator.removeClass('connected').addClass('disconnected');
                    indicator.find('.dashicons').addClass('dashicons-warning');
                    indicator.find('.status-text').text('Disconnected');
                    details.html('<p style=\"color:#dc3232;margin:0;\">' + (status.error || 'Connection failed') + '</p>');
                }

                lastCheck.text('Last check: ' + new Date().toLocaleTimeString());
            }

            // Immediate status check on page load via REST API
            $(document).ready(function() {
                var indicator = $('#gcore-gnode-status-indicator');
                if (indicator.attr('data-checking') === 'true') {
                    // Add spinning animation
                    indicator.find('.dashicons').addClass('spin');

                    $.ajax({
                        url: restUrl,
                        method: 'GET',
                        headers: { 'X-WP-Nonce': nonce },
                        success: function(status) {
                            updateStatusDisplay(status);
                        },
                        error: function() {
                            updateStatusDisplay({ connected: false, error: 'REST API unavailable' });
                        }
                    });
                }
            });

            // Request gNode status on each heartbeat
            $(document).on('heartbeat-send', function(e, data) {
                data.gcore_gnode_status = true;
            });

            // Handle gNode status response
            $(document).on('heartbeat-tick', function(e, data) {
                if (data.gcore_gnode_status) {
                    updateStatusDisplay(data.gcore_gnode_status);
                }
            });
        })(jQuery);
    ");

    // Add CSS for spinning animation
    wp_add_inline_style('dashicons', '
        .dashicons.spin {
            animation: gcore-spin 1s linear infinite;
        }
        @keyframes gcore-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    ');
});

// ============================================================================
// ADMIN MENU & PAGES
// ============================================================================

/**
 * Operator-console pages (Diagnostics, Topology, Schemas, Templates, the
 * Sites/Services/Streams-&-Keys explorer) read cross-site/ecosystem keyspaces
 * or Pro-gated managers. A per-site wp-admin (least-privilege www-data cred)
 * cannot serve them, so they are HIDDEN for launch — the per-site backend shows
 * only the pages that actually work per-site (Status + Notifications). They
 * return in the standalone gDash operator console. Re-enable on a dev/operator
 * host by defining GEODINEUM_OPERATOR_CONSOLE truthy in wp-config.
 */
function gcore_operator_console_enabled(): bool {
    return defined('GEODINEUM_OPERATOR_CONSOLE') && GEODINEUM_OPERATOR_CONSOLE;
}

add_action('admin_menu', function() {
    if (!current_user_can('manage_options')) {
        return;
    }

    add_menu_page(
        __('Geodineum', 'gcore'),
        __('Geodineum', 'gcore'),
        'manage_options',
        'gcore-dashboard',
        'gcore_admin_dashboard_page',
        'dashicons-networking',
        80
    );

    // Replace WordPress's auto-generated parent-named duplicate with a "Status"
    // label by using the parent slug for this submenu (NOT a separate slug).
    add_submenu_page(
        'gcore-dashboard',
        __('Status', 'gcore'),
        __('Status', 'gcore'),
        'manage_options',
        'gcore-dashboard',
        'gcore_admin_dashboard_page'
    );

    // Read-only Topology 3D viewer — pulled behind the operator-console gate
    // for launch (operator call 2026-07-14: current view too limited to be a
    // public differentiator; revisit post-launch). gNode-TOPO (Pro) gates
    // custom-topology authoring; this stub only views the live topology.
    if (gcore_operator_console_enabled()) {
        add_submenu_page(
            'gcore-dashboard',
            __('Topology Visualizer', 'gcore'),
            __('Topology 3D', 'gcore'),
            'manage_options',
            'gcore-topology',
            'gcore_admin_topology_page'
        );
    }

    // Operator-console only — hidden for launch (see gcore_operator_console_enabled).
    if (gcore_operator_console_enabled()) {
    add_submenu_page(
        'gcore-dashboard',
        __('Diagnostics', 'gcore'),
        __('Diagnostics', 'gcore'),
        'manage_options',
        'gcore-diagnostics',
        'gcore_admin_diagnostics_page'
    );
    } // end gcore_operator_console_enabled()
});

// Brand assets for the Geodineum admin pages. Loaded unconditionally — the
// DashboardAdmin enqueue only fires when the operator console is on, but the
// launch dashboard needs the gold/dark brand (.geo-admin scope) without it.
// Shared handle 'gdash-admin' → WP dedupes if DashboardAdmin also enqueues.
add_action('admin_enqueue_scripts', function ($hook) {
    if (!is_string($hook) || (strpos($hook, 'gcore') === false && strpos($hook, 'geodineum') === false) || !defined('GCORE_MU_URL')) {
        return;
    }
    // Assets are served with a 1-year max-age, so key the version on the file
    // mtime — a content-stable GCORE_VERSION would let a stale cached copy win
    // after a brand edit. mtime changes whenever the file changes.
    $cssPath = (defined('GCORE_MU_DIR') ? GCORE_MU_DIR : '') . 'assets/dashboard.css';
    $version = ($cssPath !== 'assets/dashboard.css' && is_readable($cssPath))
        ? (string) filemtime($cssPath)
        : (defined('GCORE_VERSION') ? (string) GCORE_VERSION : '1.0.0');
    wp_enqueue_style('gdash-admin', GCORE_MU_URL . 'assets/dashboard.css', [], $version);

    // The read-only Topology 3D page needs three.js in the document head (its
    // inline viz script depends on THREE at parse time). Enqueue here so the
    // page works without the operator console — DashboardAdmin's enqueue is
    // gated; the shared 'gdash-three' handle dedupes if both run.
    if (strpos($hook, 'gcore-topology') !== false) {
        wp_enqueue_script('gdash-three', GCORE_MU_URL . 'assets/three.min.js', [], 'r128', false);
    }
});

// Tag every Geodineum admin screen so the gold/dark brand (body.geodineum-admin
// rules in dashboard.css) themes the whole page — not just the dashboard.
add_filter('admin_body_class', function ($classes) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $id = (string) ($screen->id ?? '');
    if ($id !== '' && (strpos($id, 'gcore') !== false || strpos($id, 'geodineum') !== false)) {
        $classes .= ' geodineum-admin';
    }
    return $classes;
});

// Load module admin interfaces (self-initializing singletons).
// Launch surface = site-scoped pages only (Notifications/Comms). The
// operator/cross-site modules (Templates, Schemas, and DashboardAdmin's
// Sites/Services/Streams-&-Keys explorer) are loaded ONLY when the operator
// console is enabled — they belong in gDash. See gcore_operator_console_enabled().
if (is_admin()) {
    $adminModules = [
        [GCORE_FRAMEWORK_PATH . '/Modules/Comms/Admin/CommsAdmin.php',
         '\\gCore\\Modules\\Comms\\Admin\\CommsAdmin'],
    ];
    if (gcore_operator_console_enabled()) {
        $adminModules[] = [GCORE_FRAMEWORK_PATH . '/Modules/Template/Admin/TemplateAdmin.php',
            '\\gCore\\Modules\\Template\\Admin\\TemplateAdmin'];
        $adminModules[] = [GCORE_FRAMEWORK_PATH . '/Modules/Schemas/Admin/SchemasAdmin.php',
            '\\gCore\\Modules\\Schemas\\Admin\\SchemasAdmin'];
        $adminModules[] = [GCORE_FRAMEWORK_PATH . '/Modules/Dashboard/Admin/DashboardAdmin.php',
            '\\gCore\\Modules\\Dashboard\\Admin\\DashboardAdmin'];
    }
    foreach ($adminModules as [$adminFile, $adminClass]) {
        if (file_exists($adminFile)) {
            require_once $adminFile;
            // Instantiate singleton so it can register hooks
            try {
                if (class_exists($adminClass) && method_exists($adminClass, 'getInstance')) {
                    $adminClass::getInstance();
                }
            } catch (\Throwable $e) {
                // Admin module failed to initialize — log but don't crash wp-admin
                if (function_exists('error_log')) {
                    error_log("gCore: admin module {$adminClass} failed to initialize: " . $e->getMessage());
                }
            }
        }
    }
}

/**
 * Admin dashboard page with gNode status
 */
function gcore_admin_dashboard_page(): void {
    global $gCore;

    // Try to get gCore instance if global not set
    if (!$gCore) {
        try {
            $gCoreClass = '\\gCore\\Modules\\Core\\gCore';
            if (class_exists($gCoreClass)) {
                $gCore = $gCoreClass::getInstance();
            }
        } catch (Throwable $e) {
            // Ignore
        }
    }

    $gnode_status = gcore_get_gnode_status();

    ?>
    <div class="wrap">
        <div class="geo-admin">
            <div class="geo-hero">
                <div class="geo-hero-titles">
                    <h1 class="geo-hero-title"><?php esc_html_e('Geodineum', 'gcore'); ?></h1>
                    <p class="geo-hero-sub"><?php esc_html_e('Constellation Operator Dashboard', 'gcore'); ?></p>
                </div>
                <span class="geo-hero-pill <?php echo $gnode_status['connected'] ? 'connected' : 'disconnected'; ?>">
                    <span class="dashicons <?php echo $gnode_status['connected'] ? 'dashicons-yes-alt' : 'dashicons-update'; ?>"></span>
                    <?php echo $gnode_status['connected'] ? esc_html__('gNode Connected', 'gcore') : esc_html__('Connecting…', 'gcore'); ?>
                </span>
            </div>

        <div class="gcore-status-grid">
            <!-- gNode Status Card -->
            <div class="gcore-card">
                <h2><?php esc_html_e('gNode Daemon Status', 'gcore'); ?></h2>

                <div id="gcore-gnode-status-indicator" class="gcore-status-indicator <?php echo $gnode_status['connected'] ? 'connected' : 'disconnected'; ?>" data-checking="<?php echo $gnode_status['connected'] ? 'false' : 'true'; ?>">
                    <span class="dashicons <?php echo $gnode_status['connected'] ? 'dashicons-yes-alt' : 'dashicons-update'; ?>"></span>
                    <span class="status-text"><?php echo $gnode_status['connected'] ? esc_html__('Connected', 'gcore') : esc_html__('Checking...', 'gcore'); ?></span>
                </div>

                <div id="gcore-gnode-status-details" style="margin-top: 15px;">
                    <?php if ($gnode_status['connected']): ?>
                        <table class="gcore-table">
                            <tr><th><?php esc_html_e('Latency', 'gcore'); ?></th><td><?php echo esc_html($gnode_status['latency_ms']); ?>ms</td></tr>
                            <?php if ($gnode_status['site_id']): ?>
                            <tr><th><?php esc_html_e('Site ID', 'gcore'); ?></th><td><code><?php echo esc_html($gnode_status['site_id']); ?></code></td></tr>
                            <?php endif; ?>
                            <?php if ($gnode_status['daemon_version']): ?>
                            <tr><th><?php esc_html_e('Daemon Version', 'gcore'); ?></th><td>v<?php echo esc_html($gnode_status['daemon_version']); ?></td></tr>
                            <?php endif; ?>
                        </table>
                    <?php else: ?>
                        <p style="color: #888; margin: 0;" id="gcore-status-message"><?php esc_html_e('Checking gNode connection...', 'gcore'); ?></p>
                    <?php endif; ?>
                </div>

                <p class="last-check" id="gcore-last-check"><?php esc_html_e('Last check:', 'gcore'); ?> <?php echo esc_html(date('H:i:s')); ?></p>
                <p class="description" style="margin-top: 10px; font-size: 12px;">
                    <?php esc_html_e('Status updates automatically via WordPress heartbeat.', 'gcore'); ?>
                </p>
            </div>

            <!-- Framework Status Card -->
            <div class="gcore-card">
                <h2><?php esc_html_e('Framework Status', 'gcore'); ?></h2>
                <table class="gcore-table">
                    <tr>
                        <th><?php esc_html_e('Version', 'gcore'); ?></th>
                        <td><?php echo esc_html(defined('GCORE_VERSION') ? GCORE_VERSION : 'Unknown'); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Framework Path', 'gcore'); ?></th>
                        <td><code><?php echo esc_html(GCORE_FRAMEWORK_PATH); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('PHP Version', 'gcore'); ?></th>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Mode', 'gcore'); ?></th>
                        <td>
                            <span style="color:#0073aa;" title="<?php esc_attr_e('gCore initializes on frontend requests. Admin uses lightweight gNode connection.', 'gcore'); ?>">
                                <?php esc_html_e('Frontend Active', 'gcore'); ?>
                                <span class="dashicons dashicons-info" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Topology Services Card - Fetched from gNode -->
            <?php
            $topology = gcore_fetch_topology_services();
            $totalServices = count($topology['services']);
            ?>
            <?php
            $siteCount = (int) ($topology['counts']['sites'] ?? 0);
            $svcCount  = (int) ($topology['counts']['services'] ?? 0);
            ?>
            <div class="gcore-card">
                <h2><?php esc_html_e('Topology', 'gcore'); ?> <span class="geo-count">(<?php echo $siteCount; ?>&nbsp;<?php esc_html_e('sites', 'gcore'); ?> · <?php echo $svcCount; ?>&nbsp;<?php esc_html_e('services', 'gcore'); ?>)</span></h2>
                <?php if ($topology['error']): ?>
                    <p class="geo-bad-text"><?php echo esc_html($topology['error']); ?></p>
                <?php elseif ($totalServices > 0): ?>
                    <?php
                    // Sites = internet-exposed; Services = internal. This is the
                    // ecosystem's core distinction, surfaced for the operator.
                    $sections = [
                        ['key' => 'sites',    'label' => __('Sites', 'gcore'),    'css' => 'site', 'hint' => __('internet-exposed', 'gcore')],
                        ['key' => 'services', 'label' => __('Services', 'gcore'), 'css' => 'svc',  'hint' => __('internal', 'gcore')],
                    ];
                    foreach ($sections as $sec):
                        $items = $topology['by_class'][$sec['key']] ?? [];
                        if (empty($items)) { continue; }
                    ?>
                    <div class="gcore-tier-section">
                        <div class="gcore-tier-label <?php echo esc_attr($sec['css']); ?>"><?php echo esc_html($sec['label']); ?> <span class="geo-dim">(<?php echo count($items); ?> · <?php echo esc_html($sec['hint']); ?>)</span></div>
                        <ul class="gcore-services-list">
                            <?php foreach ($items as $svc): ?>
                            <li title="<?php echo esc_attr($svc['description']); ?>"><code><?php echo esc_html($svc['id']); ?></code> <span><?php echo esc_html($svc['type']); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?php esc_html_e('No services in topology yet.', 'gcore'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // Visitor analytics — read straight from this site's {site}:metrics:*
        // keys (no operator console required; every site has its own).
        $analytics = gcore_fetch_visitor_analytics();
        ?>
        <h2 class="geo-section-head"><?php esc_html_e('Visitor Analytics', 'gcore'); ?></h2>
        <?php if (!$analytics['available']): ?>
        <p class="description" style="margin: 0 34px;"><?php esc_html_e('No visitor metrics recorded for this site yet.', 'gcore'); ?></p>
        <?php else: ?>
        <p class="description" style="margin: 0 34px 12px;"><?php esc_html_e('Real page views over the last 7 days — cookieless, human traffic only unless noted.', 'gcore'); ?></p>
        <div class="gdash-stat-grid">
            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php esc_html_e('Unique visitors · 7 days', 'gcore'); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html($analytics['unique_visitors'] === null ? '—' : number_format((int) $analytics['unique_visitors'])); ?></div>
                <div class="gdash-stat-sub"><?php esc_html_e('distinct people', 'gcore'); ?></div>
            </div>
            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php esc_html_e('Pages served · 7 days', 'gcore'); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html($analytics['pages_served'] === null ? '—' : number_format((int) $analytics['pages_served'])); ?></div>
                <div class="gdash-stat-sub"><?php esc_html_e('human page views', 'gcore'); ?></div>
            </div>
            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php esc_html_e('Pages per visitor', 'gcore'); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html($analytics['avg_pages'] === null ? '—' : number_format((float) $analytics['avg_pages'], 1)); ?></div>
                <div class="gdash-stat-sub"><?php esc_html_e('avg depth of visit', 'gcore'); ?></div>
            </div>
            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php esc_html_e('Human vs bot', 'gcore'); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html(number_format((int) ($analytics['human_hits'] ?? 0))); ?><span class="geo-dim" style="font-size: 0.6em;"> / <?php echo esc_html(number_format((int) ($analytics['bot_hits'] ?? 0))); ?></span></div>
                <div class="gdash-stat-sub"><?php esc_html_e('hits · human / bot', 'gcore'); ?></div>
            </div>
        </div>
        <?php if (!empty($analytics['top_pages'])): ?>
        <div style="padding: 0 34px; margin-top: 16px;">
            <div class="gcore-tier-label svc"><?php esc_html_e('Top pages', 'gcore'); ?></div>
            <ul class="gcore-services-list" style="max-width: 640px;">
                <?php foreach ($analytics['top_pages'] as $row): ?>
                <li><code><?php echo esc_html((string) ($row['name'] ?? '')); ?></code> <span><?php echo esc_html(number_format((int) ($row['count'] ?? 0))); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if (!empty($analytics['top_paths'])): ?>
        <div style="padding: 0 34px; margin-top: 16px;">
            <div class="gcore-tier-label svc"><?php esc_html_e('Visitor paths', 'gcore'); ?></div>
            <ul class="gcore-services-list" style="max-width: 640px;">
                <?php foreach ($analytics['top_paths'] as $row): ?>
                <li><code><?php echo esc_html((string) ($row['name'] ?? '')); ?></code> <span><?php echo esc_html(number_format((int) ($row['count'] ?? 0))); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php if (!empty($analytics['top_referrers'])): ?>
        <div style="padding: 0 34px; margin-top: 16px;">
            <div class="gcore-tier-label svc"><?php esc_html_e('Top referrers', 'gcore'); ?></div>
            <ul class="gcore-services-list" style="max-width: 520px;">
                <?php foreach ($analytics['top_referrers'] as $row): ?>
                <li><code><?php echo esc_html((string) ($row['name'] ?? '')); ?></code> <span><?php echo esc_html(number_format((int) ($row['count'] ?? 0))); ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php
        // Captured form submissions ([gform] → {site}:forms:<id> streams).
        $forms = gcore_fetch_form_captures();
        if (!empty($forms['forms'])):
        ?>
        <h2 class="geo-section-head"><?php esc_html_e('Form Submissions', 'gcore'); ?></h2>
        <div class="gdash-stat-grid">
            <?php foreach ($forms['forms'] as $fid => $count): ?>
            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php echo esc_html((string) $fid); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html(number_format((int) $count)); ?></div>
                <div class="gdash-stat-sub"><?php esc_html_e('submissions', 'gcore'); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($forms['recent'])): ?>
        <div style="padding: 0 34px; margin-top: 16px;">
            <div class="gcore-tier-label svc"><?php esc_html_e('Recent submissions', 'gcore'); ?></div>
            <ul class="gcore-services-list" style="max-width: 640px;">
                <?php foreach ($forms['recent'] as $r): ?>
                <li>
                    <code><?php echo esc_html($r['form']); ?></code>
                    <span><?php echo esc_html($r['ts'] ? human_time_diff($r['ts']) . ' ' . __('ago', 'gcore') : ''); ?></span>
                    <?php if ($r['preview'] !== ''): ?><span class="geo-dim"> — <?php echo esc_html($r['preview']); ?></span><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php
        // gDash Status extension — sites count + cache hit-rate + recent activity.
        // Owned by Modules/Dashboard/Admin/DashboardAdmin so this surface stays
        // decoupled from wp-hooks.php logic; we only ask the singleton for data.
        $gdashAdmin = null;
        try {
            $gdashClass = '\\gCore\\Modules\\Dashboard\\Admin\\DashboardAdmin';
            if (class_exists($gdashClass)) {
                $gdashAdmin = $gdashClass::getInstance();
            }
        } catch (Throwable $e) {
            // Dashboard module missing → skip the extension cards entirely.
        }

        if ($gdashAdmin):
            $sites = $gdashAdmin->getSites();
            $sitesCount = count($sites);
            $primarySite = $sites[0] ?? '';
            $cache = $primarySite !== '' ? $gdashAdmin->cacheHitRate($primarySite) : null;
        ?>
        <h2 class="geo-section-head"><?php esc_html_e('Constellation Snapshot', 'gcore'); ?></h2>
        <p class="description" style="margin: 0 34px;">
            <?php esc_html_e('Live counters from ValKey. Refresh the page (or wait for the heartbeat tick) for fresh values.', 'gcore'); ?>
        </p>

        <div class="gdash-stat-grid">
            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php esc_html_e('Sites', 'gcore'); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html((string) $sitesCount); ?></div>
                <div class="gdash-stat-sub">
                    <?php esc_html_e('SMEMBERS gnode:sites:registry', 'gcore'); ?>
                </div>
            </div>

            <?php if ($cache && $cache['total'] > 0): ?>
            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php esc_html_e('Cache hit-rate', 'gcore'); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html(number_format($cache['rate'] * 100, 1)); ?>%</div>
                <div class="gdash-stat-sub">
                    <?php echo esc_html(sprintf(
                        /* translators: 1=hits 2=total */
                        __('%1$s hits of %2$s', 'gcore'),
                        number_format($cache['hits']),
                        number_format($cache['total'])
                    )); ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="gdash-stat">
                <div class="gdash-stat-label"><?php esc_html_e('Total services', 'gcore'); ?></div>
                <div class="gdash-stat-value"><?php echo esc_html((string) $totalServices); ?></div>
                <div class="gdash-stat-sub">
                    <?php esc_html_e('Across all tiers', 'gcore'); ?>
                </div>
            </div>
        </div>

        <?php
        // These explorer pages are only registered when the operator console is
        // enabled (they read cross-site / Pro keyspaces a per-site credential
        // cannot serve). Only render the buttons when their target pages exist,
        // otherwise they lead to a "not allowed to access this page" wp_die.
        if (function_exists('gcore_operator_console_enabled') && gcore_operator_console_enabled()):
        ?>
        <p class="geo-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-sites')); ?>" class="button">
                <?php esc_html_e('Inspect Sites', 'gcore'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-streams-keys')); ?>" class="button">
                <?php esc_html_e('Streams &amp; Keys', 'gcore'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=geodineum-schemas')); ?>" class="button">
                <?php esc_html_e('Schema Registry', 'gcore'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-service-logs')); ?>" class="button">
                <?php esc_html_e('Service Logs', 'gcore'); ?>
            </a>
        </p>
        <?php endif; ?>
        <?php endif; ?>

        <?php
        // Component Health — live liveness heartbeats across the constellation.
        // Independent of the gDash extension; uses only the per-site handle, so
        // it renders on every site's dashboard.
        $health = gcore_fetch_component_health();
        ?>
        <h2 class="geo-section-head"><?php esc_html_e('Component Health', 'gcore'); ?></h2>
        <p class="description" style="margin: 0 34px 12px;">
            <?php echo esc_html(sprintf(
                /* translators: %s = environment name */
                __('Live liveness heartbeats (120s TTL) · env: %s', 'gcore'),
                $health['env']
            )); ?>
        </p>
        <?php if (!empty($health['components'])): ?>
        <ul class="gcore-services-list" style="max-width: 640px; margin: 0 34px;">
            <?php foreach ($health['components'] as $c):
                $color = $c['status'] === 'up' ? '#3fb950' : ($c['status'] === 'lagging' ? '#d29922' : '#f85149');
                if ($c['status'] === 'up') {
                    $txt = ($c['age'] !== null) ? sprintf(__('up · %ds ago', 'gcore'), (int) $c['age']) : __('up', 'gcore');
                } elseif ($c['status'] === 'lagging') {
                    $txt = sprintf(__('lagging · %ds ago', 'gcore'), (int) $c['age']);
                } else {
                    $txt = __('no heartbeat', 'gcore');
                }
            ?>
            <li>
                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?php echo esc_attr($color); ?>;margin-right:8px;vertical-align:middle;"></span>
                <code><?php echo esc_html($c['label']); ?></code>
                <span class="geo-dim"><?php echo esc_html($txt); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($health['libraries'])): ?>
        <div style="padding: 0 34px; margin-top: 16px;">
            <div class="gcore-tier-label svc"><?php esc_html_e('Libraries on this host', 'gcore'); ?></div>
            <ul class="gcore-services-list" style="max-width: 520px;">
                <?php foreach ($health['libraries'] as $lib):
                    $lcolor = $lib['loaded'] ? '#3fb950' : '#6e7681';
                ?>
                <li>
                    <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?php echo esc_attr($lcolor); ?>;margin-right:8px;vertical-align:middle;"></span>
                    <code><?php echo esc_html($lib['label']); ?></code>
                    <span class="geo-dim"><?php echo $lib['loaded'] ? esc_html__('loaded', 'gcore') : esc_html__('absent', 'gcore'); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        </div><!-- .geo-admin -->
    </div>
    <?php
}

/**
 * Admin diagnostics page
 */
function gcore_admin_diagnostics_page(): void {
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('gCore Diagnostics', 'gcore') . '</h1>';

    $debugFile = GCORE_FRAMEWORK_PATH . '/gcore-debug.php';
    if (file_exists($debugFile)) {
        require_once $debugFile;

        if (function_exists('gcore_run_diagnostics')) {
            echo '<div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">';
            gcore_run_diagnostics();
            echo '</div>';
        }
    } else {
        echo '<div class="notice notice-warning"><p>' . esc_html__('Diagnostics module not available.', 'gcore') . '</p></div>';
    }

    echo '</div>';
}

/**
 * Admin topology 3D visualizer page
 */
function gcore_admin_topology_page(): void {
    // Get gCore instance for status check
    $gCore = null;
    try {
        $gCoreClass = '\\gCore\\Modules\\Core\\gCore';
        if (class_exists($gCoreClass)) {
            $gCore = $gCoreClass::getInstance();
        }
    } catch (Throwable $e) {
        // Ignore
    }

    $gnode_status = gcore_get_gnode_status();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Topology 3D Visualizer', 'gcore'); ?></h1>
        <p class="description"><?php esc_html_e('Visualize the 23-dimensional service topology in 3D space. Select any 3 capability dimensions for the X, Y, and Z axes.', 'gcore'); ?></p>

        <style>
            .topology-container { display: flex; gap: 20px; margin-top: 20px; flex-wrap: wrap; }
            .topology-canvas-wrap { flex: 1; min-width: 600px; background: #1a1a2e; border-radius: 8px; position: relative; }
            #topology-canvas { width: 100%; height: 600px; display: block; border-radius: 8px; }
            .topology-controls { width: 280px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; }
            .topology-controls h3 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px solid #eee; }
            .dimension-select { margin-bottom: 15px; }
            .dimension-select label { display: block; font-weight: 600; margin-bottom: 5px; color: #1d2327; }
            .dimension-select select { width: 100%; padding: 8px; border-radius: 4px; border: 1px solid #8c8f94; }
            .axis-x select { border-left: 4px solid #e74c3c; }
            .axis-y select { border-left: 4px solid #2ecc71; }
            .axis-z select { border-left: 4px solid #3498db; }
            .service-legend { margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; }
            .service-legend h4 { margin: 0 0 10px 0; font-size: 13px; }
            .legend-item { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 12px; cursor: pointer; }
            .legend-item:hover { background: #f0f0f1; margin: 0 -10px; padding: 4px 10px; }
            .legend-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
            .legend-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .topology-info { position: absolute; bottom: 15px; left: 15px; background: rgba(0,0,0,0.8); color: #fff; padding: 10px 15px; border-radius: 4px; font-size: 12px; font-family: monospace; display: none; max-width: 350px; }
            .topology-loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #fff; font-size: 16px; }
            .controls-footer { margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; }
            .controls-footer button { width: 100%; padding: 10px; margin-bottom: 8px; }
            .dimension-group { margin-bottom: 20px; }
            .dimension-group-title { font-size: 11px; text-transform: uppercase; color: #666; margin-bottom: 8px; letter-spacing: 0.5px; }
        </style>

        <div class="topology-container">
            <div class="topology-canvas-wrap">
                <canvas id="topology-canvas"></canvas>
                <div class="topology-loading" id="topology-loading">Loading topology data...</div>
                <div class="topology-info" id="topology-info"></div>
            </div>

            <div class="topology-controls">
                <h3><?php esc_html_e('Topology Tier', 'gcore'); ?></h3>

                <div class="dimension-group">
                    <div class="dimension-group-title"><?php esc_html_e('Tier Selection', 'gcore'); ?></div>

                    <div class="dimension-select">
                        <label for="topology-tier"><?php esc_html_e('Topology', 'gcore'); ?></label>
                        <select id="topology-tier">
                            <option value="service"><?php esc_html_e('Service Tier (per-site, 30D)', 'gcore'); ?></option>
                            <option value="tool"><?php esc_html_e('Tool Pyramid (ecosystem-wide, 16D)', 'gcore'); ?></option>
                        </select>
                    </div>
                </div>

                <h3 style="margin-top:20px;"><?php esc_html_e('Dimension Axes', 'gcore'); ?></h3>

                <div class="dimension-group">
                    <div class="dimension-group-title"><?php esc_html_e('Axis Selection', 'gcore'); ?></div>

                    <div class="dimension-select axis-x">
                        <label for="dim-x"><?php esc_html_e('X Axis (Red)', 'gcore'); ?></label>
                        <select id="dim-x"></select>
                    </div>

                    <div class="dimension-select axis-y">
                        <label for="dim-y"><?php esc_html_e('Y Axis (Green)', 'gcore'); ?></label>
                        <select id="dim-y"></select>
                    </div>

                    <div class="dimension-select axis-z">
                        <label for="dim-z"><?php esc_html_e('Z Axis (Blue)', 'gcore'); ?></label>
                        <select id="dim-z"></select>
                    </div>
                </div>

                <div class="service-legend" id="service-legend">
                    <h4><?php esc_html_e('Services', 'gcore'); ?></h4>
                    <div id="legend-items"></div>
                </div>

                <div class="controls-footer">
                    <button type="button" class="button" id="reset-view"><?php esc_html_e('Reset View', 'gcore'); ?></button>
                    <button type="button" class="button" id="refresh-data"><?php esc_html_e('Refresh Data', 'gcore'); ?></button>
                </div>
            </div>
        </div>

        <?php // three.js r128 is enqueued in the document head by DashboardAdmin::enqueueAssets — vendored at gcore-mu/assets/three.min.js. ?>
        <script>
        (function() {
            const REST_URL = '<?php echo esc_url(rest_url('gcore/v1')); ?>';
            const NONCE = '<?php echo wp_create_nonce('wp_rest'); ?>';

            let scene, camera, renderer, controls;
            let serviceMeshes = [];
            let topology = null;
            let schema = null;
            let selectedDims = { x: 'domain_primary', y: 'service_scope', z: 'reliability_tier' };

            // Colors for different service types
            const SERVICE_COLORS = {
                'wordpress': 0x21759b,
                'wordpress-site': 0x21759b,
                'gnode-daemon': 0xe51022,
                'manager': 0xf39c12,
                'default': 0x9b59b6
            };

            // Initialize Three.js
            function initScene() {
                const canvas = document.getElementById('topology-canvas');
                const width = canvas.clientWidth;
                const height = canvas.clientHeight;

                // Scene
                scene = new THREE.Scene();
                scene.background = new THREE.Color(0x1a1a2e);

                // Camera
                camera = new THREE.PerspectiveCamera(60, width / height, 0.1, 1000);
                camera.position.set(2, 2, 2);
                camera.lookAt(0, 0, 0);

                // Renderer
                renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true });
                renderer.setSize(width, height);
                renderer.setPixelRatio(window.devicePixelRatio);

                // Lights
                const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
                scene.add(ambientLight);

                const pointLight = new THREE.PointLight(0xffffff, 0.8);
                pointLight.position.set(5, 5, 5);
                scene.add(pointLight);

                // Grid helpers (axes)
                addAxisHelpers();

                // Simple orbit controls (mouse drag)
                setupControls();

                // Handle resize
                window.addEventListener('resize', onResize);

                // Raycaster for hover
                setupRaycaster();

                animate();
            }

            function addAxisHelpers() {
                // Remove existing helpers
                scene.children = scene.children.filter(c => !c.isGridHelper && !c.isAxisHelper);

                // Grid on XZ plane
                const gridHelper = new THREE.GridHelper(2, 10, 0x444444, 0x333333);
                gridHelper.position.y = 0;
                scene.add(gridHelper);

                // Axis lines
                const axisLength = 1.2;

                // X axis (red)
                const xGeom = new THREE.BufferGeometry().setFromPoints([
                    new THREE.Vector3(0, 0, 0),
                    new THREE.Vector3(axisLength, 0, 0)
                ]);
                const xLine = new THREE.Line(xGeom, new THREE.LineBasicMaterial({ color: 0xe74c3c, linewidth: 2 }));
                scene.add(xLine);

                // Y axis (green)
                const yGeom = new THREE.BufferGeometry().setFromPoints([
                    new THREE.Vector3(0, 0, 0),
                    new THREE.Vector3(0, axisLength, 0)
                ]);
                const yLine = new THREE.Line(yGeom, new THREE.LineBasicMaterial({ color: 0x2ecc71, linewidth: 2 }));
                scene.add(yLine);

                // Z axis (blue)
                const zGeom = new THREE.BufferGeometry().setFromPoints([
                    new THREE.Vector3(0, 0, 0),
                    new THREE.Vector3(0, 0, axisLength)
                ]);
                const zLine = new THREE.Line(zGeom, new THREE.LineBasicMaterial({ color: 0x3498db, linewidth: 2 }));
                scene.add(zLine);
            }

            function setupControls() {
                let isDragging = false;
                let previousMousePosition = { x: 0, y: 0 };
                const canvas = renderer.domElement;

                canvas.addEventListener('mousedown', (e) => {
                    isDragging = true;
                    previousMousePosition = { x: e.clientX, y: e.clientY };
                });

                canvas.addEventListener('mousemove', (e) => {
                    if (!isDragging) return;

                    const deltaX = e.clientX - previousMousePosition.x;
                    const deltaY = e.clientY - previousMousePosition.y;

                    // Rotate camera around origin
                    const spherical = new THREE.Spherical();
                    spherical.setFromVector3(camera.position);
                    spherical.theta -= deltaX * 0.01;
                    spherical.phi += deltaY * 0.01;
                    spherical.phi = Math.max(0.1, Math.min(Math.PI - 0.1, spherical.phi));

                    camera.position.setFromSpherical(spherical);
                    camera.lookAt(0, 0, 0);

                    previousMousePosition = { x: e.clientX, y: e.clientY };
                });

                canvas.addEventListener('mouseup', () => { isDragging = false; });
                canvas.addEventListener('mouseleave', () => { isDragging = false; });

                canvas.addEventListener('wheel', (e) => {
                    e.preventDefault();
                    const zoom = e.deltaY > 0 ? 1.1 : 0.9;
                    camera.position.multiplyScalar(zoom);
                    camera.position.clampLength(1, 10);
                });
            }

            function setupRaycaster() {
                const raycaster = new THREE.Raycaster();
                const mouse = new THREE.Vector2();
                const canvas = renderer.domElement;
                const infoBox = document.getElementById('topology-info');

                canvas.addEventListener('mousemove', (e) => {
                    const rect = canvas.getBoundingClientRect();
                    mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                    mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

                    raycaster.setFromCamera(mouse, camera);
                    const intersects = raycaster.intersectObjects(serviceMeshes);

                    if (intersects.length > 0) {
                        const mesh = intersects[0].object;
                        const data = mesh.userData;
                        infoBox.style.display = 'block';
                        infoBox.innerHTML = `
                            <strong>${data.id}</strong><br>
                            Type: ${data.type || 'unknown'}<br>
                            ${selectedDims.x}: ${data[selectedDims.x]?.toFixed(2) || 'N/A'}<br>
                            ${selectedDims.y}: ${data[selectedDims.y]?.toFixed(2) || 'N/A'}<br>
                            ${selectedDims.z}: ${data[selectedDims.z]?.toFixed(2) || 'N/A'}
                        `;
                        canvas.style.cursor = 'pointer';
                    } else {
                        infoBox.style.display = 'none';
                        canvas.style.cursor = 'grab';
                    }
                });
            }

            function onResize() {
                const canvas = document.getElementById('topology-canvas');
                const width = canvas.clientWidth;
                const height = canvas.clientHeight;

                camera.aspect = width / height;
                camera.updateProjectionMatrix();
                renderer.setSize(width, height);
            }

            function animate() {
                requestAnimationFrame(animate);
                renderer.render(scene, camera);
            }

            // Fetch data from REST API
            async function fetchTopology() {
                const tier = document.getElementById('topology-tier')?.value || 'service';
                try {
                    const response = await fetch(`${REST_URL}/topology?tier=${encodeURIComponent(tier)}`, {
                        headers: { 'X-WP-Nonce': NONCE }
                    });
                    return await response.json();
                } catch (e) {
                    console.error('Failed to fetch topology:', e);
                    return null;
                }
            }

            async function fetchSchema() {
                try {
                    const response = await fetch(`${REST_URL}/topology/schema`, {
                        headers: { 'X-WP-Nonce': NONCE }
                    });
                    return await response.json();
                } catch (e) {
                    console.error('Failed to fetch schema:', e);
                    return null;
                }
            }

            // Populate dimension dropdowns
            function populateDimensionSelects() {
                if (!schema || !schema.dimensions) return;

                const dims = Object.keys(schema.dimensions).sort((a, b) => {
                    return (schema.dimensions[a] || 0) - (schema.dimensions[b] || 0);
                });

                ['x', 'y', 'z'].forEach(axis => {
                    const select = document.getElementById(`dim-${axis}`);
                    select.innerHTML = '';
                    dims.forEach(dim => {
                        const opt = document.createElement('option');
                        opt.value = dim;
                        opt.textContent = dim.replace(/_/g, ' ');
                        if (dim === selectedDims[axis]) opt.selected = true;
                        select.appendChild(opt);
                    });
                    select.addEventListener('change', () => {
                        selectedDims[axis] = select.value;
                        updateVisualization();
                    });
                });
            }

            // Build service legend
            function buildLegend() {
                if (!topology || !topology.services) return;

                const container = document.getElementById('legend-items');
                container.innerHTML = '';

                topology.services.forEach(svc => {
                    const type = svc.type || svc.point_data?.metadata?.type || 'default';
                    // Sites (internet-exposed) render gold; internal services cool blue.
                    const color = (svc && (svc.is_site || svc.node_class === 'site')) ? 0xf4d27e : 0x7fc8d8;

                    const item = document.createElement('div');
                    item.className = 'legend-item';
                    item.innerHTML = `
                        <span class="legend-dot" style="background:#${color.toString(16).padStart(6, '0')}"></span>
                        <span class="legend-label" title="${svc.id}">${svc.id}</span>
                    `;
                    item.addEventListener('click', () => highlightService(svc.id));
                    container.appendChild(item);
                });
            }

            function highlightService(id) {
                serviceMeshes.forEach(mesh => {
                    if (mesh.userData.id === id) {
                        mesh.scale.setScalar(2);
                        setTimeout(() => mesh.scale.setScalar(1), 1000);
                    }
                });
            }

            // Update 3D visualization
            function updateVisualization() {
                // Remove existing service meshes
                serviceMeshes.forEach(mesh => scene.remove(mesh));
                serviceMeshes = [];

                if (!topology || !topology.services) return;

                const geometry = new THREE.SphereGeometry(0.04, 16, 16);

                topology.services.forEach(svc => {
                    const pointData = svc.point_data || {};
                    const type = svc.type || pointData.metadata?.type || 'default';
                    // Sites (internet-exposed) render gold; internal services cool blue.
                    const color = (svc && (svc.is_site || svc.node_class === 'site')) ? 0xf4d27e : 0x7fc8d8;

                    // Get coordinates for selected dimensions
                    const x = (pointData[selectedDims.x] || 0);
                    const y = (pointData[selectedDims.y] || 0);
                    const z = (pointData[selectedDims.z] || 0);

                    const material = new THREE.MeshPhongMaterial({
                        color: color,
                        emissive: color,
                        emissiveIntensity: 0.3
                    });

                    const mesh = new THREE.Mesh(geometry, material);
                    mesh.position.set(x, y, z);
                    mesh.userData = {
                        id: svc.id,
                        type: type,
                        ...pointData
                    };

                    scene.add(mesh);
                    serviceMeshes.push(mesh);
                });

                document.getElementById('topology-loading').style.display = 'none';
            }

            // Initialize
            async function init() {
                initScene();

                schema = await fetchSchema();
                populateDimensionSelects();

                topology = await fetchTopology();
                if (topology && topology.error) {
                    document.getElementById('topology-loading').textContent =
                        topology.error + (topology.hint ? ' — ' + topology.hint : '');
                    return;
                }
                buildLegend();
                updateVisualization();
            }

            // Button handlers
            document.getElementById('reset-view').addEventListener('click', () => {
                camera.position.set(2, 2, 2);
                camera.lookAt(0, 0, 0);
            });

            document.getElementById('refresh-data').addEventListener('click', async () => {
                document.getElementById('topology-loading').style.display = 'block';
                topology = await fetchTopology();
                buildLegend();
                updateVisualization();
            });

            // Tier dropdown — re-fetch on change
            document.getElementById('topology-tier').addEventListener('change', async () => {
                document.getElementById('topology-loading').style.display = 'block';
                document.getElementById('topology-loading').textContent = 'Loading topology data...';
                topology = await fetchTopology();
                if (topology && topology.error) {
                    document.getElementById('topology-loading').textContent =
                        topology.error + (topology.hint ? ' — ' + topology.hint : '');
                    return;
                }
                buildLegend();
                updateVisualization();
            });

            // Start
            init();
        })();
        </script>
    </div>
    <?php
}

// ============================================================================
// TRANSLATION META BOXES
// ============================================================================

/**
 * Register translation meta boxes for posts and pages
 */
add_action('add_meta_boxes', function() {
    global $gCore;

    // Check if TranslateManager is available
    $hasTranslation = false;
    if ($gCore) {
        try {
            $translateManager = $gCore->getService('TranslateManager');
            $hasTranslation = $translateManager && method_exists($translateManager, 'isInitialized') && $translateManager->isInitialized();
        } catch (Throwable $e) {
            // TranslateManager not available
        }
    }

    if (!$hasTranslation) {
        return;
    }

    // Add meta box to posts and pages
    $post_types = apply_filters('gcore_translation_post_types', ['post', 'page']);

    foreach ($post_types as $post_type) {
        add_meta_box(
            'gcore-translations',
            __('Translations', 'gcore'),
            'gcore_translation_meta_box_callback',
            $post_type,
            'side',
            'default'
        );
    }
});

/**
 * Translation meta box callback
 */
function gcore_translation_meta_box_callback($post): void {
    global $gCore;

    try {
        $translateManager = $gCore->getService('TranslateManager');
        $supportedLanguages = method_exists($translateManager, 'getSupportedLanguages')
            ? $translateManager->getSupportedLanguages()
            : ['en', 'nl', 'de', 'es', 'fr'];
        $defaultLanguage = method_exists($translateManager, 'getDefaultLanguage')
            ? $translateManager->getDefaultLanguage()
            : 'en';
    } catch (Throwable $e) {
        echo '<p>' . esc_html__('Translation service unavailable.', 'gcore') . '</p>';
        return;
    }

    // Get current post language
    $postLanguage = get_post_meta($post->ID, '_gcore_language', true) ?: $defaultLanguage;

    // Get linked translations
    $translations = get_post_meta($post->ID, '_gcore_translations', true) ?: [];

    wp_nonce_field('gcore_translation_meta', 'gcore_translation_nonce');
    ?>
    <p>
        <label for="gcore-post-language"><strong><?php esc_html_e('Language:', 'gcore'); ?></strong></label>
        <select id="gcore-post-language" name="gcore_post_language" style="width: 100%; margin-top: 5px;">
            <?php foreach ($supportedLanguages as $code): ?>
            <option value="<?php echo esc_attr($code); ?>" <?php selected($postLanguage, $code); ?>>
                <?php echo esc_html(gcore_get_language_name($code)); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p><strong><?php esc_html_e('Linked Translations:', 'gcore'); ?></strong></p>
    <?php if (!empty($translations)): ?>
    <ul style="margin: 5px 0;">
        <?php foreach ($translations as $lang => $linked_post_id): ?>
        <li>
            <a href="<?php echo esc_url(get_edit_post_link($linked_post_id)); ?>">
                <?php echo esc_html(gcore_get_language_name($lang)); ?>
            </a>
            <button type="button" class="button-link" onclick="gcoreUnlinkTranslation('<?php echo esc_attr($lang); ?>')" title="<?php esc_attr_e('Unlink', 'gcore'); ?>">
                <span class="dashicons dashicons-no-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
            </button>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p style="color: #666; font-style: italic;"><?php esc_html_e('No translations linked.', 'gcore'); ?></p>
    <?php endif; ?>

    <p style="margin-top: 10px;">
        <button type="button" class="button" onclick="gcoreAddTranslation()">
            <?php esc_html_e('Link Translation', 'gcore'); ?>
        </button>
    </p>

    <script>
    function gcoreAddTranslation() {
        // Open media-style modal for selecting a post to link
        // For now, simple prompt
        var postId = prompt('<?php echo esc_js(__('Enter Post ID to link as translation:', 'gcore')); ?>');
        if (postId) {
            var lang = prompt('<?php echo esc_js(__('Enter language code (e.g., nl, de, es):', 'gcore')); ?>');
            if (lang) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'gcore_add_translation[' + lang + ']';
                input.value = postId;
                document.getElementById('post').appendChild(input);
                alert('<?php echo esc_js(__('Translation will be linked when you save the post.', 'gcore')); ?>');
            }
        }
    }

    function gcoreUnlinkTranslation(lang) {
        if (confirm('<?php echo esc_js(__('Remove this translation link?', 'gcore')); ?>')) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'gcore_remove_translation[]';
            input.value = lang;
            document.getElementById('post').appendChild(input);
            alert('<?php echo esc_js(__('Translation will be unlinked when you save the post.', 'gcore')); ?>');
        }
    }
    </script>
    <?php
}

/**
 * Save translation meta data
 */
add_action('save_post', function($post_id) {
    // Verify nonce
    if (!isset($_POST['gcore_translation_nonce']) || !wp_verify_nonce($_POST['gcore_translation_nonce'], 'gcore_translation_meta')) {
        return;
    }

    // Check autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Save language
    if (isset($_POST['gcore_post_language'])) {
        update_post_meta($post_id, '_gcore_language', sanitize_text_field($_POST['gcore_post_language']));
    }

    // Get current translations
    $translations = get_post_meta($post_id, '_gcore_translations', true) ?: [];

    // Add new translations
    if (!empty($_POST['gcore_add_translation'])) {
        foreach ($_POST['gcore_add_translation'] as $lang => $linked_id) {
            $lang = sanitize_text_field($lang);
            $linked_id = absint($linked_id);
            if ($linked_id && get_post($linked_id)) {
                $translations[$lang] = $linked_id;

                // Also link back from the other post
                $other_translations = get_post_meta($linked_id, '_gcore_translations', true) ?: [];
                $current_lang = get_post_meta($post_id, '_gcore_language', true) ?: 'en';
                $other_translations[$current_lang] = $post_id;
                update_post_meta($linked_id, '_gcore_translations', $other_translations);
            }
        }
    }

    // Remove translations
    if (!empty($_POST['gcore_remove_translation'])) {
        foreach ($_POST['gcore_remove_translation'] as $lang) {
            $lang = sanitize_text_field($lang);
            if (isset($translations[$lang])) {
                // Also unlink from the other post
                $linked_id = $translations[$lang];
                $other_translations = get_post_meta($linked_id, '_gcore_translations', true) ?: [];
                $current_lang = get_post_meta($post_id, '_gcore_language', true) ?: 'en';
                unset($other_translations[$current_lang]);
                update_post_meta($linked_id, '_gcore_translations', $other_translations);

                unset($translations[$lang]);
            }
        }
    }

    update_post_meta($post_id, '_gcore_translations', $translations);
});

/**
 * Get human-readable language name
 */
function gcore_get_language_name(string $code): string {
    $languages = [
        'en' => 'English',
        'nl' => 'Nederlands',
        'de' => 'Deutsch',
        'fr' => 'Fran&ccedil;ais',
        'es' => 'Espa&ntilde;ol',
        'it' => 'Italiano',
        'pt' => 'Portugu&ecirc;s',
        'pl' => 'Polski',
        'ru' => 'Русский',
        'ja' => '日本語',
        'zh' => '中文',
        'ko' => '한국어',
        'ar' => 'العربية',
    ];

    return $languages[$code] ?? strtoupper($code);
}

// ============================================================================
// MISC HOOKS
// ============================================================================

/**
 * Load text domain for translations
 */
add_action('plugins_loaded', function() {
    load_muplugin_textdomain('gcore', 'gcore-mu/languages');
});

/**
 * Clean up on deactivation
 */
register_deactivation_hook(GCORE_MU_DIR . 'gcore-loader.php', function() {
    delete_transient('gcore_status_cache');
    delete_transient('gcore_services_cache');
    flush_rewrite_rules();
});
