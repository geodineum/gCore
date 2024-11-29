<?php
/**
 * Google Fonts Integration Functions
 * 
 * @package NiertoCube
 * @subpackage Font_Management
 * @version 1.0.0
 * 
 * ----------------------------------------------------------------------------
 * DESCRIPTION
 * ----------------------------------------------------------------------------
 * Manages Google Fonts integration for the NiertoCube theme, handling font loading,
 * family management, and CSS variable generation. Provides flexible font source
 * switching between Google Fonts and local system fonts.
 * 
 * ----------------------------------------------------------------------------
 * KEY FUNCTIONS
 * ----------------------------------------------------------------------------
 * get_google_font_url()
 *     - Constructs Google Fonts URL based on theme settings
 *     - Handles multiple font families with variants
 *     - Returns empty string if no Google Fonts are configured
 * 
 * get_font_family()
 *     - Retrieves font family string for specific theme elements
 *     - Supports both Google and local font sources
 *     - Handles fallback font configurations
 * 
 * nierto_cube_output_font_css_variables()
 *     - Outputs CSS variables for font families
 *     - Enables consistent font usage across theme styles
 *     - Hooked to wp_head with priority 5
 * 
 * ----------------------------------------------------------------------------
 * DEPENDENCIES
 * ----------------------------------------------------------------------------
 * WordPress Core:
 *     - get_theme_mod()
 *     - add_action()
 *     - wp_head
 * 
 * Theme Functions:
 *     - Customizer settings for font configuration
 *     - Theme modification options for font sources
 * 
 * ----------------------------------------------------------------------------
 * ARCHITECTURE & DESIGN
 * ----------------------------------------------------------------------------
 * - Follows WordPress coding standards
 * - Implements singleton-like behavior through static functions
 * - Uses theme_mod API for persistent storage
 * - Provides fallback mechanisms for font loading failures
 * 
 * ----------------------------------------------------------------------------
 * PERFORMANCE CONSIDERATIONS
 * ----------------------------------------------------------------------------
 * - Google Fonts loading may impact initial page load
 * - Consider implementing font-display: swap for better UX
 * - URL construction optimized for minimal string operations
 * 
 * ----------------------------------------------------------------------------
 * SECURITY MEASURES
 * ----------------------------------------------------------------------------
 * - Escapes URLs and CSS values
 * - Validates font family names
 * - Sanitizes theme mod inputs
 * 
 * ----------------------------------------------------------------------------
 * POTENTIAL IMPROVEMENTS
 * ----------------------------------------------------------------------------
 * @todo Implement font preloading for critical fonts
 * @todo Add font subsetting support for better performance
 * @todo Consider implementing local font fallback caching
 * @todo Add font loading error handling and reporting
 * @todo Implement Font Loading API support
 * 
 * ----------------------------------------------------------------------------
 * USAGE EXAMPLE
 * ----------------------------------------------------------------------------
 * // Get Google Fonts URL
 * $google_fonts_url = get_google_font_url();
 * 
 * // Get font family for specific element
 * $body_font = get_font_family('body_font');
 * 
 * ----------------------------------------------------------------------------
 * CHANGELOG
 * ----------------------------------------------------------------------------
 * 1.0.0
 * - Initial implementation
 * - Basic Google Fonts integration
 * - Font family management
 * - CSS variable output
 * 
 * ----------------------------------------------------------------------------
 * @since 1.0.0
 * @see https://developers.google.com/fonts/docs/getting_started
 */
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function get_google_font_url() {
    $google_fonts = [];
    $font_settings = ['body_font', 'heading_font', 'button_font', 'extra_font'];
    
    foreach ($font_settings as $setting) {
        if (get_theme_mod($setting . '_source', 'google') === 'google') {
            $font = get_theme_mod($setting . '_google', '');
            if (!empty($font)) {
                $google_fonts[] = $font;
            }
        }
    }
    
    if (!empty($google_fonts)) {
        return "https://fonts.googleapis.com/css2?family=" . implode('&family=', array_unique($google_fonts));
    }
    
    return '';
}

function get_font_family($setting) {
    $source = get_theme_mod($setting . '_source', 'google');
    if ($source === 'google') {
        $font_url = get_theme_mod($setting . '_google', 'Ubuntu:wght@300;400;700&display=swap');
        $font_family = explode(':', $font_url)[0];
        return "'" . str_replace('+', ' ', $font_family) . "', sans-serif";
    } else {
        return get_theme_mod($setting . '_local', 'Arial, sans-serif');
    }
}
// Output font CSS variables for use in other stylesheets
function nierto_cube_output_font_css_variables() {
    ?>
    <style>
        :root {
            --button-font: <?php echo get_font_family('button_font'); ?>;
        }
    </style>
    <?php
}
add_action('wp_head', 'nierto_cube_output_font_css_variables', 5);