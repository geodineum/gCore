<?php
/**
 * Uninstall gCore
 *
 * This file runs when the plugin is deleted from the WordPress admin.
 * It performs a complete cleanup of database tables, options, and files.
 *
 * @package gCore
 */

// If uninstall.php is not called by WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Set error log notice
error_log('gCore uninstall process started: ' . date('Y-m-d H:i:s'));

// Get important constants or set defaults
$gcore_version = get_option('gcore_version', '0.1.0');
$gcore_environment = get_option('gcore_environment', 'wordpress');

/**
 * 1. Get setting for retention (if exists)
 * Allow admins to preserve some data on uninstall if desired
 */
$preserve_data = get_option('gcore_preserve_data_on_uninstall', false);
$preserve_logs = get_option('gcore_preserve_logs_on_uninstall', false);

/**
 * 2. Remove database tables
 */
global $wpdb;

// Security events table
$table_security_events = $wpdb->prefix . 'gcore_security_events';
$wpdb->query("DROP TABLE IF EXISTS $table_security_events");

// Remove additional tables if they exist from user configurations
$custom_tables = [
    $wpdb->prefix . 'gcore_cache_metrics',
    $wpdb->prefix . 'gcore_service_registry',
    $wpdb->prefix . 'gcore_topology_nodes',
    $wpdb->prefix . 'gcore_api_keys'
];

foreach ($custom_tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS $table");
}

/**
 * 3. Remove all options
 */
// Core options
$core_options = [
    'gcore_version',
    'gcore_environment',
    'gcore_security_level',
    'gcore_notification_email',
    'gcore_cache_enabled',
    'gcore_preserve_data_on_uninstall',
    'gcore_preserve_logs_on_uninstall'
];

// Settings options
$settings_options = [
    'gcore_settings',
    'gcore_valkey_host',
    'gcore_valkey_port',
    'gcore_valkey_auth',
    'gcore_valkey_password',
    'gcore_valkey_tls',
    'gcore_advanced_features',
    'gcore_debug_mode',
    'gcore_topology_enabled',
    'gcore_metrics_enabled',
    'gcore_default_security_rules',
    'gcore_service_discovery_interval',
    'gcore_notifications_enabled',
    'gcore_multitenant_enabled',
    'gcore_notification_channels'
];

// Combined options
$all_options = array_merge($core_options, $settings_options);

// Delete all options
foreach ($all_options as $option) {
    delete_option($option);
}

// Delete options from multisite network if applicable
if (is_multisite()) {
    foreach ($all_options as $option) {
        delete_site_option($option);
    }
}

/**
 * 4. Remove cached transients
 */
$transients = [
    'gcore_service_registry',
    'gcore_status_cache',
    'gcore_topology_cache',
    'gcore_schema_validation',
    'gcore_capability_matrix'
];

foreach ($transients as $transient) {
    delete_transient($transient);
}

/**
 * 5. Remove directory content
 */
// No need to check if preserve logs is true
if (!$preserve_logs) {
    // Log directories
    $log_dirs = [
        WP_CONTENT_DIR . '/gcore-logs'
    ];

    foreach ($log_dirs as $dir) {
        gcore_recursive_remove_directory($dir);
    }
}

// Cache directories (always remove)
$cache_dirs = [
    WP_CONTENT_DIR . '/gcore-cache'
];

foreach ($cache_dirs as $dir) {
    gcore_recursive_remove_directory($dir);
}

/**
 * 6. Clean ValKey/Redis data
 */
// Attempt to connect to ValKey and clean data if available
$valkey_host = getenv('VALKEY_HOST') ?: get_option('gcore_valkey_host', null);
$valkey_port = getenv('VALKEY_PORT') ?: get_option('gcore_valkey_port', null);
$valkey_auth = get_option('gcore_valkey_auth', false);
$valkey_password = get_option('gcore_valkey_password', '');

if (!$valkey_host || !$valkey_port) {
    error_log('gCore uninstall: VALKEY_HOST/VALKEY_PORT not configured, skipping ValKey cleanup');
    $valkey_host = null; // Signal to skip ValKey cleanup below
}

if (class_exists('Redis') && $valkey_host && $valkey_port) {
    try {
        $redis = new Redis();
        $connected = $redis->connect($valkey_host, (int)$valkey_port, 2); // 2 second timeout
        
        if ($connected) {
            // Auth if needed
            if ($valkey_auth && !empty($valkey_password)) {
                $redis->auth($valkey_password);
            }

            // Cluster-safe per-site cleanup via SCAN cursor.
            // Pattern is hash-tagged at "{$site_id}:" so all matching keys
            // live on the same shard in cluster mode; SCAN iterates that
            // shard's slot space without blocking.
            //
            // Stale prefixes ("wp:", "topo:") removed: zero writers exist
            // in the current ecosystem (verified via cross-repo grep). Canonical write pattern is "{site_id}:*"
            // (per-site streams, hashes, sets owned by gNode + gCore).
            $site_id = get_current_blog_id();
            $pattern = "{{$site_id}}:*";

            // phpredis SCAN_RETRY: auto-retries on empty batches with
            // non-zero cursor so the loop terminates only on cursor==0.
            $redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);
            $iter = null;
            $deleted = 0;
            while ($batch = $redis->scan($iter, $pattern, 500)) {
                foreach ($batch as $key) {
                    if ($redis->del($key)) {
                        $deleted++;
                    }
                }
            }
            error_log("gCore uninstall: deleted {$deleted} ValKey keys for site {$site_id}");

            $redis->close();
        }
    } catch (Exception $e) {
        error_log('Failed to clean ValKey data during uninstall: ' . $e->getMessage());
    }
}

/**
 * 7. Execute any user-defined uninstall actions through the termination hook
 */
$termination_actions = get_option('gcore_termination_actions', []);
if (!empty($termination_actions) && is_array($termination_actions)) {
    foreach ($termination_actions as $action) {
        if (function_exists($action)) {
            try {
                call_user_func($action);
            } catch (Exception $e) {
                error_log('Failed to execute termination action ' . $action . ': ' . $e->getMessage());
            }
        }
    }
}

/**
 * 8. Remove cron tasks
 */
$cron_hooks = [
    'gcore_service_discovery_cron',
    'gcore_metrics_collection_cron',
    'gcore_security_audit_cron',
    'gcore_cache_cleanup_cron',
    'gcore_log_rotation_cron'
];

foreach ($cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}

// Set final uninstall log
error_log('gCore uninstall process completed: ' . date('Y-m-d H:i:s'));

/**
 * Helper function to recursively remove a directory
 * 
 * @param string $dir Directory path
 * @return bool Success/failure
 */
function gcore_recursive_remove_directory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        
        if (is_dir($path)) {
            gcore_recursive_remove_directory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}