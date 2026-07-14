<?php
/**
 * Plugin Name: gCore Framework Loader
 * Plugin URI: https://geodineum.com/gcore
 * Description: Loads gCore framework from configurable location for secure, centralized deployment
 * Version: 1.1.0
 * Author: Geodineum
 * Author URI: https://geodineum.com
 * License: Apache-2.0
 * Requires at least: 5.2
 * Requires PHP: 7.4
 *
 * Path-agnostic loader. Framework location resolved from (in order):
 * 1. GCORE_FRAMEWORK_PATH constant (e.g., defined in wp-config.php)
 * 2. GCORE_PATH environment variable
 * 3. Fallback: /opt/geodineum/gCore
 *
 * @package gCore
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Framework location - resolved from constant, env var, or default
// This makes gCore deployable to any location
// Priority: GCORE_BASE_PATH constant → GCORE_BASE_PATH env → GCORE_PATH env → default
if (!defined('GCORE_FRAMEWORK_PATH')) {
    $gcorePath = defined('GCORE_BASE_PATH') ? GCORE_BASE_PATH : null;
    if (!$gcorePath || !is_dir($gcorePath)) {
        $gcorePath = getenv('GCORE_BASE_PATH') ?: getenv('GCORE_PATH');
    }
    if ($gcorePath && is_dir($gcorePath)) {
        define('GCORE_FRAMEWORK_PATH', $gcorePath);
    } else {
        define('GCORE_FRAMEWORK_PATH', '/opt/geodineum/gCore');
    }
}

// MU-plugin location constants.
define('GCORE_MU_DIR', plugin_dir_path(__FILE__));
// plugin_dir_url(__FILE__) mis-resolves here: this loader is reached through a
// wp-content/mu-plugins/<name> symlink into /opt, so __FILE__ is the /opt
// realpath, which WordPress cannot map back under wp-content — it produced
// http://host/wp-content/plugins/opt/geodineum/.../ (wrong path AND scheme).
// Build the URL from the canonical mu-plugins base + this directory's name so
// the path and scheme both match WP core assets.
if (defined('WPMU_PLUGIN_URL')) {
    define('GCORE_MU_URL', trailingslashit(WPMU_PLUGIN_URL) . basename(rtrim(GCORE_MU_DIR, '/')) . '/');
} else {
    define('GCORE_MU_URL', plugin_dir_url(__FILE__));
}

/**
 * Verify framework exists and is readable
 */
function gcore_mu_verify_framework(): bool {
    $bootstrapFile = GCORE_FRAMEWORK_PATH . '/bootstrap.php';

    if (!file_exists($bootstrapFile)) {
        return false;
    }

    if (!is_readable($bootstrapFile)) {
        return false;
    }

    return true;
}

/**
 * Display admin error if framework not found
 */
function gcore_mu_framework_missing_notice(): void {
    $class = 'notice notice-error';
    $message = sprintf(
        __('gCore Framework Error: Framework not found at %s. Set GCORE_PATH env var or define GCORE_FRAMEWORK_PATH in wp-config.php.', 'gcore'),
        '<code>' . esc_html(GCORE_FRAMEWORK_PATH) . '</code>'
    );

    printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
}

/**
 * Display admin error if framework not readable
 */
function gcore_mu_framework_unreadable_notice(): void {
    $class = 'notice notice-error';
    $message = sprintf(
        __('gCore Framework Error: Framework at %s is not readable. Check file permissions (owner:www-data with 640/750).', 'gcore'),
        '<code>' . esc_html(GCORE_FRAMEWORK_PATH) . '</code>'
    );

    printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
}

// Verify framework before loading
if (!file_exists(GCORE_FRAMEWORK_PATH . '/bootstrap.php')) {
    add_action('admin_notices', 'gcore_mu_framework_missing_notice');
    error_log('[gCore MU] Framework not found at: ' . GCORE_FRAMEWORK_PATH);
    return;
}

if (!is_readable(GCORE_FRAMEWORK_PATH . '/bootstrap.php')) {
    add_action('admin_notices', 'gcore_mu_framework_unreadable_notice');
    error_log('[gCore MU] Framework not readable at: ' . GCORE_FRAMEWORK_PATH . ' - check permissions');
    return;
}

// Load the framework bootstrap
try {
    require_once GCORE_FRAMEWORK_PATH . '/bootstrap.php';

    // Log successful load
    if (function_exists('gcore_bootstrap_log')) {
        gcore_bootstrap_log('Framework loaded via MU-plugin from: ' . GCORE_FRAMEWORK_PATH);
    }
} catch (Throwable $e) {
    error_log('[gCore MU] Bootstrap error: ' . $e->getMessage());

    add_action('admin_notices', function() use ($e) {
        $class = 'notice notice-error';
        $message = sprintf(
            __('gCore Framework Error: %s', 'gcore'),
            esc_html($e->getMessage())
        );
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), $message);
    });

    return;
}

// Load WordPress-specific hooks and integrations
$wpHooksFile = GCORE_MU_DIR . 'wp-hooks.php';
if (file_exists($wpHooksFile)) {
    require_once $wpHooksFile;
}

// Make gCore globally available
global $gCore;
try {
    if (class_exists('\\gCore\\Modules\\Core\\gCore')) {
        $gCore = \gCore\Modules\Core\gCore::getInstance();
    }
} catch (Throwable $e) {
    error_log('[gCore MU] Failed to get gCore instance: ' . $e->getMessage());
}

// Early page cache - serve from ValKey before WordPress fully loads
// This saves ~80ms by bypassing WordPress query/template initialization
$earlyCacheFile = GCORE_MU_DIR . 'early-page-cache.php';
if ($gCore && file_exists($earlyCacheFile)) {
    require_once $earlyCacheFile;
}
