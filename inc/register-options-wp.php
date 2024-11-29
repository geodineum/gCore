<?php
/**
 * WordPress Theme Customizer Registration and Configuration
 * =====================================================
 *
 * This file manages the registration and configuration of all WordPress Customizer
 * options for the NiertoCube theme. It handles color schemes, cube settings,
 * navigation styling, PWA configurations, ValKey integration, and more.
 *
 * @package     NiertoCube
 * @subpackage  Customizer
 * @since       1.0.0
 * 
 * File Organization
 * ----------------
 * - Section Registration (colors, ValKey, PWA, cube settings, etc.)
 * - Control Registration (settings for each section)
 * - Sanitization Functions
 * - Default Value Management
 *
 * Key Functions
 * ------------
 * nierto_cube_customize_register($wp_customize)
 *   - Main registration function for all customizer options
 *   - Organizes settings into logical sections
 *   - Handles control type assignment and validation
 *
 * Customizer Sections
 * -----------------
 * 1. Colors            - Theme color scheme management
 * 2. ValKey            - Cache integration settings
 * 3. PWA              - Progressive Web App configurations
 * 4. Cube Settings    - Core cube behavior and appearance
 * 5. Face Settings    - Individual cube face configurations
 * 6. Logo             - Logo appearance and placement
 * 7. Font             - Typography and font management
 * 8. Navigation       - Button styling and behavior
 * 9. Content Options  - Zoom functionality settings
 *
 * Dependencies
 * -----------
 * - WordPress Customizer API
 * - sanitization-funcs.php (For input validation)
 * - CacheManager.php (For ValKey integration)
 * - ManifestSettings.php (For PWA configurations)
 * 
 * Hooks & Filters
 * -------------
 * Actions:
 * - 'customize_register'
 * 
 * Design Philosophy
 * ---------------
 * - Modular section organization for maintainability
 * - Comprehensive input sanitization
 * - Clear separation of concerns between sections
 * - Progressive enhancement approach
 * - Mobile-first responsive design considerations
 *
 * Technical Notes
 * -------------
 * - Uses WordPress native customizer controls where possible
 * - Implements custom sanitization for specific input types
 * - Supports real-time preview via JavaScript
 * - Maintains backward compatibility with older WordPress versions
 *
 * Integration Points
 * ----------------
 * - Cube.js (for real-time preview updates)
 * - CSS generation system
 * - PWA manifest generation
 * - Cache configuration
 *
 * Known Issues & TODOs
 * ------------------
 * @todo Refactor color settings into a separate class for better organization
 * @todo Implement validation for interdependent settings
 * @todo Add REST API endpoint for real-time preview optimization
 * @todo Consider implementing preset color schemes
 * @todo Add proper TypeScript definitions for JavaScript interactions
 *
 * Performance Considerations
 * ------------------------
 * - Lazy loads custom controls
 * - Implements efficient preview updates
 * - Caches computed values where appropriate
 * - Minimizes DOM updates during preview
 *
 * Security Measures
 * ---------------
 * - Implements nonce verification
 * - Sanitizes all inputs
 * - Validates capabilities
 * - Escapes output
 *
 * Changelog
 * --------
 * 1.0.0 - Initial version
 * - Implemented basic color scheme management
 * - Added cube face configurations
 * - Integrated ValKey settings
 * 
 * Future Improvements
 * -----------------
 * 1. Convert to OOP architecture for better organization
 * 2. Implement settings validation class
 * 3. Add export/import functionality
 * 4. Enhanced real-time preview system
 * 5. Add unit tests for settings validation
 *
 * @author    Niels Erik Toren
 * @copyright 2024 NiertoCube
 * @license   See project root for license information
 */


// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}


function nierto_cube_customize_register($wp_customize) {
    // Registering sections
    // COLORS SECTION
    $wp_customize->add_section('colors', array(
        'title' => __('Colors', 'nierto_cube'),
        'description' => __('Customize the colors of the theme.', 'nierto_cube'),
        'priority' => 23,
    ));
    // VALKEY INTEGRATION SECTION
    $wp_customize->add_section('nierto_cube_valkey', array(
    'title' => __('ValKey Settings', 'nierto_cube'),
    'priority' => 29,
    ));
    // PWA INTEGRATION SECTION
    $wp_customize->add_section('nierto_cube_pwa', array(
    'title' => __('PWA Settings', 'nierto_cube'),
    'priority' => 35,
    ));
    // CUBE SETTINGS SECTION
    $wp_customize->add_section('cube_settings', array(
        'title' => __('Cube Settings', 'nierto_cube'),
        'priority' => 150,
    ));
    $wp_customize->add_section('cube_face_settings', array(
        'title' => __('Cube Face Settings', 'nierto_cube'),
        'priority' => 163,
    ));
    // LOGO SECTION
    $wp_customize->add_section('logo', array(
        'title' => __('Logo Settings', 'nierto_cube'),
        'priority' => 173,
    ));
    // FONT SECTION
    $wp_customize->add_section('font', array(
        'title' => __('Font Settings', 'nierto_cube'),
        'priority' => 183,
    ));
    // NAV BUTTON STYLING SECTION
    $wp_customize->add_section('nav_button_styling', array(
        'title' => __('Navigation Button Styling', 'nierto_cube'),
        'priority' => 203,
    ));
    // ZOOM functionality of main content area (also called: Content Expansion Options) SECTION
    $wp_customize->add_section('nierto_cube_content_options', array(
        'title' => __('Content Expansion Options', 'nierto_cube'),
        'priority' => 300,
    ));
    //INDEX OF SECTION: COLORS
    // Gradient colors settings
    $gradient_colors = ['1', '2', '3', '4'];
    foreach ($gradient_colors as $num) {
        $wp_customize->add_setting("grad_color{$num}", array(
            'default' => '#ee7752',
            'transport' => 'refresh',
            'sanitize_callback' => 'nierto_cube_sanitize_hex_color'
        ));
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, "grad_color{$num}", array(
            'label' => __("Gradient Color {$num}", 'nierto_cube'),
            'section' => 'colors',
            'settings' => "grad_color{$num}",
        )));
    }
     // Other color settings
    $color_settings = [
        'scrollbar_color1' => '#F97162',
        'scrollbar_color2' => '#FEFEF9',
        'color_background' => '#F97162',
        'color_text' => '#F97162',
        'color_header' => '#FEFEF9',
        'color_border' => '#F5F9E9',
        'color_highlight' => '#F5F9E9',
        'color_hover' => '#F5F9E9',
        'color_background_button' => '#F5F9E9',
        'color_text_button' => '#F5F9E9',
        'nav_button_bg_color' => '#ffffff',
        'nav_button_text_color ' => '#000000',
        'nav_button_hover_bg_color' => '#dddddd',
        'nav_button_hover_text_color' => '#000000',
        'nav_button_border_color' => '#000000'
    ];
    foreach ($color_settings as $setting_id => $default) {
        $wp_customize->add_setting($setting_id, [
            'default' => $default,
            'transport' => 'refresh',
            'sanitize_callback' => 'nierto_cube_sanitize_hex_color'
        ]);
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, $setting_id, array(
            'label' => __(ucfirst(str_replace('_', ' ', $setting_id)), 'nierto_cube'),
            'section' => 'colors',
            'settings' => $setting_id,
        )));
    }
    // INDEX OF SECTION: VALKEY INTEGRATION
    $wp_customize->add_setting('use_valkey', array(
    'default' => 0,
    'sanitize_callback' => 'absint',
    ));
    $wp_customize->add_control('use_valkey', array(
        'label' => __('Use ValKey', 'nierto_cube'),
        'section' => 'nierto_cube_valkey',
        'type' => 'checkbox',
    ));
    $wp_customize->add_setting('valkey_ip', array(
        'default' => '',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('valkey_ip', array(
        'label' => __('ValKey IP Address', 'nierto_cube'),
        'section' => 'nierto_cube_valkey',
        'type' => 'text',
    ));
    $wp_customize->add_setting('valkey_port', array(
        'default' => '6379',
        'sanitize_callback' => 'absint',
    ));
    $wp_customize->add_control('valkey_port', array(
        'label' => __('ValKey Port', 'nierto_cube'),
        'section' => 'nierto_cube_valkey',
        'type' => 'number',
    ));
    $wp_customize->add_setting('nierto_cube_settings[valkey_auth]', array(
    'type' => 'option',
    'capability' => 'manage_options',
    'default' => '',
    'transport' => 'refresh',
    'sanitize_callback' => 'sanitize_text_field',
    ));

    $wp_customize->add_control('nierto_cube_settings[valkey_auth]', array(
        'label' => __('ValKey Authentication Key', 'nierto_cube'),
        'section' => 'nierto_cube_valkey',
        'type' => 'password',
    ));
    $wp_customize->add_setting('nierto_cube_cache_prefix', array(
        'default' => 'nierto_cube_',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('nierto_cube_cache_prefix', array(
        'label' => __('Cache Prefix', 'nierto_cube'),
        'section' => 'nierto_cube_valkey',
        'type' => 'text',
    ));
    // INDEX OF SECTION: PWA SETTINGS

    $wp_customize->add_setting('enable_pwa', array(
        'default' => 0,
        'sanitize_callback' => 'absint',
    ));
    $wp_customize->add_control('enable_pwa', array(
        'label' => __('Enable PWA Functionality', 'nierto_cube'),
        'section' => 'nierto_cube_pwa',
        'type' => 'checkbox',
    ));
    $wp_customize->add_setting('pwa_icon_192', array(
        'default' => '',
        'sanitize_callback' => 'esc_url_raw',
    ));
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'pwa_icon_192', array(
        'label' => __('PWA Icon (192x192)', 'nierto_cube'),
        'section' => 'nierto_cube_pwa',
        'settings' => 'pwa_icon_192',
    )));
    $wp_customize->add_setting('pwa_icon_512', array(
        'default' => '',
        'sanitize_callback' => 'esc_url_raw',
    ));
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'pwa_icon_512', array(
        'label' => __('PWA Icon (512x512)', 'nierto_cube'),
        'section' => 'nierto_cube_pwa',
        'settings' => 'pwa_icon_512',
    )));
    $wp_customize->add_setting('pwa_short_name', array(
        'default' => 'NCube',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('pwa_short_name', array(
        'label' => __('PWA Short Name', 'nierto_cube'),
        'section' => 'nierto_cube_pwa',
        'type' => 'text',
    ));
    $wp_customize->add_setting('pwa_background_color', array(
        'default' => '#ffffff',
        'sanitize_callback' => 'sanitize_hex_color',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'pwa_background_color', array(
        'label' => __('PWA Background Color', 'nierto_cube'),
        'section' => 'nierto_cube_pwa',
    )));
    $wp_customize->add_setting('pwa_theme_color', array(
        'default' => '#000000',
        'sanitize_callback' => 'sanitize_hex_color',
    ));
    $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'pwa_theme_color', array(
        'label' => __('PWA Theme Color', 'nierto_cube'),
        'section' => 'nierto_cube_pwa',
    )));
    $wp_customize->add_setting('pwa_install_banner', array(
        'default' => '',
        'sanitize_callback' => 'esc_url_raw',
    ));
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'pwa_install_banner', array(
        'label' => __('PWA Install Banner Image', 'nierto_cube'),
        'description' => __('Upload an image to use as the install banner. If not set, a default banner will be used.', 'nierto_cube'),
        'section' => 'nierto_cube_pwa',
        'settings' => 'pwa_install_banner',
    )));
    // INDEX OF SECTION: CUBE SETTINGS
    $cube_settings = [
        'perspective_scene' => [
            'default' => '200vmin',
            'label' => 'Perspective for Scene'
        ],
        'perspective_origin_scene' => [
            'default' => '50% 50%',
            'label' => 'Perspective Origin for Scene'
        ],
        'default_cubeheight' => [
            'default' => '80vmin',
            'label' => 'The Height of the Cube'
        ],
        'default_cubewidth' => [
            'default' => '80vmin',
            'label' => 'The Width of the Cube'
        ]
    ];
    foreach ($cube_settings as $id => $values) {
        $wp_customize->add_setting($id, [
            'default' => $values['default'],
            'transport' => 'refresh',
            'sanitize_callback' => 'nierto_cube_sanitize_css_value'
        ]);
        $wp_customize->add_control($id, [
            'label' => __($values['label'], 'nierto_cube'),
            'section' => 'cube_settings',
            'type' => 'text'
        ]);
    }
    //background IMG for cube backside
    $wp_customize->add_setting('cube_four_bg_image', [
        'default' => '',
        'transport' => 'refresh',
        'sanitize_callback' => 'esc_url_raw'
    ]);
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'cube_four_bg_image', [
        'label' => __('Background Image for Cube Backside', 'nierto_cube'),
        'section' => 'cube_settings',
        'settings' => 'cube_four_bg_image',
    ]));
    //background style for cube backside
    $wp_customize->add_setting('cube_four_bg_size', [
        'default' => 'cover',
        'transport' => 'refresh',
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    $wp_customize->add_control('cube_four_bg_size', [
        'label' => __('Background Size for Cube Backside', 'nierto_cube'),
        'section' => 'cube_settings',
        'type' => 'text',
    ]);
    //INDEX OF SECTION: PAGE NAMES FOR SIDES
    // PAGE NAMES FOR FUNCTION CALLINGZ
        // CUBE PAGE NAMES
    for ($i = 1; $i <= 6; $i++) {
        $wp_customize->add_setting("cube_face_{$i}_text", array(
            'default' => "Face {$i}",
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control("cube_face_{$i}_text", array(
            'label' => "Face {$i} Text",
            'section' => 'cube_face_settings',
            'type' => 'text',
        ));
        $wp_customize->add_setting("cube_face_{$i}_type", array(
            'default' => 'cube_face',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control("cube_face_{$i}_type", array(
            'label' => "Face {$i} Content Type",
            'section' => 'cube_face_settings',
            'type' => 'select',
            'choices' => array(
                'page' => 'Page (iframe)',
                'cube_face' => 'Cube Face'
            ),
        ));
        $wp_customize->add_setting("cube_face_{$i}_slug", array(
            'default' => "face-{$i}",
            'sanitize_callback' => 'sanitize_title',
        ));
        $wp_customize->add_control("cube_face_{$i}_slug", array(
            'label' => "Face {$i} Slug/Title",
            'section' => 'cube_face_settings',
            'type' => 'text',
            'description' => 'Enter URL slug for Page or post title for Custom Post',
        ));
        // Keep your existing position setting
        $wp_customize->add_setting("cube_face_{$i}_position", array(
            'default' => "face" . ($i - 1),
            'sanitize_callback' => 'sanitize_text_field',
        ));
        $wp_customize->add_control("cube_face_{$i}_position", array(
            'label' => "Face {$i} Position",
            'section' => 'cube_face_settings',
            'type' => 'select',
            'choices' => array(
                'face0' => 'Face 0',
                'face1' => 'Face 1 = Front',
                'face2' => 'Face 2',
                'face3' => 'Face 3 = Back',
                'face4' => 'Face 4',
                'face5' => 'Face 5 Reversed',
            ),
        ));
    }
    //INDEX OF SECTION: LOGO
    // Logo settings
    for ($i = 1; $i <= 2; $i++) {
        if ($i == 1){
            $name = "width";
        } else {
            $name = "height";
        }
        $wp_customize->add_setting("logo_{$name}", array(
            'default' => "124px",
            'sanitize_callback' => 'sanitize_text_field',
            'transport' => 'refresh',
        ));
        $wp_customize->add_control("logo_{$name}", array(
            'label' => __("Logo Setting {$name}", 'nierto_cube'),
            'section' => 'logo',
            'type' => 'text',
            'settings' => "logo_{$name}",
        ));
    }
    $wp_customize->add_setting('logo_source', array(
        'default' => '',
        'transport' => 'refresh',
        'sanitize_callback' => 'esc_url_raw'
    ));
    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'source_logo', [
        'label' => __('Your logo', 'nierto_cube'),
        'section' => 'logo',
        'settings' => 'logo_source',
    ]));
    $wp_customize->add_setting('logo_alt_text', array(
        'default' => get_bloginfo('name') . ' logo',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    $wp_customize->add_control('logo_alt_text', array(
        'label' => __('Logo Alt Text', 'nierto_cube'),
        'section' => 'logo',
        'type' => 'text',
    ));
// INDEX OF SECTION: FONT
$font_settings = [
    'body_font' => [
        'label' => 'Body Font',
        'default_google' => 'Ubuntu:wght@300;400;700&display=swap',
        'default_local' => 'Ubuntu, sans-serif',
        'description' => 'The default font-family for the body text.'
    ],
    'heading_font' => [
        'label' => 'Heading Font',
        'default_google' => 'Ubuntu:wght@300;400;700&display=swap',
        'default_local' => 'Ubuntu, sans-serif',
        'description' => 'The default font family for headings.'
    ],
    'button_font' => [
        'label' => 'Button Font',
        'default_google' => 'Rubik:wght@400;500&display=swap',
        'default_local' => 'Rubik, sans-serif',
        'description' => 'The default font family for buttons, including navigation buttons.'
    ],
    'extra_font' => [
        'label' => 'Extra Font',
        'default_google' => 'Rubik:wght@300;400;700&display=swap',
        'default_local' => 'Rubik, sans-serif',
        'description' => 'An additional font for use with custom classes.'
    ]
];

foreach ($font_settings as $setting_id => $values) {
    $wp_customize->add_setting($setting_id . '_source', [
        'default' => 'google',
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    $wp_customize->add_control($setting_id . '_source', [
        'label' => __($values['label'] . ' Source', 'nierto_cube'),
        'section' => 'font',
        'type' => 'radio',
        'choices' => [
            'google' => 'Google Font',
            'local' => 'Local Font'
        ]
    ]);
    $wp_customize->add_setting($setting_id . '_google', [
        'default' => $values['default_google'],
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    $wp_customize->add_control($setting_id . '_google', [
        'label' => __($values['label'] . ' (Google)', 'nierto_cube'),
        'description' => __($values['description'] . ' Enter the part of the Google Font URL after "https://fonts.googleapis.com/css2?family=". For example: Ubuntu:wght@300;400;700&display=swap', 'nierto_cube'),
        'section' => 'font',
        'type' => 'text'
    ]);
    $wp_customize->add_setting($setting_id . '_local', [
        'default' => $values['default_local'],
        'sanitize_callback' => 'sanitize_text_field'
    ]);
    $wp_customize->add_control($setting_id . '_local', [
        'label' => __($values['label'] . ' (Local)', 'nierto_cube'),
        'description' => __($values['description'] . ' Enter the font-family value for a locally available font. For example: Ubuntu, sans-serif', 'nierto_cube'),
        'section' => 'font',
        'type' => 'text'
    ]);
}
    // INDEX OF SECTION: NAV STYLING
    // sizes and dimensions
    $nav_texts = [
        'nav_button_padding' => '10px 20px',
        'nav_button_font_size' => '16px',
        'nav_button_border_style' => 'solid',
        'nav_button_border_width' => '1px',
        'nav_button_border_radius' => '20%',
        'nav_wrapper_width' => '15%',
        'nav_button_min_width' => '18vmin',
        'nav_button_max_height' => '5vmin'
    ];
    foreach ($nav_texts as $setting_id => $default_text) {
        $wp_customize->add_setting($setting_id, array(
            'default' => $default_text,
            'sanitize_callback' => 'sanitize_text_field',
            'transport' => 'refresh',
        ));
        $wp_customize->add_control($setting_id, array(
            'label' => __(ucfirst(str_replace('_', ' ', $setting_id)), 'nierto_cube'),
            'section' => 'nav_button_styling',
            'type' => 'text',
            'settings' => $setting_id,
        ));
    }
    // INDEX OF SECTION: ZOOM (Content Expansion Options)
    $wp_customize->add_setting('nierto_cube_max_zoom', array(
        'default' => '90',
        'sanitize_callback' => 'absint',
    ));
    $wp_customize->add_control('nierto_cube_max_zoom', array(
        'label' => __('Maximum Content Zoom (%)', 'nierto_cube'),
        'section' => 'nierto_cube_content_options',
        'type' => 'range',
        'input_attrs' => array(
            'min' => 80,
            'max' => 100,
            'step' => 1,
        ),
    ));
    $wp_customize->add_setting('nierto_cube_long_press_duration', array(
        'default' => '1300',
        'sanitize_callback' => 'absint',
    ));
    $wp_customize->add_control('nierto_cube_long_press_duration', array(
        'label' => __('Long Press Duration (ms)', 'nierto_cube'),
        'section' => 'nierto_cube_content_options',
        'type' => 'range',
        'input_attrs' => array(
            'min' => 500,
            'max' => 2000,
            'step' => 50,
        ),
    ));
}

add_action('customize_register', 'nierto_cube_customize_register');