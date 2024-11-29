<?php
/**
 * Optimization Management System for NiertoCube Theme
 * 
 * Handles performance optimization, resource management, and asset optimization
 * for the NiertoCube theme. This module focuses purely on optimization strategies
 * with metrics collection handled separately by MetricsManager.
 * 
 * @package     NiertoCube
 * @subpackage  Modules
 * @version     2.0.0
 * @since       2.0.0
 * 
 * Integration Points:
 * - WordPress Hooks (init, wp_enqueue_scripts, wp_head, shutdown)
 * - Resource Management (scripts, styles, headers)
 * - Asset Optimization (defer, async, media loading)
 * - Database Query Optimization
 * 
 * Key Features:
 * 1. Resource Management
 *    - Asset loading optimization
 *    - Script/style optimization
 *    - Resource hint management
 *    - Header optimization
 * 
 * 2. Optimization Phases
 *    - Early: Pre-init optimizations
 *    - Standard: WordPress init phase
 *    - Late: Post-init cleanup
 * 
 * @author    Niels Erik Toren
 * @copyright 2024 NiertoCube
 */

namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Core\NiertoCore;

if (!defined('ABSPATH')) {
    exit;
}

class OptimizationManager implements ModuleInterface {
    use AdvancedOptimizations;
    /** @var OptimizationManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration settings */
    private $config = [];
    
    /** @var \NiertoCube\Modules\MetricsManager|null */
    private $metrics = null;

    /** @var array Default configuration values */
    private const DEFAULT_CONFIG = [
        'enabled' => true,
        'debug' => WP_DEBUG,
        'static_extensions' => ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'ico', 'svg', 'woff', 'woff2'],
        'cache_ttl' => HOUR_IN_SECONDS,
        'resource_version' => '1.0',
        'defer_scripts' => true,
        'optimize_styles' => true,
        'remove_query_strings' => true,
        'optimize_database' => true,
        'preload_resources' => [
            'css/critical.css' => 'style',
            'js/essential.js' => 'script'
        ]
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->initializeOptimizer();
        $this->initializeAdvancedOptimizations();
    }

    /**
     * Initialize optimization system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge(self::DEFAULT_CONFIG, $config);

            // Get metrics manager if available
            try {
                $this->metrics = NiertoCore::getInstance()->getModule('Metrics');
            } catch (\Exception $e) {
                // Metrics unavailable - continue without metrics
            }

            // Register optimization hooks
            $this->registerOptimizationHooks();

            $this->initialized = true;

            // Log successful initialization
            if ($this->config['debug']) {
                $this->recordMetric('optimization_init', true, [
                    'timestamp' => microtime(true),
                    'config' => $this->config
                ]);
            }

        } catch (\Exception $e) {
            error_log('OptimizationManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register optimization hooks
     */
    private function registerOptimizationHooks(): void {
        // Early optimizations (priority 1)
        add_action('init', [$this, 'earlyOptimizations'], 1);
        
        // Standard optimizations (priority 10)
        add_action('init', [$this, 'standardOptimizations'], 10);
        
        // Late optimizations (priority 999)
        add_action('init', [$this, 'lateOptimizations'], 999);
        
        // Asset optimization
        add_action('wp_enqueue_scripts', [$this, 'optimizeAssets'], 999);
        
        // Header optimization
        add_filter('wp_headers', [$this, 'optimizeHeaders'], 10, 2);
        
        // Resource hints
        add_action('wp_head', [$this, 'addResourceHints'], 2);
    }

    /**
     * Early optimization phase
     */
    public function earlyOptimizations(): void {
        if (!$this->config['enabled']) {
            return;
        }

        // Disable emoji support
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        
        // Remove query strings from static resources
        if ($this->config['remove_query_strings']) {
            add_filter('script_loader_src', [$this, 'removeQueryStrings'], 15);
            add_filter('style_loader_src', [$this, 'removeQueryStrings'], 15);
        }
        
        // Disable XML-RPC
        add_filter('xmlrpc_enabled', '__return_false');
        
        // Remove unnecessary links
        remove_action('wp_head', 'wp_shortlink_wp_head');
        remove_action('wp_head', 'rest_output_link_wp_head');
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('template_redirect', 'rest_output_link_header', 11);
    }

    /**
     * Standard optimization phase
     */
    public function standardOptimizations(): void {
        if (!$this->config['enabled']) {
            return;
        }

        // Clean up headers
        header_remove('X-Powered-By');
        header_remove('X-Pingback');
        header_remove('Server');
        
        // Add security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        
        // Remove WordPress version info
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'rsd_link');
    }

    /**
     * Late optimization phase
     */
    public function lateOptimizations(): void {
        if (!$this->config['enabled']) {
            return;
        }

        if ($this->config['optimize_database']) {
            add_filter('posts_where', [$this, 'optimizeQueries'], 10, 2);
        }
    }

    /**
     * Optimize assets loading
     */
    public function optimizeAssets(): void {
        if (!$this->config['enabled']) {
            return;
        }

        global $wp_scripts, $wp_styles;
        
        $startTime = microtime(true);

        // Optimize script loading
        if ($this->config['defer_scripts'] && $wp_scripts instanceof \WP_Scripts) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if (!in_array($handle, ['jquery'])) {
                    $wp_scripts->registered[$handle]->extra['defer'] = true;
                }
            }
        }
        
        // Optimize style loading
        if ($this->config['optimize_styles'] && $wp_styles instanceof \WP_Styles) {
            foreach ($wp_styles->registered as $handle => $style) {
                if (!is_admin() && !in_array($handle, ['nierto-cube-critical'])) {
                    $wp_styles->registered[$handle]->extra['media'] = 'print';
                    $wp_styles->registered[$handle]->extra['onload'] = "this.media='all'";
                }
            }
        }

        // Record optimization metrics
        $this->recordMetric('asset_optimization', [
            'duration' => microtime(true) - $startTime,
            'scripts_count' => count($wp_scripts->queue ?? []),
            'styles_count' => count($wp_styles->queue ?? [])
        ]);
    }

    /**
     * Optimize HTTP headers
     */
    public function optimizeHeaders($headers, $wp): array {
        if (!$this->config['enabled'] || is_admin()) {
            return $headers;
        }

        $uri = $_SERVER['REQUEST_URI'];
        $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

        if (in_array($extension, $this->config['static_extensions'])) {
            $headers['Cache-Control'] = 'public, max-age=31536000';
            $headers['Pragma'] = 'public';
        }

        return $headers;
    }

    /**
     * Add resource hints
     */
    public function addResourceHints(): void {
        if (!$this->config['enabled']) {
            return;
        }

        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        
        foreach ($this->config['preload_resources'] as $resource => $type) {
            printf(
                '<link rel="preload" href="%s" as="%s">',
                esc_url(get_template_directory_uri() . '/' . $resource),
                esc_attr($type)
            );
        }
    }

    /**
     * Remove query strings from static resources
     */
    public function removeQueryStrings($src): string {
        if (strpos($src, '?ver=')) {
            return remove_query_arg('ver', $src);
        }
        return $src;
    }

    /**
     * Optimize database queries
     */
    public function optimizeQueries($where, $query): string {
        if (!is_admin() && $query->is_main_query()) {
            $where = str_replace("post_type = 'post'", "post_type IN ('post', 'cube_face')", $where);
        }
        return $where;
    }

    /**
     * Record performance metric
     */
    private function recordMetric(string $type, $value, array $context = []): void {
        if ($this->metrics) {
            $this->metrics->recordMetric($type, $value, $context);
        }
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if module is initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get module status
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'defer_scripts' => $this->config['defer_scripts'],
            'optimize_styles' => $this->config['optimize_styles'],
            'remove_query_strings' => $this->config['remove_query_strings'],
            'optimize_database' => $this->config['optimize_database'],
            'resource_version' => $this->config['resource_version'],
            'metrics_available' => $this->metrics !== null
        ];
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialize of singleton
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}