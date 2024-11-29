<?php
/**
 * NiertoCube Meta Tags Management System
 * 
 * Handles SEO metadata for cube faces, including title and description management.
 * This file provides functionality for adding, storing, and rendering meta tags
 * specifically for the cube_face custom post type.
 *
 * @package NiertoCube
 * @subpackage SEO
 * @since 1.0.0
 * 
 * @architectural-overview
 * Implements WordPress meta box API for SEO data management within the cube face
 * editor interface. Uses WordPress post meta API for data storage and retrieval.
 * Follows WordPress coding standards for meta data handling and sanitization.
 *
 * @dependencies
 * - WordPress Core: add_meta_box(), wp_nonce_field(), update_post_meta(),
 *                  get_post_meta(), wp_verify_nonce()
 * - Custom Post Type: 'cube_face'
 * - Hooks: add_meta_boxes, save_post, wp_head
 *
 * @key-functions
 * nierto_cube_add_seo_meta_box()     - Registers SEO meta box in admin interface
 * nierto_cube_seo_meta_box_callback() - Renders meta box interface
 * nierto_cube_save_seo_meta_box_data()- Handles meta data saving
 * nierto_cube_add_meta_tags()         - Outputs meta tags in frontend
 *
 * @data-structure
 * Meta Keys:
 * - _nierto_cube_meta_title       - SEO title for cube face
 * - _nierto_cube_meta_description - SEO description for cube face
 *
 * @usage
 * Meta tags are automatically added to wp_head for cube_face post types when
 * metadata is present. No manual function calls required after initial setup.
 *
 * @security-measures
 * - Nonce verification for form submissions
 * - Capability checking for post editing
 * - Data sanitization using sanitize_text_field()
 * - Proper escaping for output using esc_attr()
 *
 * @performance-considerations
 * - Minimal database queries using get_post_meta()
 * - Efficient hook usage with specific post type targeting
 * - No unnecessary meta tag output for non-cube-face content
 *
 * @code-standards
 * - Follows WordPress PHP Documentation Standards
 * - Implements WordPress Security Best Practices
 * - Uses WordPress Coding Standards for naming conventions
 *
 * @potential-improvements
 * 1. Add support for additional meta tags (og:tags, twitter cards)
 * 2. Implement meta tag preview in admin interface
 * 3. Add character count validation for meta descriptions
 * 4. Consider adding schema.org markup integration
 * 5. Add bulk editing capability for meta tags
 *
 * @known-issues
 * None currently identified.
 *
 * @backwards-compatibility
 * Maintains compatibility with WordPress 5.0+
 * No breaking changes introduced since implementation
 *
 * @future-considerations
 * - Consider integration with popular SEO plugins
 * - Plan for structured data expansion
 * - Evaluate need for meta tag validation system
 *
 * @changelog
 * 1.0.0 - Initial implementation
 *       - Basic meta title and description support
 *       - WordPress integration complete
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function nierto_cube_add_seo_meta_box() {
    add_meta_box(
        'nierto_cube_seo_meta_box',
        'SEO Settings',
        'nierto_cube_seo_meta_box_callback',
        'cube_face',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'nierto_cube_add_seo_meta_box');

function nierto_cube_seo_meta_box_callback($post) {
    wp_nonce_field('nierto_cube_save_seo_meta_box_data', 'nierto_cube_seo_meta_box_nonce');

    $meta_title = get_post_meta($post->ID, '_nierto_cube_meta_title', true);
    $meta_description = get_post_meta($post->ID, '_nierto_cube_meta_description', true);

    echo '<p><label for="nierto_cube_meta_title">Meta Title</label><br>';
    echo '<input type="text" id="nierto_cube_meta_title" name="nierto_cube_meta_title" value="' . esc_attr($meta_title) . '" size="50"></p>';

    echo '<p><label for="nierto_cube_meta_description">Meta Description</label><br>';
    echo '<textarea id="nierto_cube_meta_description" name="nierto_cube_meta_description" rows="4" cols="50">' . esc_textarea($meta_description) . '</textarea></p>';
}

function nierto_cube_save_seo_meta_box_data($post_id) {
    if (!isset($_POST['nierto_cube_seo_meta_box_nonce'])) {
        return;
    }
    if (!wp_verify_nonce($_POST['nierto_cube_seo_meta_box_nonce'], 'nierto_cube_save_seo_meta_box_data')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['nierto_cube_meta_title'])) {
        update_post_meta($post_id, '_nierto_cube_meta_title', sanitize_text_field($_POST['nierto_cube_meta_title']));
    }
    if (isset($_POST['nierto_cube_meta_description'])) {
        update_post_meta($post_id, '_nierto_cube_meta_description', sanitize_textarea_field($_POST['nierto_cube_meta_description']));
    }
}
add_action('save_post', 'nierto_cube_save_seo_meta_box_data');

function nierto_cube_add_meta_tags() {
    if (is_singular('cube_face')) {
        $post_id = get_the_ID();
        $meta_title = get_post_meta($post_id, '_nierto_cube_meta_title', true);
        $meta_description = get_post_meta($post_id, '_nierto_cube_meta_description', true);

        if (!empty($meta_title)) {
            echo '<meta name="title" content="' . esc_attr($meta_title) . '">' . "\n";
        }
        if (!empty($meta_description)) {
            echo '<meta name="description" content="' . esc_attr($meta_description) . '">' . "\n";
        }
    }
}
add_action('wp_head', 'nierto_cube_add_meta_tags');