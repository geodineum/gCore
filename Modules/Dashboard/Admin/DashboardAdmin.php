<?php
declare(strict_types=1);

namespace gCore\Modules\Dashboard\Admin;

use Throwable;

/**
 * gDash — Geodineum Dashboard admin host.
 *
 * Owns the operator-facing observability surface that ships pre-launch:
 *
 *   - Sites           : per-site card grid + detail
 *   - Services        : flat tabular list with per-service request counters
 *   - Streams & Keys  : SCAN-cursor explorer with shape-aware render
 *   - ACL Inspector   : read-only ACL LIST view (graceful empty state on NOPERM)
 *   - Service Logs    : journalctl tail per allowlisted unit
 *   - Actions         : placeholder
 *
 * Also exposes the extension panel-registration contract:
 *
 *   gDash::register_panel([
 *       'slug'       => 'myext-overview',
 *       'title'      => 'MyExt Overview',
 *       'menu_title' => 'MyExt',
 *       'callback'   => [\Vendor\MyExt\Admin::class, 'renderOverview'],
 *       'icon'       => 'dashicons-shield',
 *       'priority'   => 30,
 *       'capability' => 'manage_options',
 *   ]);
 *
 * See README.md for full contract docs.
 *
 * @package gCore
 * @subpackage Modules\Dashboard\Admin
 */
class DashboardAdmin
{
    /** Top-level admin slug (matches wp-hooks.php menu registration). */
    public const PARENT_SLUG = 'gcore-dashboard';

    /** Allowlist of systemd units operators may tail via the Service Logs page. */
    private const JOURNAL_UNIT_ALLOWLIST = [
        'gnode-daemon',
        'geodineum-comms',
        'valkey-gnode',
        'valkey-constellation',
        'gcore-cache-warmer',
    ];

    /** Default admin sub-menu priority (lower = higher in the list). */
    private const DEFAULT_PRIORITY = 50;

    private static ?self $instance = null;

    /**
     * External panels registered via {@see register_panel()}.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $registeredPanels = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenus'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('heartbeat_received', [$this, 'extendHeartbeat'], 10, 2);
        add_action('wp_loaded', [$this, 'enforceViewKeyGate']);
    }

    // =========================================================================
    // EXTENSION PANEL-REGISTRATION CONTRACT
    // =========================================================================

    /**
     * Register a panel under the gDash menu.
     *
     * Required fields: slug, title, callback.
     * Optional: menu_title, icon, priority, capability, parent.
     *
     * Idempotent: registering the same slug twice last-write-wins with a
     * notice in the error log. Validation is hard-fail in WP_DEBUG and
     * soft-warn otherwise so a misbehaving extension module cannot brick
     * the dashboard for the operator.
     */
    public static function register_panel(array $args): void
    {
        $required = ['slug', 'title', 'callback'];
        foreach ($required as $field) {
            if (empty($args[$field])) {
                self::reportPanelError(
                    sprintf('register_panel: missing required field "%s"', $field),
                    $args
                );
                return;
            }
        }

        if (!is_callable($args['callback'])) {
            self::reportPanelError('register_panel: callback is not callable', $args);
            return;
        }

        $slug = (string) $args['slug'];

        if (isset(self::$registeredPanels[$slug])) {
            error_log(sprintf(
                '[gDash] register_panel: slug "%s" already registered; overwriting',
                $slug
            ));
        }

        self::$registeredPanels[$slug] = [
            'slug'       => $slug,
            'title'      => (string) $args['title'],
            'menu_title' => (string) ($args['menu_title'] ?? $args['title']),
            'callback'   => $args['callback'],
            'icon'       => (string) ($args['icon'] ?? ''),
            'priority'   => (int)    ($args['priority'] ?? self::DEFAULT_PRIORITY),
            'capability' => (string) ($args['capability'] ?? 'manage_options'),
            'parent'     => (string) ($args['parent'] ?? self::PARENT_SLUG),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_panels(): array
    {
        return self::$registeredPanels;
    }

    private static function reportPanelError(string $message, array $args): void
    {
        $slug = isset($args['slug']) ? (string) $args['slug'] : '<no-slug>';
        $full = sprintf('[gDash] %s (slug=%s)', $message, $slug);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die(esc_html($full));
        }
        error_log($full);
    }

    // =========================================================================
    // MENU REGISTRATION
    // =========================================================================

    public function registerMenus(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->registerInternalPages();
        $this->registerExternalPanels();
    }

    private function registerInternalPages(): void
    {
        // Order matters — earlier add_submenu_page calls appear higher in the
        // sidebar within the parent. Internal pages slot between the existing
        // Status / Diagnostics / Topology 3D entries (registered in
        // wp-hooks.php) and any extension panels (registered later).

        add_submenu_page(
            self::PARENT_SLUG,
            __('Sites', 'gcore'),
            __('Sites', 'gcore'),
            'manage_options',
            'gcore-sites',
            [$this, 'renderSitesPage']
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __('Services', 'gcore'),
            __('Services', 'gcore'),
            'manage_options',
            'gcore-services-list',
            [$this, 'renderServicesListPage']
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __('Streams & Keys', 'gcore'),
            __('Streams & Keys', 'gcore'),
            'manage_options',
            'gcore-streams-keys',
            [$this, 'renderStreamsKeysPage']
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __('ACL Inspector', 'gcore'),
            __('ACL Inspector', 'gcore'),
            'manage_options',
            'gcore-acl-inspector',
            [$this, 'renderAclInspectorPage']
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __('Service Logs', 'gcore'),
            __('Service Logs', 'gcore'),
            'manage_options',
            'gcore-service-logs',
            [$this, 'renderServiceLogsPage']
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __('Actions', 'gcore'),
            __('Actions', 'gcore'),
            'manage_options',
            'gcore-actions',
            [$this, 'renderActionsPlaceholderPage']
        );
    }

    private function registerExternalPanels(): void
    {
        // Sort by priority then slug for stable ordering across requests.
        $panels = self::$registeredPanels;
        uasort($panels, static function ($a, $b) {
            return [$a['priority'], $a['slug']] <=> [$b['priority'], $b['slug']];
        });

        foreach ($panels as $panel) {
            if (!current_user_can($panel['capability'])) {
                continue;
            }

            add_submenu_page(
                $panel['parent'],
                $panel['title'],
                $panel['menu_title'],
                $panel['capability'],
                $panel['slug'],
                $panel['callback']
            );
        }
    }

    // =========================================================================
    // ASSET ENQUEUE
    // =========================================================================

    public function enqueueAssets($hook): void
    {
        if (!is_string($hook) || (strpos($hook, 'gcore') === false && strpos($hook, 'geodineum') === false)) {
            return;
        }

        $version = defined('GCORE_VERSION') ? (string) GCORE_VERSION : '1.0.0';

        wp_enqueue_style(
            'gdash-admin',
            GCORE_MU_URL . 'assets/dashboard.css',
            [],
            $version
        );

        // three.js is only needed by the Topology 3D page. We enqueue in the
        // document head (in_footer = false) because the topology page renders
        // an inline visualization script in the body that depends on `THREE`
        // being defined at parse time.
        if (strpos($hook, 'gcore-topology') !== false || strpos($hook, 'gcore-services-list') !== false) {
            wp_enqueue_script(
                'gdash-three',
                GCORE_MU_URL . 'assets/three.min.js',
                [],
                'r128',
                false
            );
            wp_enqueue_script(
                'gdash-topology',
                GCORE_MU_URL . 'assets/gdash-topology.js',
                ['gdash-three'],
                $version,
                true
            );
        }
    }

    // =========================================================================
    // HEARTBEAT PAYLOAD EXTENSION
    // =========================================================================

    /**
     * Augment the WP heartbeat payload with gDash metrics.
     *
     * The existing `gcore_gnode_status` channel is owned by wp-hooks.php; we
     * publish under a parallel `gcore_dashboard_metrics` channel so the two
     * surfaces stay decoupled.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function extendHeartbeat($response, $data): array
    {
        if (empty($data['gcore_dashboard_metrics'])) {
            return is_array($response) ? $response : [];
        }
        if (!is_array($response)) {
            $response = [];
        }

        $response['gcore_dashboard_metrics'] = [
            'sites_count'    => $this->countSites(),
            'cache'          => $this->cacheHitRate($this->resolveSiteId()),
            'timestamp'      => time(),
        ];

        return $response;
    }

    // =========================================================================
    // VIEWKEY GATE (non-prod only)
    // =========================================================================

    /**
     * Enforce the ViewKey gate for non-production wp-admin requests.
     *
     * The ViewKey schema is declared (commented) in
     * gCore/config/sites/example.yaml:49-51 — site config may publish
     *   security:
     *     viewkey: "secret-preview-key"
     *     viewkey_expiry: 86400  # seconds
     *
     * Cookie + hash + validation primitives delegated to
     * gCore\Modules\Core\Utils\ViewKeyGate (ROADMAP §B.4 — shared with
     * gTemplate's public-facing EnvironmentGate so a single
     * authenticated session covers both wp-admin and the public splash
     * on a non-prod site).
     *
     * Visitor presents the viewkey via `?viewkey=<value>` query param
     * (one-shot — sets a hashed cookie then redirects clean) or via the
     * `gcore_viewkey` cookie. Production environments are never gated;
     * cookie storage uses a salted SHA-256 hash so a stolen cookie
     * does not leak the secret.
     */
    public function enforceViewKeyGate(): void
    {
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if (!function_exists('wp_get_current_user')) {
            return;
        }

        $env = $this->resolveEnvironment();
        if ($env === 'production' || $env === 'prod') {
            return; // never gate production
        }

        $viewkey = $this->resolveSiteViewKey();
        if ($viewkey === '') {
            return; // no viewkey configured → no gate
        }

        $cookieName = \gCore\Modules\Core\Utils\ViewKeyGate::DEFAULT_COOKIE_NAME;

        // One-shot: ?viewkey=<value> validates, sets cookie, redirects clean.
        if (isset($_GET['viewkey']) && is_string($_GET['viewkey'])) {
            $candidate = (string) wp_unslash($_GET['viewkey']);
            if (\gCore\Modules\Core\Utils\ViewKeyGate::validate($viewkey, $candidate)) {
                \gCore\Modules\Core\Utils\ViewKeyGate::setCookie(
                    $cookieName,
                    $viewkey,
                    $this->resolveSiteViewKeyExpiry()
                );
                wp_safe_redirect(remove_query_arg('viewkey'));
                exit;
            }
        }

        if (\gCore\Modules\Core\Utils\ViewKeyGate::cookieMatches($cookieName, $viewkey)) {
            return; // gate satisfied
        }

        // Reject — bounce to the public site root with no detail leak.
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    private function resolveEnvironment(): string
    {
        if (defined('WP_ENVIRONMENT_TYPE')) {
            $env = (string) WP_ENVIRONMENT_TYPE;
            if ($env !== '') {
                return $env;
            }
        }
        $env = (string) (getenv('GEODINEUM_ENV') ?: '');
        if ($env !== '') {
            return $env;
        }
        return 'production'; // safe default — never gate when ambiguous
    }

    private function resolveSiteViewKey(): string
    {
        $client = $this->getGNodeClient();
        if (!$client) {
            return '';
        }
        try {
            $siteId = $this->resolveSiteId();
            if ($siteId === '') {
                return '';
            }
            $viewkey = $client->fcall(
                'GNODE_CONFIG_GET',
                [],
                [$siteId, 'security', 'viewkey']
            );
            return is_string($viewkey) ? $viewkey : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    private function resolveSiteViewKeyExpiry(): int
    {
        $client = $this->getGNodeClient();
        if (!$client) {
            return 0;
        }
        try {
            $siteId = $this->resolveSiteId();
            if ($siteId === '') {
                return 0;
            }
            $expiry = $client->fcall(
                'GNODE_CONFIG_GET_INT',
                [],
                [$siteId, 'security', 'viewkey_expiry']
            );
            return is_numeric($expiry) ? max(0, (int) $expiry) : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    // =========================================================================
    // PAGE RENDERS — SITES
    // =========================================================================

    public function renderSitesPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'gcore'));
        }

        $sites = $this->getSites();
        echo '<div class="wrap gdash">';
        echo '<h1>' . esc_html__('Geodineum Dashboard · Sites', 'gcore') . '</h1>';
        echo '<p class="description">' . esc_html__(
            'Per-site card grid. Click a card for live config + cog-load + metrics. All keys hash-tagged to {site_id}: per cluster-safety invariant.',
            'gcore'
        ) . '</p>';

        if (empty($sites)) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('No sites in registry. SMEMBERS gnode:sites:registry returned empty.', 'gcore')
                . '</p></div></div>';
            return;
        }

        $detail = isset($_GET['site']) ? sanitize_key((string) $_GET['site']) : '';
        if ($detail !== '' && in_array($detail, $sites, true)) {
            $this->renderSiteDetail($detail);
        } else {
            $this->renderSiteCardGrid($sites);
        }
        echo '</div>';
    }

    /** @param string[] $sites */
    private function renderSiteCardGrid(array $sites): void
    {
        echo '<div class="gdash-card-grid">';
        foreach ($sites as $siteId) {
            $meta  = $this->getSiteMeta($siteId);
            $cache = $this->cacheHitRate($siteId);
            $url   = esc_url(admin_url('admin.php?page=gcore-sites&site=' . urlencode($siteId)));

            echo '<a class="gdash-card" href="' . $url . '">';
            echo '<div class="gdash-card-title"><code>' . esc_html($siteId) . '</code></div>';

            $env = isset($meta['environment']) ? (string) $meta['environment'] : 'unknown';
            echo '<div class="gdash-card-row"><span>' . esc_html__('Environment', 'gcore') . '</span>'
                . '<strong>' . esc_html($env) . '</strong></div>';

            $theme = isset($meta['theme']) ? (string) $meta['theme'] : '—';
            echo '<div class="gdash-card-row"><span>' . esc_html__('Theme', 'gcore') . '</span>'
                . '<strong>' . esc_html($theme) . '</strong></div>';

            if ($cache['total'] > 0) {
                echo '<div class="gdash-card-row"><span>' . esc_html__('Cache hit-rate', 'gcore') . '</span>'
                    . '<strong>' . esc_html(number_format($cache['rate'] * 100, 1)) . '%</strong></div>';
            }
            echo '</a>';
        }
        echo '</div>';
    }

    private function renderSiteDetail(string $siteId): void
    {
        $back = esc_url(admin_url('admin.php?page=gcore-sites'));
        echo '<p><a href="' . $back . '">&larr; ' . esc_html__('All sites', 'gcore') . '</a></p>';
        echo '<h2><code>' . esc_html($siteId) . '</code></h2>';

        $meta  = $this->getSiteMeta($siteId);
        $cache = $this->cacheHitRate($siteId);

        $sections = [
            __('Site metadata', 'gcore') => $meta,
            __('Cache metrics', 'gcore') => $cache['raw'],
        ];

        foreach ($sections as $heading => $rows) {
            echo '<h3>' . esc_html($heading) . '</h3>';
            if (empty($rows)) {
                echo '<p class="description">' . esc_html__('(empty)', 'gcore') . '</p>';
                continue;
            }
            echo '<table class="widefat striped" style="max-width: 720px; margin-bottom: 1.5em;">';
            echo '<thead><tr><th>' . esc_html__('Field', 'gcore') . '</th><th>'
                . esc_html__('Value', 'gcore') . '</th></tr></thead><tbody>';
            ksort($rows);
            foreach ($rows as $k => $v) {
                $vStr = is_scalar($v) ? (string) $v : (string) wp_json_encode($v);
                echo '<tr><td><code>' . esc_html((string) $k) . '</code></td>'
                    . '<td><code>' . esc_html($vStr) . '</code></td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    // =========================================================================
    // PAGE RENDERS — SERVICES LIST
    // =========================================================================

    public function renderServicesListPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'gcore'));
        }

        echo '<div class="wrap gdash">';
        echo '<h1>' . esc_html__('Geodineum Dashboard · Services', 'gcore') . '</h1>';
        echo '<p class="description">' . esc_html__(
            'Service inventory from the gNode topology. Per-fcall invocation counts (instrumented at the gNode-Client FCALL chokepoint) appear in the FCALL Hot-list section below.',
            'gcore'
        ) . '</p>';

        $client = $this->getGNodeClient();
        if (!$client) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('gNode client not available.', 'gcore') . '</p></div></div>';
            return;
        }

        $services = [];
        try {
            $topology = $client->getTopology();
            if (is_array($topology) && isset($topology['services']) && is_array($topology['services'])) {
                foreach ($topology['services'] as $id => $svc) {
                    $services[(string) $id] = is_array($svc) ? $svc : [];
                }
            }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>'
                . esc_html(sprintf(__('Topology fetch failed: %s', 'gcore'), $e->getMessage()))
                . '</p></div>';
        }

        // 3D constellation cone — apex trio (ValKey/gMath/gNode) over the
        // registered components, every edge converging up to gNode.
        $this->renderTopologyCone($services);

        if (!empty($services)) {
            echo '<h2>' . esc_html__('Topology services', 'gcore') . '</h2>';
            echo '<table class="widefat striped" style="max-width: 900px; margin-bottom: 1.5em;">';
            echo '<thead><tr>'
                . '<th>' . esc_html__('Service', 'gcore') . '</th>'
                . '<th>' . esc_html__('Tier', 'gcore') . '</th>'
                . '<th>' . esc_html__('Type', 'gcore') . '</th>'
                . '</tr></thead><tbody>';

            ksort($services);
            foreach ($services as $id => $svc) {
                $meta = isset($svc['metadata']) && is_array($svc['metadata']) ? $svc['metadata'] : [];
                $tier = (string) ($meta['tier'] ?? '—');
                $type = (string) ($meta['type'] ?? '—');
                echo '<tr>'
                    . '<td><code>' . esc_html((string) $id) . '</code></td>'
                    . '<td>' . esc_html($tier) . '</td>'
                    . '<td>' . esc_html($type) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No services in topology.', 'gcore') . '</p>';
        }

        $siteId = $this->resolveSiteId();
        $hot = $this->topFcallFunctions($siteId, 20);
        echo '<h2>' . esc_html__('FCALL hot-list', 'gcore') . '</h2>';
        echo '<p class="description">'
            . esc_html__('Top function invocations on this site since the dashboard was wired. Source: {site_id}:metrics:requests:fcalls:<function>.', 'gcore')
            . '</p>';
        if (empty($hot)) {
            echo '<p>' . esc_html__('No FCALL counters yet — instrumentation may have just landed.', 'gcore') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width: 600px;">';
            echo '<thead><tr><th>' . esc_html__('Function', 'gcore') . '</th>'
                . '<th>' . esc_html__('Invocations', 'gcore') . '</th></tr></thead><tbody>';
            foreach ($hot as $fn => $count) {
                echo '<tr>'
                    . '<td><code>' . esc_html((string) $fn) . '</code></td>'
                    . '<td style="text-align:right;font-variant-numeric:tabular-nums;">'
                    . esc_html(number_format((int) $count)) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /**
     * 3D constellation cone. Apex trio (ValKey / gMath / gNode) sits above the
     * registered components, every component edge converging up to gNode —
     * because nothing talks directly; everything flows through the hub. Emits a
     * three.js host div + the graph JSON consumed by gdash-topology.js. The
     * table below (rendered by the caller) is the accessible fallback.
     */
    private function renderTopologyCone(array $services): void
    {
        $nodes = [
            ['id' => 'valkey', 'label' => 'ValKey', 'role' => 'core', 'sub' => 'datastore'],
            ['id' => 'gnode',  'label' => 'gNode',  'role' => 'core', 'sub' => 'orchestrator'],
            ['id' => 'gmath',  'label' => 'gMath',  'role' => 'core', 'sub' => 'compute'],
        ];
        $edges = [
            ['from' => 'valkey', 'to' => 'gnode'],
            ['from' => 'gmath',  'to' => 'gnode'],
        ];
        foreach ($services as $id => $svc) {
            $meta = isset($svc['metadata']) && is_array($svc['metadata']) ? $svc['metadata'] : [];
            $nodes[] = [
                'id'    => (string) $id,
                'label' => (string) $id,
                'role'  => 'component',
                'type'  => (string) ($meta['type'] ?? ''),
                'tier'  => (string) ($meta['tier'] ?? ''),
            ];
            $edges[] = ['from' => (string) $id, 'to' => 'gnode'];
        }

        // Live FCALL volume drives the edge-flow speed + apex pulse in the scene.
        $hot = $this->topFcallFunctions($this->resolveSiteId(), 50);
        $activityTotal = 0;
        foreach ($hot as $count) {
            $activityTotal += (int) $count;
        }

        echo '<div class="gdash-topo-wrap">';
        echo '<div id="gdash-topo" class="gdash-topo" role="img" aria-label="'
            . esc_attr__('3D constellation topology with gNode at the centre', 'gcore') . '">';
        echo '<div class="gdash-topo-hint">' . esc_html__('drag to rotate', 'gcore') . '</div>';
        echo '</div>';
        echo '<p class="gdash-topo-teaser">'
            . esc_html__('Read-only view. Point-and-click inter-service wiring arrives in', 'gcore')
            . ' <strong>' . esc_html__('Chapter 2 of our journey', 'gcore') . '</strong> — '
            . esc_html__('stay tuned.', 'gcore') . '</p>';
        echo '<script>window.GDASH_TOPOLOGY = ' . wp_json_encode([
            'nodes'    => $nodes,
            'edges'    => $edges,
            'activity' => ['total' => $activityTotal, 'functions' => count($hot)],
        ]) . ';</script>';
        echo '</div>';
    }

    // =========================================================================
    // PAGE RENDERS — STREAMS & KEYS
    // =========================================================================

    public function renderStreamsKeysPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'gcore'));
        }

        echo '<div class="wrap gdash">';
        echo '<h1>' . esc_html__('Geodineum Dashboard · Streams &amp; Keys', 'gcore') . '</h1>';
        echo '<p class="description">' . esc_html__(
            'SCAN-cursor explorer (never KEYS). Keys grouped by prefix. Click a key for shape-aware render.',
            'gcore'
        ) . '</p>';

        $client = $this->getGNodeClient();
        if (!$client) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('gNode client not available.', 'gcore') . '</p></div></div>';
            return;
        }

        $patternRaw = isset($_GET['pattern']) ? (string) wp_unslash($_GET['pattern']) : '*';
        $pattern    = $this->sanitizePattern($patternRaw);
        $keyRaw     = isset($_GET['key']) ? (string) wp_unslash($_GET['key']) : '';

        $formAction = esc_url(admin_url('admin.php'));
        echo '<form method="get" action="' . $formAction . '" class="gdash-filter">';
        echo '<input type="hidden" name="page" value="gcore-streams-keys" />';
        echo '<label for="gdash-pattern">' . esc_html__('Pattern', 'gcore') . '</label> ';
        echo '<input id="gdash-pattern" type="text" name="pattern" value="'
            . esc_attr($pattern) . '" size="40" /> ';
        echo '<button class="button" type="submit">' . esc_html__('Scan', 'gcore') . '</button>';
        echo '</form>';

        if ($keyRaw !== '') {
            $this->renderKeyDetail($keyRaw);
        } else {
            $this->renderKeyIndex($pattern);
        }
        echo '</div>';
    }

    private function renderKeyIndex(string $pattern): void
    {
        $client = $this->getGNodeClient();
        if (!$client) {
            return;
        }

        try {
            $keys = $client->keys($pattern, 1000);
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>'
                . esc_html(sprintf(__('keys() failed: %s', 'gcore'), $e->getMessage()))
                . '</p></div>';
            return;
        }
        if (!is_array($keys)) {
            $keys = [];
        }
        sort($keys);

        if (empty($keys)) {
            echo '<p>' . esc_html__('No keys matched.', 'gcore') . '</p>';
            return;
        }

        $byPrefix = [];
        foreach ($keys as $k) {
            $key = (string) $k;
            $prefix = $this->keyPrefix($key);
            $byPrefix[$prefix][] = $key;
        }
        ksort($byPrefix);

        echo '<p class="description">'
            . esc_html(sprintf(__('Total: %d keys (capped at 1000).', 'gcore'), count($keys)))
            . '</p>';

        foreach ($byPrefix as $prefix => $list) {
            echo '<h3>' . esc_html($prefix) . ' <span style="color:#888;font-weight:normal;">('
                . count($list) . ')</span></h3>';
            echo '<ul class="gdash-key-list">';
            foreach ($list as $k) {
                $url = esc_url(admin_url('admin.php?page=gcore-streams-keys&key=' . urlencode($k)));
                echo '<li><a href="' . $url . '"><code>' . esc_html($k) . '</code></a></li>';
            }
            echo '</ul>';
        }
    }

    private function renderKeyDetail(string $key): void
    {
        $client = $this->getGNodeClient();
        if (!$client) {
            return;
        }

        $back = esc_url(admin_url('admin.php?page=gcore-streams-keys'));
        echo '<p><a href="' . $back . '">&larr; ' . esc_html__('Back to index', 'gcore') . '</a></p>';
        echo '<h2><code>' . esc_html($key) . '</code></h2>';

        try {
            $type = $client->fcall('GNODE_KEY_TYPE', [], [$key]);
        } catch (Throwable $e) {
            $type = null;
        }
        if (!is_string($type) || $type === '' || $type === 'none') {
            // Fallback: try shape-by-shape probes via existing helpers.
            $type = $this->probeKeyShape($key);
        }

        echo '<p>' . esc_html__('Type:', 'gcore') . ' <code>' . esc_html((string) $type) . '</code></p>';

        switch ((string) $type) {
            case 'string':
                $this->renderKeyAsString($key);
                break;
            case 'hash':
                $this->renderKeyAsHash($key);
                break;
            case 'list':
                $this->renderKeyAsList($key);
                break;
            case 'set':
                $this->renderKeyAsSet($key);
                break;
            case 'zset':
                $this->renderKeyAsZSet($key);
                break;
            case 'stream':
                $this->renderKeyAsStream($key);
                break;
            default:
                echo '<p class="description">'
                    . esc_html__('Unknown shape — renderer not available.', 'gcore')
                    . '</p>';
        }
    }

    private function renderKeyAsString(string $key): void
    {
        $client = $this->getGNodeClient();
        try {
            $value = $client ? $client->luaGet($key) : null;
        } catch (Throwable $e) {
            $value = null;
        }
        echo '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:1em;border-radius:4px;">'
            . esc_html(is_scalar($value) ? (string) $value : (string) wp_json_encode($value))
            . '</pre>';
    }

    private function renderKeyAsHash(string $key): void
    {
        $client = $this->getGNodeClient();
        try {
            $hash = $client ? $client->luaHGetAll($key) : [];
        } catch (Throwable $e) {
            $hash = [];
        }
        if (!is_array($hash) || empty($hash)) {
            echo '<p>' . esc_html__('(empty hash)', 'gcore') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>'
            . esc_html__('Field', 'gcore') . '</th><th>' . esc_html__('Value', 'gcore')
            . '</th></tr></thead><tbody>';
        ksort($hash);
        foreach ($hash as $f => $v) {
            $vStr = is_scalar($v) ? (string) $v : (string) wp_json_encode($v);
            echo '<tr><td><code>' . esc_html((string) $f) . '</code></td>'
                . '<td><code>' . esc_html($vStr) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderKeyAsList(string $key): void
    {
        $client = $this->getGNodeClient();
        try {
            $items = $client ? $client->fcall('GNODE_LIST_RANGE', [], [$key, 0, 99]) : [];
        } catch (Throwable $e) {
            $items = [];
        }
        if (!is_array($items) || empty($items)) {
            echo '<p>' . esc_html__('(empty list)', 'gcore') . '</p>';
            return;
        }
        echo '<ol style="font-family:monospace;background:#f6f7f7;padding:1em 1em 1em 2em;border-radius:4px;">';
        foreach ($items as $item) {
            echo '<li>' . esc_html(is_scalar($item) ? (string) $item : (string) wp_json_encode($item)) . '</li>';
        }
        echo '</ol>';
    }

    private function renderKeyAsSet(string $key): void
    {
        $client = $this->getGNodeClient();
        try {
            $members = $client ? $client->fcall('GNODE_SET_MEMBERS', [], [$key]) : [];
        } catch (Throwable $e) {
            $members = [];
        }
        if (!is_array($members) || empty($members)) {
            echo '<p>' . esc_html__('(empty set)', 'gcore') . '</p>';
            return;
        }
        sort($members);
        echo '<ul style="font-family:monospace;background:#f6f7f7;padding:1em 1em 1em 2em;border-radius:4px;">';
        foreach ($members as $m) {
            echo '<li>' . esc_html(is_scalar($m) ? (string) $m : (string) wp_json_encode($m)) . '</li>';
        }
        echo '</ul>';
    }

    private function renderKeyAsZSet(string $key): void
    {
        $client = $this->getGNodeClient();
        try {
            $items = $client ? $client->fcall('GNODE_ZSET_RANGE', [], [$key, 0, 99]) : [];
        } catch (Throwable $e) {
            $items = [];
        }
        if (!is_array($items) || empty($items)) {
            echo '<p>' . esc_html__('(empty zset)', 'gcore') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>'
            . esc_html__('Score', 'gcore') . '</th><th>' . esc_html__('Member', 'gcore')
            . '</th></tr></thead><tbody>';
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $score = isset($row['score']) ? (string) $row['score'] : '—';
            $member = isset($row['member']) ? (string) $row['member'] : '—';
            echo '<tr><td>' . esc_html($score) . '</td>'
                . '<td><code>' . esc_html($member) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function renderKeyAsStream(string $key): void
    {
        $client = $this->getGNodeClient();
        try {
            $entries = $client ? $client->fcall('GNODE_STREAM_RANGE', [], [$key, '-', '+', 100]) : [];
        } catch (Throwable $e) {
            $entries = [];
        }
        if (!is_array($entries) || empty($entries)) {
            echo '<p>' . esc_html__('(empty stream)', 'gcore') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>'
            . esc_html__('Entry ID', 'gcore') . '</th><th>'
            . esc_html__('Fields', 'gcore') . '</th></tr></thead><tbody>';
        foreach ($entries as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id     = (string) ($row['id'] ?? '');
            $fields = $row['fields'] ?? $row;
            $payload = is_scalar($fields) ? (string) $fields : (string) wp_json_encode($fields);
            echo '<tr><td><code>' . esc_html($id) . '</code></td>'
                . '<td><code>' . esc_html($payload) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function probeKeyShape(string $key): string
    {
        // Best-effort fallback when GNODE_KEY_TYPE is not available.
        $client = $this->getGNodeClient();
        if (!$client) {
            return 'unknown';
        }
        try {
            if ($client->luaExists($key)) {
                $val = $client->luaGet($key);
                if ($val !== null && $val !== false) {
                    return 'string';
                }
            }
        } catch (Throwable $e) {
            // continue
        }
        return 'unknown';
    }

    // =========================================================================
    // PAGE RENDERS — ACL INSPECTOR
    // =========================================================================

    public function renderAclInspectorPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'gcore'));
        }

        echo '<div class="wrap gdash">';
        echo '<h1>' . esc_html__('Geodineum Dashboard · ACL Inspector', 'gcore') . '</h1>';
        echo '<p class="description">' . esc_html__(
            'Read-only view of ValKey ACL users. Write surface (add/modify/delete) is planned.',
            'gcore'
        ) . '</p>';

        $users = $this->fetchAclUsers();
        if ($users === null) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__(
                    'Could not read ACL list. The dashboard ACL user is read-only by design and lacks the ACL admin command. Configure a privileged read-back user (or run redis-cli ACL LIST as the operator) to enable this view.',
                    'gcore'
                )
                . '</p></div></div>';
            return;
        }
        if (empty($users)) {
            echo '<p>' . esc_html__('No ACL users.', 'gcore') . '</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>'
            . '<th>' . esc_html__('User', 'gcore') . '</th>'
            . '<th>' . esc_html__('Status', 'gcore') . '</th>'
            . '<th>' . esc_html__('Commands', 'gcore') . '</th>'
            . '<th>' . esc_html__('Keys', 'gcore') . '</th>'
            . '<th>' . esc_html__('Channels', 'gcore') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($users as $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html((string) ($row['user'] ?? '?')) . '</code></td>';
            $on = !empty($row['enabled']);
            $statusBadge = $on ? '<span class="gdash-badge gdash-badge-ok">on</span>'
                              : '<span class="gdash-badge gdash-badge-warn">off</span>';
            echo '<td>' . $statusBadge . '</td>';
            echo '<td><code>' . esc_html((string) ($row['commands'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($row['keys'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($row['channels'] ?? '')) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Best-effort fetch of ACL LIST output. Returns null when unauthorized.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchAclUsers(): ?array
    {
        try {
            $r = $this->openDirectConnection();
        } catch (Throwable $e) {
            return null;
        }
        if ($r === null) {
            return null;
        }

        try {
            $raw = $r->rawCommand('ACL', 'LIST');
        } catch (Throwable $e) {
            return null;
        } finally {
            try { $r->close(); } catch (Throwable $_) { /* noop */ }
        }

        if (!is_array($raw)) {
            return null;
        }

        $users = [];
        foreach ($raw as $line) {
            if (!is_string($line)) {
                continue;
            }
            $tokens = preg_split('/\s+/', trim($line)) ?: [];
            if (count($tokens) < 2) {
                continue;
            }

            // First token is "user", second is the username, then flags.
            $user = (string) $tokens[1];
            $rest = array_slice($tokens, 2);

            $enabled  = in_array('on', $rest, true);
            $commands = implode(' ', array_filter($rest, static function ($t) {
                return is_string($t) && strlen($t) > 0
                    && ($t[0] === '+' || $t[0] === '-' || strpos($t, '@') !== false);
            }));
            $keys = implode(' ', array_filter($rest, static function ($t) {
                return is_string($t) && (strpos($t, '~') === 0 || strpos($t, 'allkeys') !== false
                    || strpos($t, 'resetkeys') !== false);
            }));
            $channels = implode(' ', array_filter($rest, static function ($t) {
                return is_string($t) && (strpos($t, '&') === 0 || strpos($t, 'allchannels') !== false
                    || strpos($t, 'resetchannels') !== false);
            }));

            $users[] = [
                'user'     => $user,
                'enabled'  => $enabled,
                'commands' => $commands,
                'keys'     => $keys,
                'channels' => $channels,
            ];
        }

        return $users;
    }

    // =========================================================================
    // PAGE RENDERS — SERVICE LOGS
    // =========================================================================

    public function renderServiceLogsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'gcore'));
        }

        echo '<div class="wrap gdash">';
        echo '<h1>' . esc_html__('Geodineum Dashboard · Service Logs', 'gcore') . '</h1>';
        echo '<p class="description">' . esc_html__(
            'journalctl tail for allowlisted Geodineum systemd units. ANSI-stripped + XSS-safe rendering of arbitrary log content.',
            'gcore'
        ) . '</p>';

        $unitParam = isset($_GET['unit']) ? (string) wp_unslash($_GET['unit']) : self::JOURNAL_UNIT_ALLOWLIST[0];
        if (!in_array($unitParam, self::JOURNAL_UNIT_ALLOWLIST, true)) {
            $unitParam = self::JOURNAL_UNIT_ALLOWLIST[0];
        }

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="gdash-filter">';
        echo '<input type="hidden" name="page" value="gcore-service-logs" />';
        echo '<label for="gdash-unit">' . esc_html__('Unit', 'gcore') . '</label> ';
        echo '<select id="gdash-unit" name="unit">';
        foreach (self::JOURNAL_UNIT_ALLOWLIST as $allowed) {
            $sel = $allowed === $unitParam ? ' selected' : '';
            echo '<option value="' . esc_attr($allowed) . '"' . $sel . '>'
                . esc_html($allowed) . '</option>';
        }
        echo '</select> ';
        echo '<button class="button" type="submit">' . esc_html__('Tail', 'gcore') . '</button>';
        echo '</form>';

        $output = $this->fetchJournalTail($unitParam, 200);
        echo '<pre class="gdash-journal">' . esc_html($output) . '</pre>';
        echo '</div>';
    }

    private function fetchJournalTail(string $unit, int $lines): string
    {
        // Defence-in-depth: re-validate against the allowlist. The unit name
        // never reaches a shell because we use proc_open with an argv array.
        if (!in_array($unit, self::JOURNAL_UNIT_ALLOWLIST, true)) {
            return '(unit not allowlisted)';
        }
        $lines = max(1, min(2000, $lines));

        if (!function_exists('proc_open')) {
            return '(proc_open disabled — cannot exec journalctl)';
        }

        $cmd = ['/usr/bin/journalctl', '-u', $unit, '-n', (string) $lines, '--no-pager'];
        $descr = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descr, $pipes);
        if (!is_resource($proc)) {
            return '(failed to spawn journalctl)';
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0 && $stdout === '') {
            return sprintf("(journalctl exit=%d)\n%s", $exit, $stderr);
        }

        // Strip ANSI escape sequences. XSS is handled by esc_html at render time.
        return preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $stdout) ?? $stdout;
    }

    // =========================================================================
    // PAGE RENDERS — ACTIONS PLACEHOLDER
    // =========================================================================

    public function renderActionsPlaceholderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'gcore'));
        }
        echo '<div class="wrap gdash">';
        echo '<h1>' . esc_html__('Geodineum Dashboard · Actions', 'gcore') . '</h1>';
        echo '<div class="notice notice-info"><p>'
            . esc_html__(
                'Actions surface (write-side: every CLI subcommand mapped to an admin form) is planned post-launch.',
                'gcore'
            )
            . '</p></div>';
        echo '<p>' . esc_html__('Until then, use the Geodineum CLI directly:', 'gcore') . '</p>';
        echo '<pre style="background:#f6f7f7;padding:1em;border-radius:4px;">'
            . "$ geodineum --help\n"
            . "$ geodineum register\n"
            . "$ geodineum status\n"
            . '</pre>';
        echo '<p>' . esc_html__('See the project roadmap (§G.6) for the full CLI ↔ GUI mapping.', 'gcore') . '</p>';
        echo '</div>';
    }

    // =========================================================================
    // SHARED HELPERS
    // =========================================================================

    /** @return \gCore\gNode\gNodeClient|null */
    private function getGNodeClient()
    {
        if (function_exists('gcore_get_admin_gnode_client')) {
            return gcore_get_admin_gnode_client();
        }
        return null;
    }

    /**
     * Open a direct \Redis connection for ADMIN-class operations (ACL LIST,
     * etc.) that bypass the FCALL allowlist.  Mirrors the connect helper in
     * SchemasAdmin (decision F: file-path credentials only).
     */
    private function openDirectConnection(): ?\Redis
    {
        // Connect as THIS site's own ValKey identity (gnode_client_<site>) — the
        // same per-site cred the Status page uses — NOT a separate operator
        // token. Covers this site's keyspace + gnode:* (incl. gnode:site:*:meta)
        // + topology. Global/cross-site reads (ACL LIST, other sites) are out of
        // scope and degrade gracefully; that surface is the standalone gDash.
        if (function_exists('gcore_admin_site_redis')) {
            return gcore_admin_site_redis();
        }
        return null;
    }

    /**
     * Resolve the active site_id. Reuses the helper baked into wp-hooks.php.
     */
    private function resolveSiteId(): string
    {
        $client = $this->getGNodeClient();
        if ($client && method_exists($client, 'getSiteId')) {
            try {
                $sid = $client->getSiteId();
                if (is_string($sid) && $sid !== '') {
                    return $sid;
                }
            } catch (Throwable $e) {
                // fall through
            }
        }

        $domain = parse_url((string) get_site_url(), PHP_URL_HOST) ?: 'localhost';
        return str_replace(['.', '-'], '_', $domain);
    }

    /**
     * @return string[]
     */
    public function getSites(): array
    {
        $r = $this->openDirectConnection();
        if ($r === null) {
            return [];
        }
        try {
            $members = $r->sMembers('gnode:sites:registry');
        } catch (Throwable $e) {
            $members = [];
        } finally {
            try { $r->close(); } catch (Throwable $_) { /* noop */ }
        }
        if (!is_array($members)) {
            return [];
        }
        $sites = array_values(array_filter(array_map('strval', $members), 'strlen'));
        sort($sites);
        return $sites;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSiteMeta(string $siteId): array
    {
        $r = $this->openDirectConnection();
        if ($r === null) {
            return [];
        }
        try {
            $meta = $r->hGetAll('gnode:site:' . $siteId . ':meta');
        } catch (Throwable $e) {
            $meta = [];
        } finally {
            try { $r->close(); } catch (Throwable $_) { /* noop */ }
        }
        return is_array($meta) ? $meta : [];
    }

    public function countSites(): int
    {
        return count($this->getSites());
    }

    /**
     * @return array{rate: float, hits: int, misses: int, total: int, raw: array<string, mixed>}
     */
    public function cacheHitRate(string $siteId): array
    {
        $empty = ['rate' => 0.0, 'hits' => 0, 'misses' => 0, 'total' => 0, 'raw' => []];
        if ($siteId === '') {
            return $empty;
        }
        $r = $this->openDirectConnection();
        if ($r === null) {
            return $empty;
        }
        try {
            $raw = $r->hGetAll('{' . $siteId . '}:metrics:cache');
        } catch (Throwable $e) {
            $raw = [];
        } finally {
            try { $r->close(); } catch (Throwable $_) { /* noop */ }
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $hits   = isset($raw['hits'])   ? (int) $raw['hits']   : 0;
        $misses = isset($raw['misses']) ? (int) $raw['misses'] : 0;
        $total  = $hits + $misses;
        $rate   = $total > 0 ? $hits / $total : 0.0;
        return ['rate' => $rate, 'hits' => $hits, 'misses' => $misses, 'total' => $total, 'raw' => $raw];
    }

    public function totalRequests(string $siteId): int
    {
        if ($siteId === '') {
            return -1;
        }
        $r = $this->openDirectConnection();
        if ($r === null) {
            return -1;
        }
        try {
            $val = $r->get('{' . $siteId . '}:metrics:requests:total');
        } catch (Throwable $e) {
            $val = false;
        } finally {
            try { $r->close(); } catch (Throwable $_) { /* noop */ }
        }
        if ($val === false || $val === null || $val === '') {
            return -1;
        }
        return (int) $val;
    }

    /**
     * Return the top-N FCALL function invocation counts for this site.
     *
     * @return array<string, int>  function-name => count, sorted desc by count
     */
    public function topFcallFunctions(string $siteId, int $limit = 20): array
    {
        if ($siteId === '') {
            return [];
        }
        $r = $this->openDirectConnection();
        if ($r === null) {
            return [];
        }

        $prefix = '{' . $siteId . '}:metrics:requests:fcalls:';
        $cursor = null;
        $found  = [];
        try {
            // Use SCAN — never KEYS — per cluster-safety invariant.
            $iter = 0;
            do {
                $batch = $r->scan($cursor, $prefix . '*', 200);
                if ($batch === false) {
                    break;
                }
                foreach ($batch as $key) {
                    $val = $r->get($key);
                    if ($val !== false && $val !== null) {
                        $fn = substr((string) $key, strlen($prefix));
                        $found[$fn] = (int) $val;
                    }
                }
                $iter++;
            } while ($cursor > 0 && $iter < 50);
        } catch (Throwable $e) {
            // fall through with whatever we got
        } finally {
            try { $r->close(); } catch (Throwable $_) { /* noop */ }
        }

        arsort($found);
        if (count($found) > $limit) {
            $found = array_slice($found, 0, $limit, true);
        }
        return $found;
    }

    private function keyPrefix(string $key): string
    {
        // Drop the {site_id}: hash-tag (if present) before prefix grouping so
        // multi-site installs collapse into one row per logical namespace.
        $stripped = preg_replace('/^\{[^}]+\}:/', '', $key) ?? $key;
        $colon = strpos($stripped, ':');
        if ($colon === false) {
            return $stripped === $key ? $key : '{site}:' . $stripped;
        }
        $prefix = substr($stripped, 0, $colon);
        return $stripped === $key ? $prefix : '{site}:' . $prefix;
    }

    private function sanitizePattern(string $pattern): string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '*';
        }
        // SCAN MATCH glob characters are *, ?, [, ]. Reject anything else
        // that looks shell-injection-shaped just in case.
        $pattern = preg_replace('/[^A-Za-z0-9_:{}\-*?\[\]]/', '', $pattern) ?? '*';
        if ($pattern === '') {
            return '*';
        }
        return $pattern;
    }
}

// ----------------------------------------------------------------------------
// Short-form alias for the panel-registration contract: extension modules can
// write `gDash::register_panel([...])` instead of the full namespace path.
// Convention matches the gCore / gNode / gCube ecosystem prefixing.
// ----------------------------------------------------------------------------
if (!class_exists('gDash', false)) {
    class_alias(\gCore\Modules\Dashboard\Admin\DashboardAdmin::class, 'gDash');
}
