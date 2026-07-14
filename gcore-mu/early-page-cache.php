<?php
/**
 * Early Full-Page Cache via gNode-Client
 *
 * Runs at mu-plugin stage to serve cached pages before WordPress fully loads.
 * Uses gCore's gNode-Client with ACL authentication for secure cache access.
 *
 * PERFORMANCE GAIN: Serving from cache here saves ~80ms of WordPress initialization.
 *
 * @package gCore
 * @since 2.1.0
 */

// Skip non-cacheable requests early (before any initialization)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') return;
if (defined('WP_CLI') && WP_CLI) return;
if (defined('DOING_CRON') && DOING_CRON) return;
if (defined('DOING_AJAX') && DOING_AJAX) return;
if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;

$uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($uri, '/wp-admin') !== false) return;
if (strpos($uri, '/wp-login') !== false) return;
if (strpos($uri, '/wp-signup') !== false) return;
if (strpos($uri, '/wp-cron') !== false) return;
if (strpos($uri, '/wp-json') !== false) return;

// Skip for logged-in users (check cookies before WordPress loads)
foreach ($_COOKIE as $key => $value) {
    if (strpos($key, 'wordpress_logged_in') === 0) return;
    if (strpos($key, 'comment_author') === 0) return;
    // Gate-bypass cookies: their holders see a DIFFERENT render at the same
    // URL (environment gate / findings dashboard). Serving them the cached
    // anonymous page traps them at the gate forever. Mirrors the writer-side
    // list in gTemplate full-page-cache.php; keep the two in sync.
    if ($key === 'gcore_viewkey') return;
    if ($key === 'gan_access') return;
}

// Skip for nocache query params
$nocache_params = ['preview', 'customize_changeset_uuid', 'wp-preview', 'nocache'];
foreach ($nocache_params as $param) {
    if (isset($_GET[$param])) return;
}

/**
 * Detect site_id from HTTP_HOST (must match the theme-side page cache)
 */
function gcore_early_cache_get_site_id(): string {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // Clean domain
    $host = preg_replace('/^www\./', '', $host);
    $host = preg_replace('/:[0-9]+$/', '', $host);
    // Convert to safe ID (dots and hyphens become underscores)
    return str_replace(['.', '-'], '_', $host);
}

/**
 * Generate cache key (must match the theme-side page cache)
 */
function gcore_early_cache_get_key(): string {
    $site_id = gcore_early_cache_get_site_id();

    $path = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed = parse_url($path);
    $clean_path = $parsed['path'] ?? '/';

    // Include sorted query string
    $query_parts = [];
    if (!empty($_GET)) {
        $get_copy = $_GET;
        ksort($get_copy);
        foreach ($get_copy as $k => $v) {
            // Skip tracking/analytics params
            if (in_array($k, ['utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'gclid', 'ref'])) {
                continue;
            }
            $query_parts[] = urlencode($k) . '=' . urlencode($v);
        }
    }

    $full_path = $clean_path;
    if (!empty($query_parts)) {
        $full_path .= '?' . implode('&', $query_parts);
    }

    $path_hash = md5($full_path);
    return "{{$site_id}}:cache:page:{$path_hash}";
}

/**
 * Detect environment from domain — delegates to the canonical DTAP
 * prefix rules (gcore-mu/dtap-rules.php) so the pre-bootstrap cache and
 * gCore proper can never diverge. The former inline substring check
 * mis-mapped dev. to staging and matched tokens inside bare domains.
 */
function gcore_early_cache_detect_environment(): string {
    require_once __DIR__ . '/dtap-rules.php';
    return gcore_dtap_environment_from_host($_SERVER['HTTP_HOST'] ?? '');
}

/**
 * Initialize gCore minimally for cache lookup
 */
function gcore_early_cache_init_gcore(): ?\gCore\gNode\gNodeClient {
    global $gCore;

    if (!$gCore) {
        return null;
    }

    // Check if already initialized
    if ($gCore->isInitialized()) {
        try {
            return $gCore->getService('gnode_client');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Initialize gCore with minimal config for cache lookup
    $site_id = gcore_early_cache_get_site_id();
    $environment = gcore_early_cache_detect_environment();

    try {
        $gCore->initialize([
            'core' => [
                'environment' => 'wordpress',
                'debug' => false,
                'site_id' => $site_id,
                'node_id' => 'web-' . gethostname()
            ],
            'site_id' => $site_id,
            'gnode_environment' => $environment,
            'gnode_stream_prefix' => 'gnode',
            'gnode_cache_enabled' => true,
            'gnode_cache_ttl' => 60,
            // Disable heavy features for early cache
            'modules' => [
                'TopologyManager' => ['enabled' => false],
            ]
        ]);

        // Mark as initialized for cache (theme can enhance later)
        define('GCORE_EARLY_CACHE_INIT', true);

        return $gCore->getService('gnode_client');
    } catch (\Throwable $e) {
        error_log('[gCore EarlyCache] Init failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Try to serve cached page via gNode-Client
 *
 * On cache hit: sends ETag + Cache-Control headers so browsers and CDNs
 * can cache the response. Subsequent requests with matching ETag get
 * a 304 Not Modified (zero bytes transferred).
 */
function gcore_early_cache_serve(): void {
    $gNodeClient = gcore_early_cache_init_gcore();
    if (!$gNodeClient) {
        return; // Let WordPress handle
    }

    $cache_key = gcore_early_cache_get_key();
    $site_id = gcore_early_cache_get_site_id();

    try {
        $cached = $gNodeClient->fcall('GNODE_CACHE_GET', [], [$cache_key, $site_id]);

        if ($cached && is_string($cached)) {
            $data = json_decode($cached, true);
            if ($data && !empty($data['html'])) {
                $cached_at = $data['cached_at'] ?? time();
                $etag = '"' . md5($cache_key . ':' . $cached_at) . '"';

                // 304 Not Modified — browser already has this version
                $if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
                if ($if_none_match === $etag) {
                    http_response_code(304);
                    header('ETag: ' . $etag);
                    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
                    header('X-Cache: HIT-EARLY-304');
                    exit;
                }

                // Full response with HTTP caching headers
                header('Content-Type: text/html; charset=utf-8');
                header('ETag: ' . $etag);
                // max-age=60: browser uses cached version for 60s without asking
                // stale-while-revalidate=300: after 60s, serve stale while fetching fresh
                header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
                header('X-Cache: HIT-EARLY');
                header('X-Cache-Age: ' . (time() - $cached_at));

                // Send cached headers if any
                if (!empty($data['headers'])) {
                    foreach ($data['headers'] as $header) {
                        header($header);
                    }
                }

                echo $data['html'];
                exit;
            }
        }
    } catch (\Throwable $e) {
        // Silently fail - let WordPress handle
    }
}

// Run early cache check
gcore_early_cache_serve();
