<?php
/**
 * NiertoCube Multi-Post Functions
 * -----------------------------------------
 * 
 * This file manages the multi-post functionality for the NiertoCube theme, specifically
 * handling the enforcement of single settings template instances across cube faces.
 * 
 * @package     NiertoCube
 * @subpackage  Core
 * @version     1.0.0
 * @since       1.0.0
 * 
 * FUNCTIONALITY
 * -------------
 * - Ensures only one cube face can use the settings template at a time
 * - Manages template conflicts by automatically converting duplicates to drafts
 * - Provides admin notifications for template conflicts
 * 
 * KEY FUNCTIONS
 * ------------
 * enforce_single_settings_template($post_id)
 *     Ensures uniqueness of settings template across cube faces
 *     @param  int    $post_id    The ID of the post being saved
 *     @return void
 * 
 * settings_template_error_notice()
 *     Displays admin notice when template conflicts occur
 *     @return void
 * 
 * HOOKS & FILTERS
 * --------------
 * Actions:
 * - save_post_cube_face
 * - admin_notices
 * 
 * DEPENDENCIES
 * -----------
 * WordPress Core:
 * - get_posts()
 * - wp_update_post()
 * - add_action()
 * - add_filter()
 * 
 * Theme Dependencies:
 * - Requires 'cube_face' custom post type
 * - Interacts with cube face template system
 * 
 * ARCHITECTURAL NOTES
 * ------------------
 * - Implements singleton pattern for settings template
 * - Uses WordPress post status management for conflict resolution
 * - Leverages admin notices for user feedback
 * - Maintains data integrity through post status control
 * 
 * POTENTIAL IMPROVEMENTS
 * --------------------
 * 1. Consider implementing transient caching for template queries
 * 2. Add user capability checks before template enforcement
 * 3. Implement logging for template conflict resolution
 * 4. Consider adding settings template migration tools
 * 5. Add recovery mechanism for accidental template conversions
 * 
 * CODE QUALITY NOTES
 * ----------------
 * Strengths:
 * + Clean implementation of template uniqueness
 * + Proper use of WordPress hooks
 * + Clear error messaging
 * 
 * Areas for Review:
 * ! Consider adding nonce verification for template changes
 * ! May benefit from additional error handling
 * ! Query optimization for large post counts needed
 * 
 * USAGE EXAMPLE
 * ------------
 * // Automatically triggered when saving cube face posts
 * // No manual function calls required
 * // Template status is enforced through WordPress hooks
 * 
 * @author     Niels Erik Toren
 * @copyright  2024 NiertoCube
 * @license    See project license file
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
function enforce_single_settings_template($post_id) {
    if (get_post_type($post_id) === 'cube_face' && get_post_meta($post_id, '_cube_face_template', true) === 'settings') {
        $existing_settings = get_posts([
            'post_type' => 'cube_face',
            'meta_key' => '_cube_face_template',
            'meta_value' => 'settings',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'exclude' => [$post_id],
        ]);

        if (!empty($existing_settings)) {
            remove_action('save_post_cube_face', 'enforce_single_settings_template');
            wp_update_post([
                'ID' => $post_id,
                'post_status' => 'draft',
            ]);
            add_action('save_post_cube_face', 'enforce_single_settings_template');
            add_filter('redirect_post_location', function ($location) {
                return add_query_arg('settings_template_error', 1, $location);
            });
        }
    }
}
add_action('save_post_cube_face', 'enforce_single_settings_template');

function settings_template_error_notice() {
    if (isset($_GET['settings_template_error'])) {
        echo '<div class="error"><p>Only one Settings template can be published at a time. This post has been saved as a draft.</p></div>';
    }
}
add_action('admin_notices', 'settings_template_error_notice');