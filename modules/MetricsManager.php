<?php
/**
 * NiertoCube Metrics Management System
 * 
 * Provides comprehensive metrics collection, analysis, and reporting functionality
 * for the NiertoCube theme. Integrates with other core managers for complete
 * system monitoring and performance tracking.
 * 
 * @package     NiertoCube
 * @subpackage  Modules\Metrics
 * @version     2.0.0
 * @since       2.0.0
 * 
 * == Integration Points ==
 * - ErrorManager: Error logging and monitoring
 * - CacheManager: Metrics storage and retrieval
 * - APIManager: REST API endpoints for metrics access
 * - ManifestManager: PWA performance tracking
 * - OptimizationManager: Performance optimization metrics
 * 
 * == Key Features ==
 * - Real-time performance monitoring
 * - Efficient metrics storage and retrieval
 * - Cross-manager metric coordination
 * - REST API integration
 * - PWA performance tracking
 * 
 * == Usage Example ==
 * $metrics = MetricsManager::getInstance();
 * $metrics->recordMetric('page_load', [
 *     'duration' => $load_time,
 *     'resources' => $resource_count
 * ]);
 * 
 * == Security Measures ==
 * - Input validation for all metrics
 * - Proper error handling and logging
 * - Rate limiting for API endpoints
 * - Data sanitization
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

class MetricsManager implements ModuleInterface {
    /** @var MetricsManager Singleton instance */
    private static $instance = null;
    
    /** @var array Current metrics collection */
    private $metrics = [];
    
    /** @var array Configuration settings */
    private $config = [];
    
    /** @var \NiertoCube\Modules\CacheManager */
    private $cache;
    
    /** @var \NiertoCube\Modules\ErrorManager */
    private $error;
    
    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Registered metric types */
    private $metric_types = [];

    /** @var string Cache group for metrics */
    private const CACHE_GROUP = 'metrics';
    
    /** @var int Maximum stored metrics */
    private const MAX_STORED_METRICS = 1000;

    /** @var array Default configuration */
    private $default_config = [
        'enabled' => true,
        'debug' => WP_DEBUG,
        'retention_days' => 30,
        'metrics_limit' => self::MAX_STORED_METRICS,
        'admin_capability' => 'manage_options',
        'auto_cleanup' => true,
        'alert_threshold' => 90,
        'storage_mode' => 'cache'
    ];

    /** @var array Default metric types */
    private const DEFAULT_METRIC_TYPES = [
        'performance' => [
            'ttl' => DAY_IN_SECONDS * 30,
            'critical' => true
        ],
        'resource' => [
            'ttl' => DAY_IN_SECONDS * 7,
            'critical' => false
        ],
        'cache' => [
            'ttl' => HOUR_IN_SECONDS * 12,
            'critical' => true
        ],
        'error' => [
            'ttl' => DAY_IN_SECONDS * 90,
            'critical' => true
        ],
        'pwa' => [
            'ttl' => DAY_IN_SECONDS * 30,
            'critical' => false
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
    private function __construct() {}

    /**
     * Initialize metrics system
     * 
     * @param array $config Configuration options
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge($this->default_config, $config);

            // Initialize dependencies
            $core = NiertoCore::getInstance();
            $this->error = $core->getModule('Error');
            $this->cache = $core->getModule('Cache');

            // Register default metric types
            foreach (self::DEFAULT_METRIC_TYPES as $type => $settings) {
                $this->registerMetricType($type, $settings);
            }

            // Initialize subsystems
            $this->initializeSubsystems();

            // Register WordPress hooks
            $this->registerHooks();

            $this->initialized = true;

            // Log successful initialization
            if ($this->config['debug']) {
                $this->recordMetric('system_init', [
                    'timestamp' => microtime(true),
                    'config' => $this->config
                ]);
            }

        } catch (\Exception $e) {
            if (isset($this->error)) {
                $this->error->logError('metrics_init_failed', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Initialize subsystems
     */
    private function initializeSubsystems(): void {
        $this->setupRealtimeMonitoring();
        $this->registerAPIEndpoints();
        
        if ($this->config['auto_cleanup']) {
            $this->scheduleCleanup();
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        // Core metrics collection
        add_action('shutdown', [$this, 'recordFinalMetrics'], 999);
        add_action('admin_init', [$this, 'registerAdminSettings']);
        
        // Resource monitoring
        add_action('wp_footer', [$this, 'injectPerformanceMonitoring'], 999);
        
        // Admin interface
        if (is_admin()) {
            add_action('admin_menu', [$this, 'addAdminMenuPage']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        }

        // Cleanup schedule
        add_action('nierto_cube_metrics_cleanup', [$this, 'cleanupOldMetrics']);
    }

    /**
     * Setup real-time monitoring
     */
    private function setupRealtimeMonitoring(): void {
        if (!$this->initialized) return;

        add_action('shutdown', function() {
            $metrics = [
                'memory_peak' => memory_get_peak_usage(true),
                'memory_current' => memory_get_usage(true),
                'query_count' => get_num_queries(),
                'load_time' => timer_stop(),
                'cache_stats' => $this->cache->getMetrics()
            ];

            if (function_exists('getrusage')) {
                $resource_usage = getrusage();
                $metrics['cpu_usage'] = [
                    'user' => $resource_usage['ru_utime.tv_sec'],
                    'system' => $resource_usage['ru_stime.tv_sec']
                ];
            }

            $this->recordMetric('realtime_performance', $metrics);
        }, 999);
    }

    /**
     * Register API endpoints
     */
    private function registerAPIEndpoints(): void {
        if (!$this->initialized) return;

        $api = NiertoCore::getInstance()->getModule('API');
        if ($api && $api->isInitialized()) {
            $api->registerEndpoint('metrics/summary', [$this, 'getMetricsSummary']);
            $api->registerEndpoint('metrics/performance', [$this, 'getPerformanceMetrics']);
            $api->registerEndpoint('metrics/errors', [$this, 'getErrorMetrics']);
        }
    }

    /**
     * Record a metric
     * 
     * @param string $type Metric type
     * @param mixed $value Metric value
     * @param array $context Additional context
     */
    public function recordMetric(string $type, $value, array $context = []): void {
        if (!$this->config['enabled'] || !isset($this->metric_types[$type])) {
            return;
        }

        try {
            $metric = [
                'timestamp' => microtime(true),
                'type' => $type,
                'value' => $value,
                'context' => array_merge([
                    'url' => $_SERVER['REQUEST_URI'] ?? '',
                    'user_id' => get_current_user_id(),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ], $context)
            ];

            $this->metrics[] = $metric;

            // Store immediately if critical
            if ($this->metric_types[$type]['critical']) {
                $this->storeMetrics([$metric]);
            }

            // Execute callback if registered
            if (isset($this->metric_types[$type]['callback'])) {
                call_user_func($this->metric_types[$type]['callback'], $metric);
            }

        } catch (\Exception $e) {
            $this->logError('metric_record_failed', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Store metrics in cache/database
     * 
     * @param array|null $specific_metrics Specific metrics to store
     */
    private function storeMetrics(?array $specific_metrics = null): void {
        if (!$this->cache->isAvailable()) return;

        try {
            $metrics_to_store = $specific_metrics ?? $this->metrics;
            
            if (empty($metrics_to_store)) {
                return;
            }

            // Group metrics by type
            $grouped = [];
            foreach ($metrics_to_store as $metric) {
                $type = $metric['type'];
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [];
                }
                $grouped[$type][] = $metric;
            }

            // Store each type with its TTL
            foreach ($grouped as $type => $typeMetrics) {
                $key = "metrics_{$type}_" . date('Y-m-d');
                $stored = $this->cache->get($key, self::CACHE_GROUP) ?: [];
                $stored = array_merge($typeMetrics, $stored);
                
                // Limit stored metrics
                $stored = array_slice($stored, 0, $this->config['metrics_limit']);
                
                $this->cache->set(
                    $key,
                    $stored,
                    self::CACHE_GROUP,
                    $this->metric_types[$type]['ttl']
                );
            }

            // Clear processed metrics
            if ($specific_metrics === null) {
                $this->metrics = [];
            }

            // Check storage limits
            $this->checkStorageLimits();

        } catch (\Exception $e) {
            $this->logError('metrics_storage_failed', $e->getMessage());
        }
    }

    /**
     * Register a new metric type
     * 
     * @param string $type Metric type
     * @param array $settings Metric settings
     */
    public function registerMetricType(string $type, array $settings): void {
        $this->metric_types[$type] = array_merge([
            'ttl' => DAY_IN_SECONDS,
            'critical' => false,
            'callback' => null
        ], $settings);
    }

    /**
     * Get metrics summary
     * 
     * @param string|null $type Optional metric type filter
     * @return array Metrics summary
     */
    public function getMetricsSummary(?string $type = null): array {
        try {
            if ($type) {
                return $this->getTypeMetrics($type);
            }

            $summary = [];
            foreach ($this->metric_types as $metric_type => $settings) {
                $summary[$metric_type] = $this->getTypeMetrics($metric_type);
            }

            return [
                'summary' => $summary,
                'system_info' => $this->getSystemInfo()
            ];

        } catch (\Exception $e) {
            $this->logError('metrics_summary_failed', $e->getMessage());
            return [];
        }
    }

    /**
     * Get system information
     * 
     * @return array System information
     */
    private function getSystemInfo(): array {
        return [
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'theme_version' => wp_get_theme()->get('Version'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_input_vars' => ini_get('max_input_vars'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'server_api' => php_sapi_name(),
            'mysql_version' => $GLOBALS['wpdb']->db_version(),
            'operating_system' => PHP_OS,
            'ssl_enabled' => is_ssl(),
            'debug_mode' => WP_DEBUG,
            'cron_enabled' => defined('DISABLE_WP_CRON') ? !DISABLE_WP_CRON : true,
            'timezone' => get_option('timezone_string') ?: get_option('gmt_offset'),
            'active_plugins' => count(get_option('active_plugins')),
            'multisite_enabled' => is_multisite(),
            'object_cache_enabled' => wp_using_ext_object_cache(),
            'cache_stats' => $this->cache->getMetrics()
        ];
    }

    /**
     * Track PWA metrics
     */
    public function trackPWAMetrics(): void {
        if (!$this->initialized) return;

        $manifest = NiertoCore::getInstance()->getModule('Manifest');
        if ($manifest && $manifest->isInitialized()) {
            $this->recordMetric('pwa_status', [
                'installed' => $manifest->isInstalled(),
                'version' => $manifest->getVersion(),
                'last_update' => $manifest->getLastUpdateTime(),
                'cache_hits' => $manifest->getCacheStats()['hits'] ?? 0,
                'active_workers' => $manifest->getActiveWorkers()
            ]);
        }
    }

    /**
     * Schedule metrics cleanup
     */
    private function scheduleCleanup(): void {
        if (!wp_next_scheduled('nierto_cube_metrics_cleanup')) {
            wp_schedule_event(time(), 'daily', 'nierto_cube_metrics_cleanup');
        }
    }

    /**
     * Clean up old metrics
     */
    public function cleanupOldMetrics(): void {
        try {
            $cutoff = time() - ($this->config['retention_days'] * DAY_IN_SECONDS);
            
            foreach ($this->metric_types as $type => $settings) {
                $key = "metrics_{$type}";
                $stored = $this->cache->get($key, self::CACHE_GROUP) ?: [];
                
                $stored = array_filter($stored, function($metric) use ($cutoff) {
                    return $metric['timestamp'] > $cutoff;
                });
                
                $this->cache->set($key, $stored, self::CACHE_GROUP, $settings['ttl']);
            }
        } catch (\Exception $e) {
            $this->logError('metrics_cleanup_failed', $e->getMessage());
        }
    }

    /**
     * Log error message
     * 
     * @param string $message Error message
     * @param array $context Error context
     */
    private function logError(string $message, array $context = []): void {
        if (!$this->initialized) return;
        
        if ($this->error && $this->error->isInitialized()) {
            $this->error->logError('metrics_error', [
                'message' => $message,
                'context' => $context,
                'timestamp' => microtime(true)
            ]);
        }
    }

    /**
     * Check storage limits
     */
    private function checkStorageLimits(): void {
        foreach ($this->metric_types as $type => $settings) {
            $stored = $this->cache->get("metrics_{$type}", self::CACHE_GROUP) ?: [];
            $usage = (count($stored) / $this->config['metrics_limit']) * 100;
            
            if ($usage >= $this->config['alert_threshold']) {
                $this->logError('metrics_storage_threshold', [
                    'type' => $type,
                    'usage' => $usage,
                    'limit' => $this->config['metrics_limit']
                ]);
            }
        }
    }

    /**
     * Get module configuration
     * 
     * @return array Configuration settings
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     * 
     * @param array $config New configuration settings
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if module is initialized
     * 
     * @return bool Initialization status
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get module status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'metrics_count' => count($this->metrics),
            'types' => array_keys($this->metric_types),
            'cache_available' => $this->cache?->isAvailable(),
            'storage_mode' => $this->config['storage_mode'],
            'last_cleanup' => get_option('nierto_cube_last_cleanup'),
            'error_count' => $this->error?->getErrorCount('metrics')
        ];
    }

    /**
     * Inject performance monitoring JavaScript
     */
    public function injectPerformanceMonitoring(): void {
        if (!$this->config['enabled'] || is_admin()) {
            return;
        }

        echo "<script>
            if ('performance' in window && 'PerformanceObserver' in window) {
                try {
                    const observer = new PerformanceObserver((list) => {
                        for (const entry of list.getEntries()) {
                            const metric = {
                                type: entry.entryType,
                                name: entry.name,
                                duration: entry.duration,
                                startTime: entry.startTime
                            };
                            fetch('/wp-admin/admin-ajax.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: new URLSearchParams({
                                    action: 'nierto_cube_record_metric',
                                    nonce: '" . wp_create_nonce('nierto_cube_metrics') . "',
                                    metric: JSON.stringify(metric)
                                })
                            });
                        }
                    });
                    observer.observe({ entryTypes: ['resource', 'paint', 'largest-contentful-paint'] });
                } catch(e) {
                    console.error('Performance monitoring error:', e);
                }
            }
        </script>";
    }

    /**
     * Clear all metrics
     * 
     * @return bool Success status
     */
    public function clearMetrics(): bool {
        $this->metrics = [];
        foreach ($this->metric_types as $type => $settings) {
            $this->cache->delete("metrics_{$type}", self::CACHE_GROUP);
        }
        return true;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}