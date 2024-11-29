<?php
/**
 * APIManager - Independent REST API Management System
 * 
 * Provides comprehensive REST API functionality for the NiertoCube theme without external
 * manager dependencies. Handles endpoint registration, request processing, response caching,
 * rate limiting, and performance monitoring as a base-level service.
 * 
 * @package     NiertoCube
 * @subpackage  Core
 * @version     2.0.0
 * @since       1.0.0
 * 
 * == File Purpose ==
 * Serves as a standalone API management system, handling all REST API operations
 * including endpoint registration, request processing, caching, and rate limiting
 * without requiring other manager dependencies.
 * 
 * == Key Functions ==
 * - registerCoreEndpoints()  : Registers default API endpoints
 * - registerEndpoint()       : Registers custom API endpoints
 * - handleRequest()         : Processes API requests with caching and rate limiting
 * - getFaceContent()        : Handles cube face content retrieval
 * - getManifest()          : Manages PWA manifest delivery
 * 
 * == Dependencies ==
 * Core:
 * - WordPress REST API
 * - WordPress Transients API
 * - WordPress Options API
 * - ModuleInterface
 * 
 * No Manager Dependencies:
 * - Independent error handling
 * - Self-contained caching
 * - Standalone rate limiting
 * - Internal metrics tracking
 * 
 * == Caching System ==
 * Cache Groups:
 * - face: Face content (TTL: 3600s)
 * - manifest: PWA manifest (TTL: 86400s)
 * - api: General responses (TTL: 1800s)
 * 
 * == Rate Limiting ==
 * Configurations:
 * - default: 60 requests per 60 seconds
 * - content: 60 requests per 60 seconds
 * - manifest: 30 requests per 60 seconds
 * 
 * == Error Management ==
 * - Independent error logging system
 * - Debug mode support
 * - Contextual error tracking
 * - Performance impact monitoring
 * 
 * == Performance Tracking ==
 * Metrics:
 * - Request counts
 * - Cache hit/miss rates
 * - Error tracking
 * - Response timing
 * - Rate limit status
 * 
 * == Security Measures ==
 * - Rate limiting by IP
 * - Permission validation
 * - Input sanitization
 * - Response sanitization
 * - Cache key security
 * 
 * == REST Endpoints ==
 * Core Endpoints:
 * - /face-content/{type}/{slug} : Face content retrieval
 * - /manifest : PWA manifest data
 * - /cache/clear : Cache management (admin only)
 * 
 * == Cache Implementation ==
 * - Uses WordPress transients
 * - Group-based storage
 * - Automatic invalidation
 * - Performance optimization
 * 
 * == Integration Points ==
 * WordPress Hooks:
 * - rest_api_init : Endpoint registration
 * - save_post_cube_face : Cache invalidation
 * 
 * == Performance Considerations ==
 * - Efficient cache implementation
 * - Optimized response handling
 * - Minimal database queries
 * - Response time tracking
 * - Resource usage monitoring
 * 
 * == Future Improvements ==
 * @todo Implement advanced caching strategy
 * @todo Add request batching support
 * @todo Enhance rate limit configurations
 * @todo Add response compression
 * @todo Implement request validation middleware
 * 
 * == Error Handling ==
 * - Request validation errors
 * - Processing failures
 * - Cache system errors
 * - Rate limit violations
 * - Permission issues
 * 
 * == Code Standards ==
 * - Follows WordPress REST API standards
 * - Implements proper error handling
 * - Uses type hints for PHP 7.4+
 * - Maintains singleton pattern integrity
 * 
 * == Changelog ==
 * 2.0.0
 * - Removed manager dependencies
 * - Implemented standalone functionality
 * - Added comprehensive rate limiting
 * - Enhanced performance tracking
 * - Improved cache management
 * 
 * 1.0.0
 * - Initial implementation
 * - Basic REST API support
 * - Simple caching system
 * 
 * @author    Niels Erik Toren
 * @copyright 2024 NiertoCube
 * @license   See project root for license information
 * @link      https://nierto.com Documentation
 * 
 * @see \NiertoCube\Core\ModuleInterface
 * @see WP_REST_Request
 * @see WP_REST_Response
 */

namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class APIManager implements ModuleInterface {
    /** @var APIManager Singleton instance */
    private static $instance = null;
    
    /** @var array Configuration */
    private $config = [];
    
    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Performance metrics */
    private $metrics = [
        'requests' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'errors' => 0,
        'response_times' => []
    ];

    /** @var array Rate limiting configuration */
    private const RATE_LIMITS = [
        'default' => [
            'requests' => 60,
            'window' => 60
        ],
        'content' => [
            'requests' => 60,
            'window' => 60
        ],
        'manifest' => [
            'requests' => 30,
            'window' => 60
        ]
    ];

    /** @var array Cache groups configuration */
    private const CACHE_GROUPS = [
        'face' => ['ttl' => 3600],
        'manifest' => ['ttl' => 86400],
        'api' => ['ttl' => 1800]
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
     * Initialize API manager
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            $this->config = array_merge([
                'enabled' => true,
                'cache_enabled' => true,
                'rate_limiting' => true,
                'cache_ttl' => HOUR_IN_SECONDS,
                'debug' => WP_DEBUG,
                'namespace' => 'niertocube/v1'
            ], $config);

            add_action('rest_api_init', [$this, 'registerCoreEndpoints']);
            add_action('save_post_cube_face', [$this, 'clearFaceCache']);
            
            $this->initialized = true;

        } catch (\Exception $e) {
            $this->logAPIError('Initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register core endpoints
     */
    public function registerCoreEndpoints(): void {
        // Face content endpoint
        $this->registerEndpoint('face-content/(?P<type>[\w-]+)/(?P<slug>[\w-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getFaceContent'],
            'permission_callback' => '__return_true',
            'args' => [
                'type' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'slug' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title'
                ]
            ]
        ]);

        // Manifest endpoint
        $this->registerEndpoint('manifest', [
            'methods' => 'GET',
            'callback' => [$this, 'getManifest'],
            'permission_callback' => '__return_true'
        ]);

        // Cache control endpoint
        $this->registerEndpoint('cache/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clearCache'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }

    /**
     * Get face content
     */
    public function getFaceContent(WP_REST_Request $request): WP_REST_Response {
        try {
            $type = $request->get_param('type');
            $slug = $request->get_param('slug');
            
            if ($type === 'page') {
                $page = get_page_by_path($slug);
                if ($page) {
                    return new WP_REST_Response([
                        'type' => 'page',
                        'content' => get_permalink($page->ID)
                    ]);
                }
            } else {
                $posts = get_posts([
                    'name' => $slug,
                    'post_type' => $type,
                    'post_status' => 'publish',
                    'numberposts' => 1
                ]);

                if ($posts) {
                    $post = $posts[0];
                    return new WP_REST_Response([
                        'type' => 'post',
                        'content' => apply_filters('the_content', $post->post_content),
                        'title' => $post->post_title,
                        'template' => get_post_meta($post->ID, '_cube_face_template', true),
                        'position' => get_post_meta($post->ID, '_cube_face_position', true)
                    ]);
                }
            }

            return new WP_REST_Response(['error' => 'Content not found'], 404);

        } catch (\Exception $e) {
            $this->logAPIError('Face content retrieval failed: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Get manifest data
     */
    public function getManifest(WP_REST_Request $request): WP_REST_Response {
        try {
            $manifest = [
                'name' => get_bloginfo('name'),
                'short_name' => get_theme_mod('pwa_short_name', get_bloginfo('name')),
                'description' => get_bloginfo('description'),
                'start_url' => home_url('/'),
                'display' => 'standalone',
                'background_color' => get_theme_mod('pwa_background_color', '#ffffff'),
                'theme_color' => get_theme_mod('pwa_theme_color', '#000000'),
                'icons' => [
                    [
                        'src' => get_theme_mod('pwa_icon_192'),
                        'sizes' => '192x192',
                        'type' => 'image/png'
                    ],
                    [
                        'src' => get_theme_mod('pwa_icon_512'),
                        'sizes' => '512x512',
                        'type' => 'image/png'
                    ]
                ]
            ];

            return new WP_REST_Response($manifest);

        } catch (\Exception $e) {
            $this->logAPIError('Manifest generation failed: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'Failed to generate manifest'], 500);
        }
    }

    /**
     * Register custom endpoint
     */
    public function registerEndpoint(string $route, array $args): void {
        try {
            register_rest_route(
                $this->config['namespace'],
                $route,
                array_merge($args, [
                    'callback' => function($request) use ($args) {
                        return $this->handleRequest($request, $args['callback']);
                    }
                ])
            );
        } catch (\Exception $e) {
            $this->logAPIError('Endpoint registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle API request
     */
    private function handleRequest(WP_REST_Request $request, callable $callback): WP_REST_Response {
        $start_time = microtime(true);
        $endpoint = $request->get_route();

        try {
            // Check rate limiting
            if (!$this->checkRateLimit($endpoint)) {
                return new WP_REST_Response([
                    'error' => 'Rate limit exceeded'
                ], 429);
            }

            // Check cache
            if ($request->get_method() === 'GET' && $this->config['cache_enabled']) {
                $cache_key = $this->generateCacheKey($request);
                $group = $this->determineCacheGroup($endpoint);
                $cached = $this->getResponse($cache_key, $group);
                
                if ($cached !== null) {
                    $this->metrics['cache_hits']++;
                    return new WP_REST_Response($cached, 200);
                }
                $this->metrics['cache_misses']++;
            }

            // Process request
            $this->metrics['requests']++;
            $response = call_user_func($callback, $request);

            // Cache successful GET responses
            if ($request->get_method() === 'GET' && 
                $this->config['cache_enabled'] && 
                $response->get_status() === 200) {
                $this->cacheResponse(
                    $cache_key,
                    $response->get_data(),
                    self::CACHE_GROUPS[$group]['ttl'] ?? $this->config['cache_ttl'],
                    $group
                );
            }

            // Track response time
            $this->metrics['response_times'][] = microtime(true) - $start_time;

            return $response;

        } catch (\Exception $e) {
            $this->metrics['errors']++;
            $this->logAPIError($e->getMessage(), [
                'endpoint' => $endpoint,
                'method' => $request->get_method()
            ]);

            return new WP_REST_Response([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Determine cache group from endpoint
     */
    private function determineCacheGroup(string $endpoint): string {
        if (strpos($endpoint, '/face-content/') !== false) {
            return 'face';
        } elseif (strpos($endpoint, '/manifest') !== false) {
            return 'manifest';
        }
        return 'api';
    }

    /**
     * Check rate limiting
     */
    private function checkRateLimit(string $endpoint): bool {
        if (!$this->config['rate_limiting']) {
            return true;
        }

        $type = $this->determineRateLimitType($endpoint);
        $limits = self::RATE_LIMITS[$type] ?? self::RATE_LIMITS['default'];
        $key = $this->getRateLimitKey($endpoint);
        
        $current = (int)get_transient($key);
        
        if ($current >= $limits['requests']) {
            return false;
        }

        set_transient($key, $current + 1, $limits['window']);
        return true;
    }

    /**
     * Determine rate limit type from endpoint
     */
    private function determineRateLimitType(string $endpoint): string {
        if (strpos($endpoint, '/face-content/') !== false) {
            return 'content';
        } elseif (strpos($endpoint, '/manifest') !== false) {
            return 'manifest';
        }
        return 'default';
    }

    /**
     * Generate rate limit key
     */
    private function getRateLimitKey(string $endpoint): string {
        return sprintf(
            'api_rate_limit_%s_%s',
            md5($endpoint),
            $_SERVER['REMOTE_ADDR']
        );
    }

    /**
     * Generate cache key
     */
    private function generateCacheKey(WP_REST_Request $request): string {
        return sprintf(
            'api_response_%s_%s',
            md5($request->get_route()),
            md5(json_encode($request->get_params()))
        );
    }

    /**
     * Get cached response
     */
    private function getResponse(string $key, string $group): ?array {
        $data = get_transient("{$group}_{$key}");
        return $data !== false ? $data : null;
    }

    /**
     * Cache response
     */
    private function cacheResponse(string $key, $data, int $ttl, string $group): void {
        set_transient("{$group}_{$key}", $data, $ttl);
    }

    /**
     * Clear API cache
     */
    public function clearCache(WP_REST_Request $request): WP_REST_Response {
        try {
            global $wpdb;
            
            $group = $request->get_param('group');
            $where = $group ? 
                $wpdb->prepare(
                    "WHERE option_name LIKE %s",
                    $wpdb->esc_like("_transient_{$group}_api_response_") . '%'
                ) : 
                "WHERE option_name LIKE '_transient_%_api_response_%'";
            
            $wpdb->query("DELETE FROM {$wpdb->options} {$where}");

            return new WP_REST_Response([
                'message' => 'Cache cleared successfully'
            ]);

        } catch (\Exception $e) {
            $this->logAPIError('Cache clear failed: ' . $e->getMessage());
            return new WP_REST_Response([
                'error' => 'Failed to clear cache'
            ], 500);
        }
    }

    /**
     * Clear face cache when face is updated
     */
    public function clearFaceCache($post_id): void {
        $slug = get_post_field('post_name', $post_id);
        $this->clearCache(new WP_REST_Request(['group' => 'face']));
    }

    /**
     * Log API error
     */
    private function logAPIError(string $message, array $context = []): void {
        if ($this->config['debug']) {
            error_log(sprintf(
                'APIManager Error: %s | Context: %s',
                $message,
                json_encode($context)
            ));
        }
    }

    /**
     * Get module configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
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
     * Get module status and metrics
     */
    public function getStatus(): array {
        $avg_response_time = !empty($this->metrics['response_times']) 
            ? array_sum($this->metrics['response_times']) / count($this->metrics['response_times'])
            : 0;

        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'cache_enabled' => $this->config['cache_enabled'],
            'rate_limiting' => $this->config['rate_limiting'],
            'total_requests' => $this->metrics['requests'],
            'cache_hits' => $this->metrics['cache_hits'],
            'cache_misses' => $this->metrics['cache_misses'],
            'errors' => $this->metrics['errors'],
            'avg_response_time' => round($avg_response_time * 1000, 2) . 'ms',
            'cache_groups' => array_keys(self::CACHE_GROUPS),
            'rate_limits' => array_keys(self::RATE_LIMITS),
            'namespace' => $this->config['namespace'],
            'debug_mode' => $this->config['debug']
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}