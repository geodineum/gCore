<?php
/**
 * gCore Standalone Entry Point
 *
 * For non-WordPress / non-Apache applications using gCore with optional
 * gNode integration (CLI tools, cron jobs, dashboard services).
 *
 * Delegates ALL platform-agnostic setup to bootstrap.php — the SAME
 * canonical loader the WordPress path uses (gcore-mu/gcore-loader.php).
 * One bootstrap, two entry points. The previous version of this file
 * reimplemented a stripped-down setup that skipped the ecosystem config
 * loader, the path-constant resolution, the explicit core requires, the
 * extension checks, AND the gNode-Client autoloader probe — so every
 * manager silently degraded to "WITHOUT gNode" legacy mode in any
 * standalone deployment, with no signal to the caller.
 *
 * What bootstrap.php provides here:
 *   - EcosystemConfigLoader (Tier 1 /etc/geodineum/bootstrap.env →
 *     Tier 2 ValKey geodineum:bootstrap:* → Tier 3 gcore.env overlay)
 *   - GCORE_BASE_PATH / LIB / LOG / CACHE / CONFIG constants (env-first)
 *   - Composer autoload (3-path probe) — which also runs
 *     Modules/Managers/Stubs/register.php, populating ExtensionResolver's
 *     Pro/Base/Stub registry
 *   - Explicit core requires (side-effect imports: error handler,
 *     schema registry)
 *   - gNode-Client autoloader probe — without it
 *     class_exists('\gCore\gNode\gNodeClient') is false and topology
 *     falls back to YAML silently
 *   - PHP version + extension checks, gcore_bootstrap_log()
 *   - gcore_get_error_manager / cache / security / api accessors
 *     (function_exists-guarded there — NOT duplicated here)
 *   - WordPress compatibility bridge — conditional on
 *     function_exists('add_action'), so it self-skips in standalone
 *
 * Usage:
 * ```php
 * require_once '/opt/geodineum/gCore/gcore-standalone.php';
 *
 * $gCore = gcore_init([
 *     'site_id' => 'my_standalone_app',          // required
 *     'environment' => 'production',             // testing|staging|acceptance|production
 * ]);
 *
 * $gNodeClient = $gCore->getService('gnode_client');
 * $cache = $gCore->getService('CacheManager');
 * ```
 *
 * gNode-Client handles all credential resolution automatically:
 * - ValKey port: 47445 (default)
 * - ValKey user: gnode_client_{site_id}
 * - Password: auto-discovered from the centralized credential store or env
 *
 * @package gCore
 * @version 3.0.0
 */

// ─── 1. Canonical bootstrap (shared with the WordPress path) ───────────
// Safe in standalone: bootstrap.php's ABSPATH self-define resolves to
// this same directory, and its WP bridge is conditional on
// function_exists('add_action').
require_once __DIR__ . '/bootstrap.php';

// ─── 2. Standalone-specific public API ─────────────────────────────────
// The WP path triggers initialize() from the first cacheable request
// (site_id derived from HTTP_HOST). Standalone contexts (CLI, cron,
// non-web services) have no HTTP_HOST — gcore_init() is the explicit
// trigger with a hand-supplied site_id.

if (!function_exists('gcore_init')) {
    /**
     * Initialize gCore for standalone (non-WordPress) use
     *
     * @param array<string, mixed> $config Configuration options:
     *   - site_id: (required) Site identifier (e.g., 'my_api_service')
     *   - environment: DTAP environment (testing|staging|acceptance|production)
     *   - node_id: Node identifier (default: hostname)
     *   - debug: Enable debug logging (default: false)
     *
     * @return \gCore\Modules\Core\gCore Initialized gCore instance
     * @throws \InvalidArgumentException If site_id is missing
     * @throws \gCore\Modules\Core\Exceptions\InitializationException If initialization fails
     */
    function gcore_init(array $config = []): \gCore\Modules\Core\gCore
    {
        // site_id is required for proper multi-tenant isolation
        if (empty($config['site_id'])) {
            throw new \InvalidArgumentException(
                'site_id is required for standalone mode. ' .
                'Example: gcore_init(["site_id" => "my_api_service"])'
            );
        }

        $defaultConfig = [
            'core' => [
                'environment' => $config['environment'] ?? 'development',
                'debug' => $config['debug'] ?? false,
            ],
            'site_id' => $config['site_id'],
            'node_id' => $config['node_id'] ?? (gethostname() ?: 'standalone'),

            // gNode-Client handles ALL credential/connection details —
            // no storage/redis config needed here.
        ];

        // Merge user config (user values override defaults)
        $mergedConfig = array_replace_recursive($defaultConfig, $config);

        // gCore singleton initializes gNodeClient via initializegNodeClient()
        $gCore = \gCore\Modules\Core\gCore::getInstance();
        $gCore->initialize($mergedConfig);

        return $gCore;
    }
}

if (!function_exists('gcore')) {
    /**
     * Get gCore instance (must be initialized first)
     *
     * @return \gCore\Modules\Core\gCore
     * @throws \RuntimeException If not initialized
     */
    function gcore(): \gCore\Modules\Core\gCore
    {
        $gCore = \gCore\Modules\Core\gCore::getInstance();
        if (!$gCore->isInitialized()) {
            throw new \RuntimeException(
                'gCore not initialized. Call gcore_init() first.'
            );
        }
        return $gCore;
    }
}

if (!function_exists('gcore_shutdown')) {
    /**
     * Gracefully shut down gCore (flushes state, closes connections)
     */
    function gcore_shutdown(bool $graceful = true): bool
    {
        $gCore = \gCore\Modules\Core\gCore::getInstance();
        return $gCore->isInitialized() ? $gCore->shutdown($graceful) : true;
    }
}

if (!function_exists('gcore_is_initialized')) {
    function gcore_is_initialized(): bool
    {
        return \gCore\Modules\Core\gCore::getInstance()->isInitialized();
    }
}

if (!function_exists('gcore_is_wordpress')) {
    /**
     * True only in a REAL WordPress context — bootstrap.php defines
     * ABSPATH unconditionally, so the function check is the part that
     * actually discriminates.
     */
    function gcore_is_wordpress(): bool
    {
        return defined('ABSPATH')
            && defined('WP_CONTENT_DIR')
            && function_exists('add_action');
    }
}

// ─── 3. Accessors NOT provided by bootstrap.php ────────────────────────
// (gcore_get_error_manager, gcore_get_cache_manager,
//  gcore_get_security_manager, gcore_get_api_manager live in
//  bootstrap.php with function_exists guards — do not duplicate.)

if (!function_exists('gcore_get_gnode_client')) {
    function gcore_get_gnode_client(): ?\gCore\gNode\gNodeClient
    {
        $g = \gCore\Modules\Core\gCore::getInstance();
        if (!$g->isInitialized() || !$g->hasService('gnode_client')) {
            return null;
        }
        return $g->getService('gnode_client');
    }
}

if (!function_exists('gcore_get_template_manager')) {
    function gcore_get_template_manager(): ?object
    {
        $g = \gCore\Modules\Core\gCore::getInstance();
        if (!$g->isInitialized() || !$g->hasService('TemplateManager')) {
            return null;
        }
        return $g->getService('TemplateManager');
    }
}

if (!function_exists('gcore_get_topology_manager')) {
    function gcore_get_topology_manager(): ?object
    {
        $g = \gCore\Modules\Core\gCore::getInstance();
        if (!$g->isInitialized() || !$g->hasService('TopologyManager')) {
            return null;
        }
        return $g->getService('TopologyManager');
    }
}

if (!function_exists('gcore_get_format_manager')) {
    function gcore_get_format_manager(): ?object
    {
        $g = \gCore\Modules\Core\gCore::getInstance();
        if (!$g->isInitialized() || !$g->hasService('FormatManager')) {
            return null;
        }
        return $g->getService('FormatManager');
    }
}

// Register automatic graceful shutdown
register_shutdown_function('gcore_shutdown');
