<?php
/**
 * CustomizerManager - WordPress Customizer Integration for NiertoCube
 * 
 * Manages all customizer functionality including registration, preview handling,
 * and real-time updates. Implements ModuleInterface for NiertoCore integration.
 * 
 * @package     NiertoCube
 * @subpackage  Customizer
 * @version     2.0.0
 * 
 * Architectural Framework:
 * ----------------------
 * - Implements ModuleInterface for core system integration
 * - Manages customizer settings through section-based organization
 * - Handles real-time preview updates via JavaScript
 * - Integrates with cube face management system
 * 
 * Section Organization:
 * ------------------
 * 1. Colors
 * 2. Cube Settings
 * 3. Face Settings
 * 4. Navigation
 * 5. Typography
 * 6. Advanced Features
 * 
 * Integration Points:
 * ----------------
 * - WordPress Customizer API
 * - NiertoCore Module System
 * - PostTypeManager for face settings
 * - CacheManager for setting storage
 * 
 * @author    Niels Erik Toren
 * @since     2.0.0
 */

namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Core\NiertoCore;

if (!defined('ABSPATH')) {
    exit;
}

class CustomizerManager implements ModuleInterface {
    /** @var CustomizerManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration settings */
    private $config = [];
    
    /** @var array Registered sections */
    private $sections = [];
    
    /** @var array Default settings */
    private const DEFAULT_SETTINGS = [
        'colors' => [
            'grad_color1' => '#ee7752',
            'grad_color2' => '#e73c7e',
            'grad_color3' => '#23a6d5',
            'grad_color4' => '#23d5ab',
            'color_background' => '#F97162',
            'color_text' => '#F97162',
            'color_header' => '#FEFEF9',
            'color_border' => '#F5F9E9',
            'color_highlight' => '#F5F9E9',
            'color_hover' => '#F5F9E9'
        ],
        'cube' => [
            'perspective_scene' => '200vmin',
            'perspective_origin_scene' => '50% 50%',
            'default_cubeheight' => '80vmin',
            'default_cubewidth' => '80vmin'
        ],
        'navigation' => [
            'nav_button_bg_color' => '#ffffff',
            'nav_button_text_color' => '#000000',
            'nav_button_padding' => '10px 20px',
            'nav_button_border_radius' => '20%'
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
     * Initialize customizer system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge([
                'enabled' => true,
                'preview_mode' => true,
                'cache_enabled' => true,
                'debug' => WP_DEBUG,
                'sections' => self::DEFAULT_SETTINGS
            ], $config);

            // Register WordPress hooks
            $this->registerHooks();

            // Initialize customizer sections
            $this->initializeSections();

            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('CustomizerManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        add_action('customize_register', [$this, 'registerCustomizerSettings']);
        add_action('customize_preview_init', [$this, 'enqueuePreviewAssets']);
        add_action('customize_save_after', [$this, 'handleCustomizerSave']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets']);
    }

    /**
     * Initialize customizer sections
     */
    private function initializeSections(): void {
        $this->sections = [
            'colors' => [
                'title' => __('Colors', 'nierto_cube'),
                'priority' => 30,
                'settings' => self::DEFAULT_SETTINGS['colors']
            ],
            'cube' => [
                'title' => __('Cube Settings', 'nierto_cube'),
                'priority' => 35,
                'settings' => self::DEFAULT_SETTINGS['cube']
            ],
            'navigation' => [
                'title' => __('Navigation', 'nierto_cube'),
                'priority' => 40,
                'settings' => self::DEFAULT_SETTINGS['navigation']
            ]
        ];
    }

    /**
     * Register customizer settings
     */
    public function registerCustomizerSettings($wp_customize): void {
        foreach ($this->sections as $section_id => $section) {
            $wp_customize->add_section("nierto_cube_{$section_id}", [
                'title' => $section['title'],
                'priority' => $section['priority']
            ]);

            foreach ($section['settings'] as $setting_id => $default) {
                $this->registerSetting($wp_customize, $setting_id, $default, $section_id);
            }
        }

        // Register cube face settings
        $this->registerCubeFaceSettings($wp_customize);
    }

    /**
     * Register individual setting
     */
    private function registerSetting($wp_customize, $setting_id, $default, $section_id): void {
        $setting_args = [
            'default' => $default,
            'transport' => 'postMessage',
            'sanitize_callback' => [$this, 'sanitizeSettingValue']
        ];

        $wp_customize->add_setting($setting_id, $setting_args);

        $control_args = $this->getControlArgs($setting_id, $section_id);
        
        if (strpos($setting_id, 'color') !== false) {
            $wp_customize->add_control(
                new \WP_Customize_Color_Control(
                    $wp_customize,
                    $setting_id,
                    $control_args
                )
            );
        } else {
            $wp_customize->add_control($setting_id, $control_args);
        }
    }

    /**
     * Get control arguments based on setting type
     */
    private function getControlArgs($setting_id, $section_id): array {
        $args = [
            'label' => $this->getSettingLabel($setting_id),
            'section' => "nierto_cube_{$section_id}"
        ];

        if (strpos($setting_id, 'dimension') !== false) {
            $args['type'] = 'text';
            $args['input_attrs'] = [
                'placeholder' => '80vmin'
            ];
        }

        return $args;
    }

    /**
     * Register cube face settings
     */
    private function registerCubeFaceSettings($wp_customize): void {
        $wp_customize->add_section('cube_face_settings', [
            'title' => __('Cube Faces', 'nierto_cube'),
            'priority' => 35
        ]);

        for ($i = 1; $i <= 6; $i++) {
            $this->registerFaceSetting($wp_customize, $i);
        }
    }

    /**
     * Register individual face setting
     */
    private function registerFaceSetting($wp_customize, $face_number): void {
        $settings = [
            'text' => [
                'default' => "Face {$face_number}",
                'type' => 'text'
            ],
            'type' => [
                'default' => 'cube_face',
                'type' => 'select',
                'choices' => [
                    'page' => 'Page (iframe)',
                    'cube_face' => 'Cube Face'
                ]
            ],
            'slug' => [
                'default' => "face-{$face_number}",
                'type' => 'text'
            ],
            'position' => [
                'default' => "face" . ($face_number - 1),
                'type' => 'select',
                'choices' => [
                    'face0' => 'Face 0',
                    'face1' => 'Face 1 = Front',
                    'face2' => 'Face 2',
                    'face3' => 'Face 3 = Back',
                    'face4' => 'Face 4',
                    'face5' => 'Face 5'
                ]
            ]
        ];

        foreach ($settings as $key => $setting) {
            $setting_id = "cube_face_{$face_number}_{$key}";
            
            $wp_customize->add_setting($setting_id, [
                'default' => $setting['default'],
                'transport' => 'postMessage',
                'sanitize_callback' => [$this, 'sanitizeSettingValue']
            ]);

            $wp_customize->add_control($setting_id, [
                'label' => sprintf(__('Face %d %s', 'nierto_cube'), $face_number, ucfirst($key)),
                'section' => 'cube_face_settings',
                'type' => $setting['type'],
                'choices' => $setting['choices'] ?? null
            ]);
        }
    }

    /**
     * Enqueue preview assets
     */
    public function enqueuePreviewAssets(): void {
        wp_enqueue_script(
            'nierto-cube-customizer-preview',
            get_template_directory_uri() . '/js/customizer-preview.js',
            ['customize-preview'],
            filemtime(get_template_directory() . '/js/customizer-preview.js'),
            true
        );

        wp_localize_script('nierto-cube-customizer-preview', 'niertoCubeCustomizer', [
            'settings' => $this->getCustomizerSettings(),
            'sections' => array_keys($this->sections),
            'nonce' => wp_create_nonce('nierto_cube_customizer')
        ]);
    }

    /**
     * Handle customizer save
     */
    public function handleCustomizerSave(): void {
        if ($this->config['cache_enabled']) {
            $cache = NiertoCore::getInstance()->getModule('Cache');
            $cache->flush('customizer');
        }

        do_action('nierto_cube_customizer_saved');
    }

    /**
     * Sanitize setting value
     */
    public function sanitizeSettingValue($value, $setting = null) {
        if (strpos($setting->id, 'color') !== false) {
            return sanitize_hex_color($value);
        }

        if (strpos($setting->id, 'dimension') !== false) {
            return preg_match('/^\d+(\.\d+)?(px|em|rem|%|vw|vh|vmin|vmax)$/', $value) ? $value : '';
        }

        return sanitize_text_field($value);
    }

    /**
     * Get customizer settings
     */
    private function getCustomizerSettings(): array {
        $settings = [];
        foreach ($this->sections as $section_id => $section) {
            foreach ($section['settings'] as $setting_id => $default) {
                $settings[$setting_id] = get_theme_mod($setting_id, $default);
            }
        }
        return $settings;
    }

    /**
     * Get setting label
     */
    private function getSettingLabel($setting_id): string {
        return ucwords(str_replace('_', ' ', $setting_id));
    }

    /**
     * Get configuration
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
     * Check if initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get status information
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'preview_mode' => $this->config['preview_mode'],
            'cache_enabled' => $this->config['cache_enabled'],
            'sections' => array_keys($this->sections),
            'settings_count' => array_sum(array_map(
                fn($section) => count($section['settings']),
                $this->sections
            ))
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}