<?php
/**
 * NiertoCube Default Configuration
 * 
 * Default configuration settings for all NiertoCube modules.
 * 
 * @package     NiertoCube
 * @subpackage  Config
 * @version     2.0.0
 */

namespace NiertoCube\Config;

if (!defined('ABSPATH')) {
    exit;
}

return [
    /**
     * Core system configuration
     */
    'core' => [
        'debug' => WP_DEBUG,
        'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production',
        'version' => '2.0.0',
        'modules_path' => '/inc/modules',
        'automatic_initialization' => true,
    ],

    /**
     * Module configurations
     */
    'modules' => [
        'cache' => [
            'enabled' => true,
            'driver' => 'valkey',
            'prefix' => 'nierto_cube_',
            'ttl' => HOUR_IN_SECONDS,
            'groups' => [
                'core' => ['ttl' => HOUR_IN_SECONDS],
                'face' => ['ttl' => HOUR_IN_SECONDS * 2],
                'api' => ['ttl' => HOUR_IN_SECONDS / 2],
                'manifest' => ['ttl' => DAY_IN_SECONDS]
            ]
        ],

        'error' => [
            'enabled' => true,
            'log_path' => WP_CONTENT_DIR . '/logs/nierto-cube',
            'error_logging' => true,
            'display_errors' => WP_DEBUG,
            'admin_notification' => true
        ],

        'performance' => [
            'enabled' => true,
            'optimize_assets' => true,
            'defer_scripts' => true,
            'remove_query_strings' => true,
            'optimize_database' => true
        ],

        'metrics' => [
            'enabled' => true,
            'retention_days' => 30,
            'metrics_limit' => 1000,
            'alert_threshold' => 90
        ],

        'api' => [
            'enabled' => true,
            'rate_limit' => 60,
            'rate_period' => 60,
            'cache_enabled' => true
        ],

        'manifest' => [
            'enabled' => true,
            'cache_enabled' => true,
            'ttl' => DAY_IN_SECONDS
        ]
    ],

    /**
     * Feature flags
     */
    'features' => [
        'pwa' => false,
        'valkey' => false,
        'advanced_metrics' => false
    ],

    /**
     * Development settings
     */
    'development' => [
        'show_debug_info' => false,
        'log_level' => 'warning',
        'profile_queries' => false
    ]
];