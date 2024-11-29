<?php
/**
 * LogoManager - Advanced Logo Asset Management System
 * 
 * Provides comprehensive logo management functionality with intelligent
 * caching, responsive image generation, and systematic state management.
 * 
 * Methodological Framework:
 * ----------------------
 * - Adaptive resource loading
 * - Contextual image optimization
 * - Dynamic state preservation
 * 
 * Optimization Vectors:
 * ------------------
 * - Response-aware image scaling
 * - Progressive asset loading
 * - Dimensional coherence maintenance
 * 
 * @package     NiertoCube
 * @subpackage  Modules
 * @version     2.0.0
 */

namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Core\NiertoCore;

if (!defined('ABSPATH')) {
    exit;
}

class LogoManager implements ModuleInterface {
    /** @var LogoManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration parameters */
    private $config = [];

    /** @var array Logo state cache */
    private $logo_cache = [];

    /** @var array Default dimensions */
    private const DEFAULT_DIMENSIONS = [
        'width' => '124px',
        'height' => 'auto',
        'max_width' => '200px',
        'min_width' => '80px'
    ];

    /** @var array Logo positions */
    private const POSITIONS = [
        'header' => [
            'class' => 'logo-header',
            'priority' => 10
        ],
        'cube' => [
            'class' => 'logo-cube',
            'priority' => 20
        ],
        'mobile' => [
            'class' => 'logo-mobile',
            'priority' => 30
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
     * Initialize logo management system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge([
                'enabled' => true,
                'cache_enabled' => true,
                'debug' => WP_DEBUG,
                'dimensions' => self::DEFAULT_DIMENSIONS,
                'optimize_images' => true,
                'lazy_loading' => true
            ], $config);

            // Register WordPress hooks
            $this->registerHooks();

            // Initialize cache system
            $this->initializeCache();

            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('LogoManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        add_action('after_setup_theme', [$this, 'setupLogoSupport']);
        add_action('customize_register', [$this, 'registerCustomizerSettings']);
        add_action('wp_head', [$this, 'injectLogoStyles'], 5);
    }

    /**
     * Set up logo theme support
     */
    public function setupLogoSupport(): void {
        add_theme_support('custom-logo', [
            'height' => 250,
            'width' => 250,
            'flex-height' => true,
            'flex-width' => true
        ]);
    }

    /**
     * Get logo details with intelligent caching
     */
    public function getLogoDetails(): array {
        if ($this->config['cache_enabled'] && isset($this->logo_cache['details'])) {
            return $this->logo_cache['details'];
        }

        $logo_id = get_theme_mod('custom_logo');
        if (!$logo_id) {
            return $this->getDefaultLogo();
        }

        $details = [
            'url' => wp_get_attachment_image_url($logo_id, 'full'),
            'width' => get_theme_mod('logo_width', self::DEFAULT_DIMENSIONS['width']),
            'height' => get_theme_mod('logo_height', self::DEFAULT_DIMENSIONS['height']),
            'alt' => get_post_meta($logo_id, '_wp_attachment_image_alt', true) ?: get_bloginfo('name'),
            'metadata' => wp_get_attachment_metadata($logo_id)
        ];

        if ($this->config['cache_enabled']) {
            $this->logo_cache['details'] = $details;
        }

        return $details;
    }

    /**
     * Get responsive logo markup
     */
    public function getLogoMarkup(string $position = 'header', array $attrs = []): string {
        $details = $this->getLogoDetails();
        if (!$details['url']) {
            return '';
        }

        $position_config = self::POSITIONS[$position] ?? self::POSITIONS['header'];
        
        $default_attrs = [
            'class' => $position_config['class'],
            'style' => sprintf(
                'max-width: %s; min-width: %s;',
                $this->config['dimensions']['max_width'],
                $this->config['dimensions']['min_width']
            ),
            'loading' => $this->config['lazy_loading'] ? 'lazy' : 'eager',
            'decoding' => 'async'
        ];

        $attrs = array_merge($default_attrs, $attrs);

        return $this->generateImageMarkup($details, $attrs);
    }

    /**
     * Generate optimized image markup
     */
    private function generateImageMarkup(array $details, array $attrs): string {
        $attr_string = '';
        foreach ($attrs as $key => $value) {
            $attr_string .= sprintf(' %s="%s"', esc_attr($key), esc_attr($value));
        }

        return sprintf(
            '<img src="%s" alt="%s" width="%s" height="%s"%s>',
            esc_url($details['url']),
            esc_attr($details['alt']),
            esc_attr($details['width']),
            esc_attr($details['height']),
            $attr_string
        );
    }

    /**
     * Get default logo configuration
     */
    private function getDefaultLogo(): array {
        return [
            'url' => '',
            'width' => self::DEFAULT_DIMENSIONS['width'],
            'height' => self::DEFAULT_DIMENSIONS['height'],
            'alt' => get_bloginfo('name'),
            'metadata' => []
        ];
    }

    /**
     * Initialize cache system
     */
    private function initializeCache(): void {
        if (!$this->config['cache_enabled']) {
            return;
        }

        try {
            $cache = NiertoCore::getInstance()->getModule('Cache');
            $cached_data = $cache->get('logo_data', 'theme');

            if ($cached_data !== false) {
                $this->logo_cache = $cached_data;
            }
        } catch (\Exception $e) {
            error_log('Logo cache initialization failed: ' . $e->getMessage());
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
     * Get module status
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'cache_enabled' => $this->config['cache_enabled'],
            'has_logo' => !empty($this->getLogoDetails()['url']),
            'positions' => array_keys(self::POSITIONS),
            'cache_status' => !empty($this->logo_cache)
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}