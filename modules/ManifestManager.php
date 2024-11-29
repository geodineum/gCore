<?php
/**
 * ManifestManager - Progressive Web App (PWA) Manifest Management
 * 
 * This class manages the Progressive Web App manifest functionality for the NiertoCube theme.
 * It handles manifest generation, caching, and delivery through both REST API and direct access.
 * It implements proper ModuleInterface integration within the NiertoCore system.
 * 
 * @package     NiertoCube
 * @subpackage  PWA
 * @version     2.0.0
 * @since       1.0.0
 * 
 * == File Purpose ==
 * Manages all aspects of PWA manifest functionality including generation, caching,
 * delivery, and customization through WordPress customizer integration.
 * 
 * == Key Functions ==
 * - getManifestData()      : Generates and returns manifest data
 * - registerEndpoints()     : Sets up REST API endpoints
 * - addManifestLink()      : Adds manifest link to wp_head
 * - registerCustomizerSettings() : Handles customizer integration
 * 
 * == Dependencies ==
 * Core:
 * - WordPress Core (add_action, rest_api_init, customize_register)
 * - NiertoCore system
 * - ModuleInterface
 * 
 * Managers (accessed through NiertoCore):
 * - ErrorManager  : For error logging
 * - CacheManager  : For manifest caching
 * 
 * == Integration Points ==
 * WordPress:
 * - rest_api_init : REST endpoint registration
 * - wp_head : Manifest link injection
 * - customize_register : Customizer settings
 * - customize_save_after : Cache invalidation
 * 
 * == Hook Integration ==
 * Actions:
 * - 'rest_api_init'          : Register REST endpoints
 * - 'wp_head'               : Output manifest link
 * - 'customize_register'     : Register customizer settings
 * - 'customize_save_after'   : Clear manifest cache
 * 
 * == Error Management ==
 * - Proper error logging through ErrorManager
 * - Fallback to error_log if ErrorManager unavailable
 * - Graceful degradation on manifest generation failure
 * - Cache system error handling
 * 
 * == Cache Implementation ==
 * - Uses CacheManager for manifest data
 * - Implements cache invalidation on customizer save
 * - Uses manifest-specific cache group
 * - Configurable TTL settings
 * 
 * == Security Measures ==
 * - Nonce verification for customizer
 * - Proper data sanitization
 * - Safe REST API endpoints
 * - XSS prevention in output
 * 
 * == Performance Considerations ==
 * - Efficient manifest caching
 * - Optimized REST API responses
 * - Minimal database queries
 * - Proper HTTP caching headers
 * 
 * == PWA Features ==
 * - Dynamic manifest generation
 * - Icon management
 * - Theme color support
 * - Display settings
 * - Language handling
 * 
 * == Customizer Integration ==
 * - PWA enable/disable
 * - Icon management
 * - Color settings
 * - Content configuration
 * 
 * == Future Improvements ==
 * @todo Add support for additional PWA features
 * @todo Implement manifest validation
 * @todo Add offline page configuration
 * @todo Consider adding service worker management
 * 
 * == Code Standards ==
 * - Follows WordPress PHP Documentation Standards
 * - Implements proper error handling
 * - Uses type hints for PHP 7.4+ compatibility
 * - Maintains singleton pattern integrity
 * 
 * == Changelog ==
 * 2.0.0
 * - Implemented proper NiertoCore integration
 * - Added comprehensive error handling
 * - Enhanced caching system
 * - Improved customizer integration
 * 
 * 1.0.0
 * - Initial implementation
 * - Basic manifest generation
 * - REST API support
 * 
 * @author    Niels Erik Toren
 * @copyright 2024 NiertoCube
 * @license   See project root for license information
 * @link      https://nierto.com Documentation
 * 
 * @see \NiertoCube\Core\ModuleInterface
 * @see \NiertoCube\Core\NiertoCore
 */


namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Core\NiertoCore;

if (!defined('ABSPATH')) {
    exit;
}

class ManifestManager implements ModuleInterface {
    /** @var ManifestManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration settings */
    private $config = [];

    /** @var array Default configuration */
    private const DEFAULT_CONFIG = [
        'enabled' => false,
        'cache_enabled' => true,
        'debug' => WP_DEBUG,
        'ttl' => DAY_IN_SECONDS,
        'icons_path' => '/assets/icons/',
        'manifest_version' => '1.0.0',
        'cache_group' => 'manifest'
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
     * Initialize module
     * 
     * @param array $config Configuration options
     * @throws \Exception If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            $this->config = array_merge(self::DEFAULT_CONFIG, $config);
            
            if ($this->config['enabled']) {
                $this->setupHooks();
            }

            $this->initialized = true;

        } catch (\Exception $e) {
            $this->logError('Initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Set up WordPress hooks
     */
    private function setupHooks(): void {
        add_action('rest_api_init', [$this, 'registerEndpoints']);
        add_action('wp_head', [$this, 'addManifestLink']);
        add_action('customize_register', [$this, 'registerCustomizerSettings']);
        add_action('customize_save_after', [$this, 'invalidateCache']);
    }

    /**
     * Register REST API endpoints
     */
    public function registerEndpoints(): void {
        register_rest_route('niertocube/v1', '/manifest', [
            'methods' => 'GET',
            'callback' => [$this, 'getManifestJson'],
            'permission_callback' => '__return_true'
        ]);
    }

    /**
     * Get manifest data
     * 
     * @return array Manifest data
     */
    public function getManifestData(): array {
        try {
            $core = NiertoCore::getInstance();
            
            // Try cache first
            if ($this->config['cache_enabled'] && $cache = $core->getModule('Cache')) {
                $manifest = $cache->get('manifest', $this->config['cache_group']);
                if ($manifest !== false) {
                    return $manifest;
                }
            }

            // Generate fresh manifest
            $manifest = [
                'name' => get_theme_mod('pwa_name', get_bloginfo('name')),
                'short_name' => get_theme_mod('pwa_short_name', substr(get_bloginfo('name'), 0, 12)),
                'description' => get_theme_mod('pwa_description', get_bloginfo('description')),
                'start_url' => home_url('/'),
                'display' => 'standalone',
                'background_color' => get_theme_mod('pwa_background_color', '#ffffff'),
                'theme_color' => get_theme_mod('pwa_theme_color', '#000000'),
                'icons' => $this->getManifestIcons(),
                'orientation' => 'any',
                'lang' => get_locale(),
                'dir' => is_rtl() ? 'rtl' : 'ltr',
                'scope' => home_url('/'),
                'version' => $this->config['manifest_version']
            ];

            // Cache the manifest
            if ($this->config['cache_enabled'] && isset($cache)) {
                $cache->set('manifest', $manifest, $this->config['cache_group'], $this->config['ttl']);
            }

            return $manifest;

        } catch (\Exception $e) {
            $this->logError('Manifest generation failed: ' . $e->getMessage());
            return $this->getDefaultManifest();
        }
    }

    /**
     * Get manifest icons configuration
     * 
     * @return array Icon configurations
     */
    private function getManifestIcons(): array {
        $icons = [];
        $sizes = ['192x192', '512x512'];

        foreach ($sizes as $size) {
            $icon_url = get_theme_mod("pwa_icon_{$size}", '');
            if ($icon_url) {
                $icons[] = [
                    'src' => $icon_url,
                    'sizes' => $size,
                    'type' => 'image/png',
                    'purpose' => 'any maskable'
                ];
            }
        }

        return $icons;
    }

    /**
     * Get default manifest
     * 
     * @return array Default manifest data
     */
    private function getDefaultManifest(): array {
        return [
            'name' => get_bloginfo('name'),
            'short_name' => substr(get_bloginfo('name'), 0, 12),
            'start_url' => home_url('/'),
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#000000',
            'icons' => []
        ];
    }

    /**
     * Output manifest link in header
     */
    public function addManifestLink(): void {
        if (!$this->config['enabled']) {
            return;
        }

        echo '<link rel="manifest" href="' . esc_url(rest_url('niertocube/v1/manifest')) . '">';
        echo '<meta name="theme-color" content="' . esc_attr(get_theme_mod('pwa_theme_color', '#000000')) . '">';
    }

    /**
     * Handle manifest JSON request
     * 
     * @return \WP_REST_Response
     */
    public function getManifestJson(): \WP_REST_Response {
        return new \WP_REST_Response(
            $this->getManifestData(),
            200,
            ['Cache-Control' => 'public, max-age=3600']
        );
    }

    /**
     * Register customizer settings
     * 
     * @param \WP_Customize_Manager $wp_customize
     */
    public function registerCustomizerSettings($wp_customize): void {
        // PWA Section
        $wp_customize->add_section('nierto_cube_pwa', [
            'title' => __('PWA Settings', 'nierto-cube'),
            'priority' => 35
        ]);

        // Enable PWA
        $wp_customize->add_setting('enable_pwa', [
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);

        $wp_customize->add_control('enable_pwa', [
            'label' => __('Enable PWA Functionality', 'nierto-cube'),
            'section' => 'nierto_cube_pwa',
            'type' => 'checkbox'
        ]);

        // Add other PWA settings...
        $this->registerIconSettings($wp_customize);
        $this->registerColorSettings($wp_customize);
        $this->registerContentSettings($wp_customize);
    }

    /**
     * Register icon settings
     * 
     * @param \WP_Customize_Manager $wp_customize
     */
    private function registerIconSettings($wp_customize): void {
        $sizes = ['192x192', '512x512'];

        foreach ($sizes as $size) {
            $wp_customize->add_setting("pwa_icon_{$size}", [
                'sanitize_callback' => 'esc_url_raw'
            ]);

            $wp_customize->add_control(
                new \WP_Customize_Image_Control($wp_customize, "pwa_icon_{$size}", [
                    'label' => sprintf(__('PWA Icon (%s)', 'nierto-cube'), $size),
                    'section' => 'nierto_cube_pwa'
                ])
            );
        }
    }

    /**
     * Register color settings
     * 
     * @param \WP_Customize_Manager $wp_customize
     */
    private function registerColorSettings($wp_customize): void {
        $colors = [
            'background_color' => __('Background Color', 'nierto-cube'),
            'theme_color' => __('Theme Color', 'nierto-cube')
        ];

        foreach ($colors as $key => $label) {
            $wp_customize->add_setting("pwa_{$key}", [
                'default' => '#ffffff',
                'sanitize_callback' => 'sanitize_hex_color'
            ]);

            $wp_customize->add_control(
                new \WP_Customize_Color_Control($wp_customize, "pwa_{$key}", [
                    'label' => $label,
                    'section' => 'nierto_cube_pwa'
                ])
            );
        }
    }

    /**
     * Register content settings
     * 
     * @param \WP_Customize_Manager $wp_customize
     */
    private function registerContentSettings($wp_customize): void {
        $settings = [
            'name' => __('PWA Name', 'nierto-cube'),
            'short_name' => __('PWA Short Name', 'nierto-cube'),
            'description' => __('PWA Description', 'nierto-cube')
        ];

        foreach ($settings as $key => $label) {
            $wp_customize->add_setting("pwa_{$key}", [
                'sanitize_callback' => 'sanitize_text_field'
            ]);

            $wp_customize->add_control("pwa_{$key}", [
                'label' => $label,
                'section' => 'nierto_cube_pwa',
                'type' => 'text'
            ]);
        }
    }

    /**
     * Invalidate manifest cache
     */
    public function invalidateCache(): void {
        $core = NiertoCore::getInstance();
        if ($cache = $core->getModule('Cache')) {
            $cache->delete('manifest', $this->config['cache_group']);
        }
    }

    /**
     * Log error message
     * 
     * @param string $message Error message
     */
    private function logError(string $message): void {
        $core = NiertoCore::getInstance();
        if ($error = $core->getModule('Error')) {
            $error->logError('manifest_error', $message);
        } else {
            error_log('ManifestManager: ' . $message);
        }
    }

    /**
     * Get module configuration
     * 
     * @return array Configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     * 
     * @param array $config New configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
        $this->invalidateCache();
    }

    /**
     * Check if module is initialized
     * 
     * @return bool
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
            'cache_enabled' => $this->config['cache_enabled'],
            'version' => $this->config['manifest_version'],
            'icons_configured' => !empty($this->getManifestIcons()),
            'customizer_active' => has_action('customize_register', [$this, 'registerCustomizerSettings'])
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}