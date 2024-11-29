<?php
/**
 * NiertoCube Asset Management System
 * 
 * Implements modular asset loading, dependency management, and optimization
 * through the NiertoCore architecture. Provides systematic approach to
 * script/style registration, localization, and conditional loading.
 *
 * @package     NiertoCube
 * @subpackage  Setup
 * @version     2.0.0
 */

namespace NiertoCube\Setup;

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Core\NiertoCore;

if (!defined('ABSPATH')) {
    exit;
}

class AssetManager implements ModuleInterface {
    /** @var AssetManager Singleton instance */
    private static $instance = null;

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Configuration settings */
    private $config = [];

    /** @var array Registered asset dependencies */
    private $dependencies = [
        'core' => [
            'scripts' => [
                'nierto-cube-performance' => [],
                'utils-script' => ['nierto-cube-performance'],
                'config-script' => ['utils-script', 'nierto-cube-performance'],
                'cookie-script' => ['utils-script'],
                'cube-script' => ['utils-script', 'config-script', 'cookie-script', 'nierto-cube-performance']
            ],
            'styles' => [
                'nierto-cube-all-styles' => [],
                'nierto-cube-style' => ['nierto-cube-all-styles']
            ]
        ]
    ];

    /** @var array Asset localization data */
    private $localization_data = [];

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
     * Initialize asset management system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            $this->config = array_merge([
                'theme_dir' => get_template_directory(),
                'theme_uri' => get_template_directory_uri(),
                'debug' => WP_DEBUG,
                'version_salt' => 'nierto_cube_v2',
                'defer_scripts' => true,
                'preload_critical' => true
            ], $config);

            $this->setupHooks();
            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('AssetManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Setup WordPress hooks
     */
    private function setupHooks(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_filter('script_loader_tag', [$this, 'modifyScriptLoading'], 10, 3);
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueueAssets(): void {
        try {
            $performance = NiertoCore::getInstance()->getModule('Metrics');
            $start_time = microtime(true);

            // Core Styles
            wp_enqueue_style(
                'nierto-cube-all-styles',
                $this->config['theme_uri'] . '/css/all-styles.css',
                [],
                $this->getAssetVersion('/css/all-styles.css')
            );

            wp_enqueue_style(
                'nierto-cube-style',
                get_stylesheet_uri(),
                ['nierto-cube-all-styles'],
                $this->getAssetVersion('/style.css')
            );

            // Core Scripts with Dependency Chain
            $this->enqueueCoreDependencyChain();

            // Conditional PWA Support
            if (is_front_page() && get_theme_mod('enable_pwa', 1)) {
                $this->enqueuePWASupport();
            }

            // Localize Script Data
            $this->localizeScripts();

            if ($performance) {
                $performance->recordMetric('asset_enqueue', [
                    'duration' => microtime(true) - $start_time,
                    'scripts' => count($GLOBALS['wp_scripts']->queue),
                    'styles' => count($GLOBALS['wp_styles']->queue)
                ]);
            }

        } catch (\Exception $e) {
            error_log('Asset enqueue failed: ' . $e->getMessage());
            
            // Fallback to critical assets
            wp_enqueue_style('nierto-cube-critical', get_stylesheet_uri());
        }
    }

    /**
     * Enqueue core dependency chain
     */
    private function enqueueCoreDependencyChain(): void {
        foreach ($this->dependencies['core']['scripts'] as $handle => $deps) {
            wp_enqueue_script(
                $handle,
                $this->config['theme_uri'] . "/js/{$handle}.js",
                $deps,
                $this->getAssetVersion("/js/{$handle}.js"),
                true
            );
        }
    }

    /**
     * Enqueue PWA support
     */
    private function enqueuePWASupport(): void {
        wp_enqueue_script(
            'pwa-script',
            $this->config['theme_uri'] . '/js/pwa.js',
            ['utils-script', 'nierto-cube-performance'],
            $this->getAssetVersion('/js/pwa.js'),
            true
        );
        wp_script_add_data('pwa-script', 'async', true);

        $this->localization_data['pwa'] = [
            'themeUrl' => $this->config['theme_uri'] . '/',
            'installBanner' => get_theme_mod('pwa_install_banner', '')
        ];
    }
/* */
    private function setupCustomizerPreview(): void {
        if (is_customize_preview()) {
            wp_enqueue_script(
                'nierto-cube-customizer-preview',
                $this->config['theme_uri'] . '/js/customizer-preview.js',
                ['customize-preview', 'cube-script'],
                $this->getAssetVersion('/js/customizer-preview.js'),
                true
            );

            // Add customizer settings for live preview
            foreach (['text', 'slug', 'position', 'type'] as $setting) {
                for ($i = 1; $i <= 6; $i++) {
                    add_filter("customize_preview_cube_face_{$i}_{$setting}", function($value) {
                        return $value;
                    });
                }
            }
        }
    }

    /**
     * Localize script data
     */
    private function localizeScripts(): void {
        // Prepare cube faces data
        $cube_faces = [];
        for ($i = 1; $i <= 6; $i++) {
            $cube_faces[] = [
                'buttonText' => get_theme_mod("cube_face_{$i}_text", "Face {$i}"),
                'urlSlug' => get_theme_mod("cube_face_{$i}_slug", "face-{$i}"),
                'facePosition' => get_theme_mod("cube_face_{$i}_position", "face" . ($i - 1)),
                'contentType' => get_theme_mod("cube_face_{$i}_type", "page")
            ];   
        }

        // Add preview data for customizer
        if (is_customize_preview()) {
            $this->localization_data['customizer'] = [
                'settings' => $this->getCustomizerSettings(),
                'transport' => 'postMessage'
            ];
            
            wp_localize_script(
                'nierto-cube-customizer-preview',
                'niertoCubeCustomizerPreview',
                $this->localization_data['customizer']
            );
        }

        // Core data structure
        $this->localization_data['core'] = [
            'resourceVersion' => get_option('nierto_cube_resource_version', '1.0'),
            'enablePWA' => get_theme_mod('enable_pwa', 0),
            'debugMode' => WP_DEBUG,
            'themeUrl' => get_template_directory_uri(),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nierto_cube_ajax')
        ];

        // Localize all data
        wp_localize_script('nierto-cube-performance', 'niertoCubeData', $this->localization_data['core']);
        wp_localize_script('cube-script', 'niertoCubeCustomizer', ['cubeFaces' => $cube_faces]);
    }

    private function getCustomizerSettings(): array {
        $settings = [];
        for ($i = 1; $i <= 6; $i++) {
            $settings["face_{$i}"] = [
                'text' => get_theme_mod("cube_face_{$i}_text"),
                'slug' => get_theme_mod("cube_face_{$i}_slug"),
                'position' => get_theme_mod("cube_face_{$i}_position"),
                'type' => get_theme_mod("cube_face_{$i}_type")
            ];
        }
        return $settings;
    }

    /**
     * Get asset version based on file modification time
     */
    private function getAssetVersion(string $file_path): string {
        $full_path = $this->config['theme_dir'] . $file_path;
        return file_exists($full_path) ? 
            hash('xxh32', $this->config['version_salt'] . filemtime($full_path)) : 
            '1.0.0';
    }

    /**
     * Modify script loading (defer/async)
     */
    public function modifyScriptLoading(string $tag, string $handle, string $src): string {
        if ($this->config['defer_scripts'] && !is_admin()) {
            if (isset($this->dependencies['core']['scripts'][$handle])) {
                return str_replace(' src', ' defer src', $tag);
            }
        }
        return $tag;
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
            'debug' => $this->config['debug'],
            'defer_enabled' => $this->config['defer_scripts'],
            'dependencies' => $this->dependencies,
            'localization' => array_keys($this->localization_data)
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}

// Initialize AssetManager
add_action('after_setup_theme', function() {
    try {
        $core = NiertoCore::getInstance();
        $core->registerModule(
            'Assets',
            AssetManager::class,
            ['Error', 'Metrics'],
            true,
            5
        );
    } catch (\Exception $e) {
        error_log('AssetManager registration failed: ' . $e->getMessage());
    }
}, 6);