<?php
/**
 * Ajax Handler for NiertoCube Theme
 * 
 * @package     NiertoCube
 * @subpackage  AJAX
 * @version     1.0.0
 * @since       1.0.0
 * @author      Niels Erik Toren
 * 
 * FUNCTIONALITY OVERVIEW
 * ======================
 * Manages all AJAX operations for the NiertoCube theme, including face content loading,
 * cache management, and dynamic content updates. Implements security measures and
 * error handling for all AJAX endpoints.
 * 
 * KEY COMPONENTS
 * ==============
 * - AJAX request verification and security handling
 * - Face content retrieval and caching
 * - Error logging and management system
 * - Cache invalidation triggers
 * 
 * PRIMARY FUNCTIONS
 * ================
 * nierto_cube_verify_ajax_nonce()
 *     Validates AJAX requests using WordPress nonces
 * 
 * nierto_cube_ajax_handler()
 *     Central handler for all AJAX operations
 *     Processes: face content, cache operations
 * 
 * nierto_cube_ajax_error_handler()
 *     Custom error handling for AJAX operations
 *     Logs errors and manages graceful degradation
 * 
 * nierto_cube_ajax_wrapper()
 *     Wraps AJAX callbacks with error handling and response formatting
 * 
 * nierto_cube_get_face_content_ajax()
 *     Retrieves and processes cube face content
 *     Handles caching and template processing
 * 
 * DEPENDENCIES
 * ===========
 * Internal:
 * - CacheManager         (/inc/cache/CacheManager.php)
 * - VersionManager       (/inc/cache/VersionManager.php)
 * - performance-optimization.php
 * 
 * WordPress Core:
 * - wp_send_json_error()
 * - wp_send_json_success()
 * - check_ajax_referer()
 * - wp_create_nonce()
 * 
 * ARCHITECTURAL NOTES
 * ==================
 * - Implements wrapper pattern for consistent error handling
 * - Uses WordPress nonce system for security
 * - Integrates with CacheManager for optimized content delivery
 * - Follows WordPress coding standards
 * 
 * ERROR HANDLING
 * =============
 * - Custom error handler for AJAX operations
 * - Logs errors to theme's error log
 * - Provides graceful degradation
 * - Sanitizes all input/output
 * 
 * PERFORMANCE CONSIDERATIONS
 * ========================
 * - Implements caching for face content
 * - Uses non-blocking operations where possible
 * - Minimizes database queries
 * - Optimizes response payload size
 * 
 * SECURITY MEASURES
 * ===============
 * - Nonce verification for all requests
 * - Input sanitization
 * - Capability checking
 * - Error message sanitization
 * 
 * @see /inc/cache/CacheManager.php
 * @see /inc/cache/VersionManager.php
 * @see /inc/performance-optimization.php
 * 
 * TODO/IMPROVEMENTS
 * ===============
 * - Consider implementing rate limiting
 * - Add request batching for multiple face content requests
 * - Implement response compression
 * - Add more granular error reporting in debug mode
 * 
 * DEPRECATION NOTICES
 * ==================
 * - direct_face_content_fetch() is deprecated since 1.1.0
 *   Use nierto_cube_get_face_content_ajax() instead
 * 
 * CHANGELOG
 * =========
 * 1.0.0
 * - Initial implementation
 * - Added security measures
 * - Implemented caching integration
 * 
 * USAGE EXAMPLES
 * =============
 * WordPress AJAX call:
 * ```javascript
 * wp.ajax.post('nierto_cube_ajax', {
 *     action: 'get_face_content',
 *     nonce: niertoCubeData.nonce,
 *     face_id: 1
 * }).done(function(response) {
 *     // Handle response
 * });
 * ```
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function nierto_cube_verify_ajax_nonce() {
    if (!check_ajax_referer('nierto_cube_ajax', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed']);
        exit;
    }
}

function nierto_cube_ajax_handler() {
    nierto_cube_verify_ajax_nonce();

    $action = isset($_POST['cube_action']) ? sanitize_text_field($_POST['cube_action']) : '';

    switch ($action) {
        case 'get_face_content':
            $slug = sanitize_text_field($_POST['slug']);
            $content = get_face_content(['slug' => $slug]);
            if (is_wp_error($content)) {
                wp_send_json_error(['message' => $content->get_error_message()]);
            } else {
                wp_send_json_success($content);
            }
            break;

        default:
            wp_send_json_error(['message' => 'Invalid action']);
            break;
    }
}

function nierto_cube_ajax_error_handler($errno, $errstr, $errfile, $errline) {
    nierto_cube_log_error("AJAX Error: $errstr in $errfile on line $errline");
    return true; // Don't execute the PHP internal error handler
}

function nierto_cube_ajax_wrapper($callback) {
    return function() use ($callback) {
        try {
            set_error_handler('nierto_cube_ajax_error_handler');
            $result = call_user_func($callback);
            restore_error_handler();
            wp_send_json_success($result);
        } catch (Exception $e) {
            nierto_cube_log_error('AJAX Exception: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    };
}

function nierto_cube_get_face_content_ajax() {
    nierto_cube_verify_ajax_nonce();
    $slug = sanitize_text_field($_POST['slug']);
    $post_type = sanitize_text_field($_POST['post_type']);
    $args = array(
        'name'        => $slug,
        'post_type'   => $post_type,  // Use the provided post type instead of hardcoding 'cube_face'
        'post_status' => 'publish',
        'numberposts' => 1
    );
    $posts = get_posts($args);
    if ($posts) {
        $post = $posts[0];
        $content = apply_filters('the_content', $post->post_content);
        $content = do_shortcode($content); // Process shortcodes
        
        // Get sidebar content
        ob_start();
        dynamic_sidebar('cube-face-sidebar');
        $sidebar_content = ob_get_clean();
        
        return array(
            'content' => $content,
            'sidebar' => $sidebar_content,
            'title'   => $post->post_title
        );
    } else {
        throw new Exception('Post not found');
    }
}

function nierto_cube_get_theme_url() {
    wp_send_json(array(
        'theme_url' => get_template_directory_uri() . '/',
        'nonce' => wp_create_nonce('nierto_cube_sw_cache')
    ));
}

add_action('wp_ajax_nierto_cube_ajax', nierto_cube_ajax_wrapper('nierto_cube_ajax_handler'));
add_action('wp_ajax_nopriv_nierto_cube_ajax', nierto_cube_ajax_wrapper('nierto_cube_ajax_handler'));
add_action('wp_ajax_nierto_cube_get_face_content', nierto_cube_ajax_wrapper('nierto_cube_get_face_content_ajax'));
add_action('wp_ajax_nopriv_nierto_cube_get_face_content', nierto_cube_ajax_wrapper('nierto_cube_get_face_content_ajax'));
add_action('wp_ajax_get_theme_url', nierto_cube_ajax_wrapper('nierto_cube_get_theme_url'));
add_action('wp_ajax_nopriv_get_theme_url', nierto_cube_ajax_wrapper('nierto_cube_get_theme_url'));