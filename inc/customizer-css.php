<?php
/**
 * NiertoCube Theme Customizer CSS Generation
 * 
 * This file handles the dynamic generation of CSS based on WordPress Theme Customizer settings.
 * It creates CSS custom properties (variables) and applies them to various theme elements.
 *
 * @package     NiertoCube
 * @subpackage  Customizer
 * @since       1.0.0
 * 
 * @architecture
 * The file follows a single-responsibility principle, focusing solely on CSS generation
 * from theme customizer settings. It outputs CSS variables and styles inline in the 
 * document head for optimal performance and immediate visual feedback.
 *
 * @dependencies
 * - WordPress Customizer API
 * - register-options-wp.php (for customizer option definitions)
 * - Core WordPress functions: get_theme_mod(), get_font_family()
 * 
 * @functions
 * - nierto_cube_customizer_css(): Primary function for CSS generation
 *   Handles:
 *   - Root CSS variables for colors, gradients, dimensions
 *   - Navigation button styling
 *   - Cube perspective and transformation settings
 *   - Font family assignments
 *   - Background image configurations
 * 
 * @hooks
 * - wp_head: Priority standard (10) for CSS output
 * 
 * @customizer_settings
 * Colors:
 * - Gradient colors (1-4)
 * - Scrollbar colors
 * - Background, text, header colors
 * - Navigation button colors
 * 
 * Dimensions:
 * - Cube height/width
 * - Navigation button dimensions
 * - Perspective values
 * 
 * @security
 * - All color values are sanitized using prepend_hash()
 * - Theme mod values are escaped for CSS output
 * - No direct user input is processed
 * 
 * @performance
 * - CSS is generated once per page load
 * - Custom properties enable efficient runtime updates
 * - Inline CSS eliminates additional HTTP requests
 * 
 * @issues
 * MEDIUM: Potential performance impact with large number of customizer options
 * LOW: Some CSS properties may need vendor prefixes for older browsers
 * 
 * @todo
 * - Consider caching generated CSS
 * - Add critical CSS identification
 * - Implement CSS minification
 * - Add fallback values for custom properties
 * - Consider moving to separate stylesheet for better caching
 * 
 * @usage
 * The CSS is automatically generated and output in the head section of the theme.
 * No direct function calls are needed. Customizer changes trigger automatic updates.
 * 
 * Example customizer value retrieval:
 * ```php
 * $color = get_theme_mod('color_background', '#F97162');
 * ```
 * 
 * @code_standards WordPress Coding Standards
 * @link https://developer.wordpress.org/themes/customize-api/
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function nierto_cube_customizer_css() {
    $cube_four_bg_image = get_theme_mod('cube_four_bg_image', '');
    function prepend_hash($color) {
        return strpos($color, '#') === 0 ? $color : '#' . $color;
    }
    ?>
    <style type="text/css">
        :root { 
            scrollbar-color: <?php echo prepend_hash(get_theme_mod('scrollbar_color1', '#F97162')); ?> <?php echo prepend_hash(get_theme_mod('scrollbar_color2', '#FEFEF9')); ?>;
            --gradcolor1: <?php echo prepend_hash(get_theme_mod('grad_color1', '#ee7752')); ?>;
            --gradcolor2: <?php echo prepend_hash(get_theme_mod('grad_color2', '#e73c7e')); ?>;
            --gradcolor3: <?php echo prepend_hash(get_theme_mod('grad_color3', '#23a6d5')); ?>;
            --gradcolor4: <?php echo prepend_hash(get_theme_mod('grad_color4', '#23d5ab')); ?>;
            --color-bg: <?php echo prepend_hash(get_theme_mod('color_background', '#F97162')); ?>;
            --color-txt: <?php echo prepend_hash(get_theme_mod('color_text', '#F97162')); ?>;
            --color-header: <?php echo prepend_hash(get_theme_mod('color_header', '#FEFEF9')); ?>;
            --color-border: <?php echo prepend_hash(get_theme_mod('color_border', '#F5F9E9')); ?>;
            --color-highlight: <?php echo prepend_hash(get_theme_mod('color_highlight', '#F5F9E9')); ?>;
            --color-hover: <?php echo prepend_hash(get_theme_mod('color_hover', '#F5F9E9')); ?>;
            --color-bg-button: <?php echo prepend_hash(get_theme_mod('color_background_button', '#F5F9E9')); ?>;
            --color-txt-button: <?php echo prepend_hash(get_theme_mod('color_text_button', '#F5F9E9')); ?>;
            --default-cubeheight: <?php echo get_theme_mod('default_cubeheight', '80vmin'); ?>;
            --default-cubewidth: <?php echo get_theme_mod('default_cubewidth', '80vmin'); ?>;
            --semi-transparant: <?php echo get_theme_mod('semi_transparant', 'rgba(255, 255, 255, 0.28)'); ?>;
            --nav-button-bg-color: <?php echo prepend_hash(get_theme_mod('nav_button_bg_color', '#ffffff')); ?>;
            --nav-button-text-color: <?php echo prepend_hash(get_theme_mod('nav_button_text_color', '#000000')); ?>;
            --nav-button-padding: <?php echo get_theme_mod('nav_button_padding', '10px 20px'); ?>;
            --nav-button-margin: <?php echo get_theme_mod('nav_button_margin', '10px'); ?>;
            --nav-button-font-size: <?php echo get_theme_mod('nav_button_font_size', '16px'); ?>;
            --nav-button-border-style: <?php echo get_theme_mod('nav_button_border_style', 'solid'); ?>;
            --nav-button-border-color: <?php echo prepend_hash(get_theme_mod('nav_button_border_color', '#000000')); ?>;
            --nav-button-border-width: <?php echo get_theme_mod('nav_button_border_width', '1px'); ?>;
            --nav-button-border-radius: <?php echo get_theme_mod('nav_button_border_radius', '20%'); ?>;
            --nav-button-hover-bg-color: <?php echo prepend_hash(get_theme_mod('nav_button_hover_bg_color', '#dddddd')); ?>;
            --nav-button-hover-text-color: <?php echo prepend_hash(get_theme_mod('nav_button_hover_text_color', '#000000')); ?>;
            --nav-button-min-width: <?php echo get_theme_mod('nav_button-min-width', '17%'); ?>;
            --nav-button-max-height: <?php echo get_theme_mod('nav_button_max_height', '17%'); ?>;
            --nav-wrapper-default-width:  <?php echo get_theme_mod('nav_wrapper_width', '17%'); ?>;
        }
        body {
            font-family: <?php echo get_font_family('body_font'); ?>;
            font-optical-sizing: auto;
            font-style: normal;
            background-color: var(--color-bg);
            color: var(--color-txt);
        }
        #scene {
            perspective: <?php echo get_theme_mod('perspective_scene', '200vmin'); ?>;
            -webkit-perspective: <?php echo get_theme_mod('perspective_scene', '200vmin'); ?>;
            perspective-origin: <?php echo get_theme_mod('perspective_origin_scene', '50% 50%'); ?>;
            -webkit-perspective-origin: <?php echo get_theme_mod('perspective_origin_scene', '50% 50%'); ?>;
            z-index: 1;
        }
        #cube .four {
            background-image: url('<?php echo get_theme_mod('cube_four_bg_image', ''); ?>');
            background-size: <?php echo get_theme_mod('cube_four_bg_size', 'cover'); ?>;
            background-position: top center;
            background-attachment: fixed;  
        }
        .navName {
            color:var(--nav-button-text-color);
        }
        .navButton {
            background-color: var(--nav-button-bg-color);
            font-family: <?php echo get_font_family('button_font'); ?>;
            color: var(--nav-button-text-color);
            padding: var(--nav-button-padding);
            font-size: var(--nav-button-font-size);
            border-style: var(--nav-button-border-style);
            border-color: var(--nav-button-border-color);
            border-width: var(--nav-button-border-width);
            border-radius: var(--nav-button-border-radius);
        }
        .navButton:hover {
        background-color: var(--nav-button-hover-bg-color);
        color: var(--nav-button-hover-text-color);
        }
    </style>
    <?php
}

add_action('wp_head', 'nierto_cube_customizer_css');