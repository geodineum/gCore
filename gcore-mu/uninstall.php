<?php
/**
 * gCore MU-Plugin Uninstall
 *
 * Handles cleanup when the MU-plugin is removed from WordPress.
 *
 * IMPORTANT: This only cleans WordPress-specific data (options, transients).
 * The framework at /opt/geodineum/gCore/ is NOT touched.
 * This is intentional - the framework serves all sites and should be
 * managed separately by system administrators.
 *
 * @package gCore
 */

// Security check - only run when called by WordPress uninstall
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up WordPress options for this site
 */
function gcore_uninstall_cleanup_options(): void {
    $options_to_delete = [
        'gcore_site_id',
        'gcore_node_id',
        'gcore_initialized',
        'gcore_version',
        'gcore_last_health_check',
        'gcore_service_status',
        'gcore_valkey_status',
    ];

    foreach ($options_to_delete as $option) {
        delete_option($option);
    }

    // Log cleanup
    error_log('[gCore Uninstall] Cleaned up ' . count($options_to_delete) . ' options');
}

/**
 * Clean up WordPress transients
 */
function gcore_uninstall_cleanup_transients(): void {
    $transients_to_delete = [
        'gcore_status_cache',
        'gcore_services_cache',
        'gcore_health_cache',
        'gcore_topology_cache',
    ];

    foreach ($transients_to_delete as $transient) {
        delete_transient($transient);
    }

    // Also clean any site transients (for multisite)
    foreach ($transients_to_delete as $transient) {
        delete_site_transient($transient);
    }

    error_log('[gCore Uninstall] Cleaned up transients');
}

/**
 * Clean up scheduled events
 */
function gcore_uninstall_cleanup_cron(): void {
    $hooks_to_clear = [
        'gcore_health_check',
        'gcore_cache_cleanup',
        'gcore_log_rotation',
    ];

    foreach ($hooks_to_clear as $hook) {
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
        // Clear all instances
        wp_clear_scheduled_hook($hook);
    }

    error_log('[gCore Uninstall] Cleared scheduled hooks');
}

/**
 * Handle multisite cleanup if applicable
 */
function gcore_uninstall_multisite_cleanup(): void {
    if (!is_multisite()) {
        return;
    }

    // Get all sites
    $sites = get_sites(['fields' => 'ids']);

    foreach ($sites as $site_id) {
        switch_to_blog($site_id);

        // Clean options for this site
        gcore_uninstall_cleanup_options();
        gcore_uninstall_cleanup_transients();
        gcore_uninstall_cleanup_cron();

        restore_current_blog();
    }

    // Clean network-wide options
    $network_options = [
        'gcore_network_initialized',
        'gcore_network_version',
    ];

    foreach ($network_options as $option) {
        delete_site_option($option);
    }

    error_log('[gCore Uninstall] Multisite cleanup complete for ' . count($sites) . ' sites');
}

// Run cleanup
if (is_multisite()) {
    gcore_uninstall_multisite_cleanup();
} else {
    gcore_uninstall_cleanup_options();
    gcore_uninstall_cleanup_transients();
    gcore_uninstall_cleanup_cron();
}

// Flush rewrite rules
flush_rewrite_rules();

// Final log message
error_log('[gCore Uninstall] WordPress cleanup complete. Framework at /opt/geodineum/gCore/ was NOT removed (intentional).');

/*
 * NOTE TO ADMINISTRATORS:
 *
 * The gCore framework at /opt/geodineum/gCore/ has NOT been removed.
 * This is intentional - the framework is shared across all WordPress sites.
 *
 * To fully remove gCore from the system:
 *   1. Remove the mu-plugin: rm -rf wp-content/mu-plugins/gcore-mu/
 *   2. Remove the framework: sudo rm -rf /opt/geodineum/gCore/
 *   3. Remove logs: sudo rm -rf /var/log/gcore/
 *   4. Remove cache: sudo rm -rf /var/cache/gcore/
 *
 * Only do this if no other sites are using gCore!
 */
