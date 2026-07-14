<?php
/**
 * Config Schema admin panel (Commit 0.5.f).
 *
 * Reads the ecosystem-wide config_schema surface published by every
 * component at startup:
 *   SMEMBERS geodineum:config_schema:_index
 *   HGETALL  geodineum:config_schema:<component>
 *   GET      geodineum:bootstrap:<KEY>          (current-value column)
 *
 * Renders one table per component, columns:
 *   key | type | default | current | mutable | description
 *
 * For mutable:true keys, an inline edit form POSTs to admin-ajax.php via
 * wp_ajax_geodineum_schema_set, which:
 *   - enforces check_admin_referer('geodineum_schema_set')
 *   - requires current_user_can('manage_options') (or per-key override)
 *   - validates the new value against the declared type / enum
 *   - SET geodineum:bootstrap:<KEY> <value>
 *
 * Pattern mirrors gCore/Modules/Comms/Admin/CommsAdmin.php (canonical —
 * no new CSRF / capability flow invented).
 *
 * @package gCore
 * @subpackage Modules\Schemas\Admin
 */

declare(strict_types=1);

namespace gCore\Modules\Schemas\Admin;

use RuntimeException;

class SchemasAdmin
{
    private static ?self $instance = null;

    /** Nonce action name */
    private const NONCE_ACTION = 'geodineum_schema_set';

    /** Nonce field name */
    private const NONCE_FIELD = '_geodineum_schema_nonce';

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('wp_ajax_geodineum_schema_set', [$this, 'ajaxSetKey']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'gcore-dashboard',
            __('Config Schemas', 'gcore'),
            __('Config Schemas', 'gcore'),
            'manage_options',
            'geodineum-schemas',
            [$this, 'renderPage']
        );
    }

    /**
     * Top-level render: walk the _index, HGETALL each component, render.
     */
    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'gcore'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Geodineum Config Schemas', 'gcore') . '</h1>';
        echo '<p class="description">'
            . esc_html__(
                'Self-describing config surface for the whole ecosystem. Every component and extension publishes its config_schema at startup. Keys marked mutable:true can be edited here; structural keys require a reinstall.',
                'gcore'
            )
            . '</p>';

        try {
            $r = $this->connect();
        } catch (RuntimeException $e) {
            echo '<div class="notice notice-error"><p>'
                . esc_html(sprintf(__('ValKey unreachable: %s', 'gcore'), $e->getMessage()))
                . '</p></div></div>';
            return;
        }

        try {
            $components = $r->sMembers('geodineum:config_schema:_index');
            if ($components === false) {
                // phpredis returns false (not throw) on NOPERM. This site's
                // per-site credential cannot read the ecosystem-wide
                // geodineum:config_schema:* surface — that is a cross-site
                // operator view, by least-privilege design. The surface IS
                // published (the gnode-daemon publishes it at startup); this is
                // an access boundary, not a missing schema, so do NOT advise
                // re-running phase_config.
                echo '<div class="notice notice-info"><p>'
                    . esc_html__('The ecosystem config-schema surface is a cross-site operator view; this site\'s read-only credential cannot read it. Browse/edit it from the Geodineum operator console (gDash) or the schema CLI on the host. (The schema IS published — this is an access boundary, not a missing schema.)', 'gcore')
                    . '</p></div>';
                echo '</div>';
                return;
            }
            if (!is_array($components)) {
                $components = [];
            }
            sort($components);

            if (empty($components)) {
                echo '<div class="notice notice-warning"><p>'
                    . esc_html__('No components have published a config_schema yet. Re-run the installer phase_config step (or restart gnode-daemon to re-publish gNode).', 'gcore')
                    . '</p></div>';
                echo '</div>';
                return;
            }

            foreach ($components as $component) {
                $this->renderComponent($r, (string) $component);
            }
        } catch (\Throwable $e) {
            // The per-site credential cannot read the ecosystem-wide config_schema
            // surface (NOPERM) — that is a cross-site operator view, not a per-site
            // one. Show an informational notice instead of a fatal/scary error.
            echo '<div class="notice notice-info"><p>'
                . esc_html__('The ecosystem config-schema surface is a cross-site operator view and is not available from this site\'s read-only credential. Browse/edit it from the Geodineum operator console (gDash), or run the schema CLI on the host.', 'gcore')
                . '</p></div>';
        } finally {
            try { $r->close(); } catch (\Throwable $_) { /* noop */ }
        }

        echo '</div>';
    }

    private function renderComponent(\Redis $r, string $component): void
    {
        $raw = $r->hGetAll('geodineum:config_schema:' . $component);
        if (!is_array($raw)) {
            $raw = [];
        }
        $entries = [];
        foreach ($raw as $k => $v) {
            $decoded = json_decode((string) $v, true);
            if (is_array($decoded)) {
                $entries[(string) $k] = $decoded;
            }
        }
        if (empty($entries)) {
            return;
        }
        // Sort by key for a stable display order.
        ksort($entries);

        $nonce = wp_create_nonce(self::NONCE_ACTION);

        echo '<h2>' . esc_html($component) . '</h2>';
        echo '<table class="widefat striped" style="margin-bottom: 2em;">';
        echo '<thead><tr>'
            . '<th style="width:18%">' . esc_html__('Key', 'gcore') . '</th>'
            . '<th style="width:8%">' . esc_html__('Type', 'gcore') . '</th>'
            . '<th style="width:12%">' . esc_html__('Default', 'gcore') . '</th>'
            . '<th style="width:20%">' . esc_html__('Current', 'gcore') . '</th>'
            . '<th style="width:8%">' . esc_html__('Mutable', 'gcore') . '</th>'
            . '<th>' . esc_html__('Description', 'gcore') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($entries as $key => $entry) {
            $type = isset($entry['type']) ? (string) $entry['type'] : 'string';
            $default = array_key_exists('default', $entry) ? $entry['default'] : '';
            $mutable = !empty($entry['mutable']);
            $desc = isset($entry['description']) ? (string) $entry['description'] : '';
            $values = isset($entry['values']) && is_array($entry['values'])
                ? array_map('strval', $entry['values'])
                : null;

            $currentRaw = $r->get('geodineum:bootstrap:' . $key);
            $currentStr = ($currentRaw === false || $currentRaw === null)
                ? ''
                : (string) $currentRaw;

            echo '<tr>';
            echo '<td><code>' . esc_html($key) . '</code></td>';
            echo '<td>' . esc_html($type) . '</td>';
            echo '<td><code>' . esc_html(is_scalar($default) ? (string) $default : wp_json_encode($default)) . '</code></td>';

            if ($mutable) {
                echo '<td>';
                $formId = 'geodineum-schema-' . md5($key);
                echo '<form method="post" class="geodineum-schema-set" data-key="' . esc_attr($key) . '" id="' . esc_attr($formId) . '">';
                echo '<input type="hidden" name="action" value="geodineum_schema_set" />';
                echo '<input type="hidden" name="key" value="' . esc_attr($key) . '" />';
                echo '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr($nonce) . '" />';
                if ($type === 'enum' && $values !== null) {
                    echo '<select name="value">';
                    foreach ($values as $opt) {
                        $sel = $opt === $currentStr ? ' selected' : '';
                        echo '<option value="' . esc_attr($opt) . '"' . $sel . '>' . esc_html($opt) . '</option>';
                    }
                    echo '</select>';
                } elseif ($type === 'bool' || $type === 'boolean') {
                    foreach (['true', 'false'] as $opt) {
                        $sel = $opt === $currentStr ? ' selected' : '';
                        $options[] = '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
                    }
                    echo '<select name="value">' . implode('', $options ?? []) . '</select>';
                    $options = null;
                } else {
                    echo '<input type="text" name="value" value="' . esc_attr($currentStr) . '" size="18" />';
                }
                echo ' <button type="submit" class="button button-small">' . esc_html__('Save', 'gcore') . '</button>';
                echo '</form>';
                echo '</td>';
            } else {
                echo '<td><code>' . esc_html($currentStr) . '</code></td>';
            }

            echo '<td>' . ($mutable ? '<span class="dashicons dashicons-edit"></span>' : '') . '</td>';
            echo '<td>' . esc_html($desc) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Minimal JS — vanilla fetch() POST on form submit.
        echo '<script>document.querySelectorAll("form.geodineum-schema-set").forEach(function(f){'
            . 'f.addEventListener("submit",function(e){e.preventDefault();'
            . 'var fd=new FormData(f);'
            . 'fetch(ajaxurl,{method:"POST",body:fd,credentials:"same-origin"})'
            . '.then(function(r){return r.json();})'
            . '.then(function(j){if(j.success){alert("Saved: "+(j.data&&j.data.key||""));location.reload();}'
            . 'else{alert("Error: "+(j.data&&j.data.message||"unknown"));}});'
            . '});});</script>';
    }

    /**
     * AJAX write-back. Nonce + capability + schema-driven validate + SET.
     */
    public function ajaxSetKey(): void
    {
        // Nonce (canonical pattern: check_admin_referer with action + field).
        if (!check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD)) {
            wp_send_json_error(['message' => 'nonce failed'], 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'insufficient capability'], 403);
        }

        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash((string) $_POST['key'])) : '';
        $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash((string) $_POST['value'])) : '';

        if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            wp_send_json_error(['message' => 'invalid key name'], 400);
        }

        try {
            $r = $this->connect();
        } catch (RuntimeException $e) {
            wp_send_json_error(['message' => 'valkey unreachable: ' . $e->getMessage()], 500);
        }

        try {
            // Find the entry: scan the _index, look for the first component
            // that owns this key. O(components × keys) — small.
            $components = $r->sMembers('geodineum:config_schema:_index');
            if (!is_array($components)) {
                $components = [];
            }
            $entry = null;
            $ownerComponent = null;
            foreach ($components as $c) {
                $v = $r->hGet('geodineum:config_schema:' . $c, $key);
                if ($v !== false && $v !== null) {
                    $decoded = json_decode((string) $v, true);
                    if (is_array($decoded)) {
                        $entry = $decoded;
                        $ownerComponent = (string) $c;
                        break;
                    }
                }
            }

            if ($entry === null) {
                wp_send_json_error(['message' => "key '{$key}' not found in any published config_schema"], 404);
            }
            if (empty($entry['mutable'])) {
                wp_send_json_error(['message' => "key '{$key}' declared mutable:false"], 400);
            }

            // Optional per-key capability override.
            if (!empty($entry['capability']) && is_string($entry['capability'])
                && !current_user_can((string) $entry['capability'])) {
                wp_send_json_error(['message' => "missing capability: " . $entry['capability']], 403);
            }

            $type = isset($entry['type']) ? (string) $entry['type'] : 'string';
            $values = isset($entry['values']) && is_array($entry['values'])
                ? array_map('strval', $entry['values'])
                : null;
            $reason = $this->validateScalarAgainstType($value, $type, $values);
            if ($reason !== null) {
                wp_send_json_error([
                    'message' => "validate failed: {$reason}",
                    'key' => $key,
                    'component' => $ownerComponent,
                ], 400);
            }

            // Finally: write. Bootstrap tier is ecosystem-wide, no site prefix.
            $ok = $r->set('geodineum:bootstrap:' . $key, $value);
            // Also keep the index entry current (idempotent SADD).
            $r->sAdd('geodineum:bootstrap:_index', $key);
            if (!$ok) {
                wp_send_json_error(['message' => 'SET failed'], 500);
            }

            wp_send_json_success([
                'key' => $key,
                'component' => $ownerComponent,
                'value' => $value,
            ]);
        } finally {
            try { $r->close(); } catch (\Throwable $_) { /* noop */ }
        }
    }

    /**
     * Mirrors the Rust crate's validate_value_against_schema (and the PHP
     * gNodeClient mirror in 0.5.e). Returns null on ok, else a reason.
     *
     * @param mixed $values
     */
    private function validateScalarAgainstType(string $value, string $type, $values = null): ?string
    {
        switch ($type) {
            case 'int':
            case 'integer':
                if (!is_numeric($value) || (string) (int) $value !== $value) {
                    return "expected int, got: {$value}";
                }
                return null;
            case 'bool':
            case 'boolean':
                if (!in_array($value, ['true', 'false', '0', '1'], true)) {
                    return "expected bool, got: {$value}";
                }
                return null;
            case 'enum':
                if (!is_array($values) || empty($values)) {
                    return 'enum type declared without values list';
                }
                if (!in_array($value, $values, true)) {
                    return "value '{$value}' not in allowed set: " . implode('|', $values);
                }
                return null;
            case 'path':
                if ($value === '') {
                    return 'path type must not be empty';
                }
                return null;
            default:
                return null;
        }
    }

    /**
     * Connect to ValKey using the same disk/creds pattern as
     * gCore\Bootstrap\EcosystemConfigLoader. Kept local rather than
     * factored out so the admin panel does not depend on internals of
     * the loader — it only depends on the env that the loader populates.
     */
    private function connect(): \Redis
    {
        $host = getenv('VALKEY_HOST');
        $port = (int) (getenv('VALKEY_PORT') ?: 0);
        $creds = getenv('VALKEY_CREDS_PATH');
        if ($host === false || $port === 0 || $creds === false) {
            throw new RuntimeException('bootstrap env missing (VALKEY_HOST/PORT/CREDS_PATH)');
        }
        if (!class_exists('Redis', false)) {
            throw new RuntimeException('ext-redis not loaded');
        }

        // Connect as THIS site's own ValKey identity (gnode_client_<site>) — the
        // same per-site cred the Status page uses — NOT a separate operator token.
        // The ecosystem config-schema surface (geodineum:config_schema:*,
        // geodineum:bootstrap:*) is CROSS-CUTTING and out of the per-site ACL
        // scope, so those reads NOPERM and renderPage() shows an operator-console
        // notice. Full schema browse/edit belongs to the standalone gDash console
        // (its own broad-read user). Writes (ajaxSetKey) NOPERM gracefully.
        if (function_exists('gcore_admin_site_redis')) {
            $r = gcore_admin_site_redis();
            if ($r instanceof \Redis) {
                return $r;
            }
        }
        throw new RuntimeException('per-site ValKey connection unavailable');
    }
}

// Auto-initialize in wp-admin, matching the CommsAdmin / TemplateAdmin pattern.
if (is_admin() && defined('ABSPATH')) {
    add_action('plugins_loaded', function () {
        SchemasAdmin::getInstance();
    }, 20);
}
