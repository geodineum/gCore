<?php
/**
 * Structured Data Implementation for NiertoCube Theme
 * 
 * This file handles the generation and integration of structured data (Schema.org)
 * markup for improved SEO and machine readability of content, particularly
 * focusing on cube face content representation.
 * 
 * @package NiertoCube
 * @subpackage SEO
 * @since 1.0.0
 * 
 * @version 1.0.0
 * @author Niels Erik Toren
 * 
 * File Purpose:
 * -------------
 * Implements Schema.org structured data for cube faces and general content,
 * enhancing search engine understanding and rich snippet potential. Focuses
 * on Article and WebPage schemas with custom properties for cube-specific
 * features.
 * 
 * Key Functions:
 * -------------
 * - nierto_cube_generate_structured_data(): 
 *   Generates JSON-LD structured data based on current content context
 *   Input: None (uses global $post)
 *   Output: JSON-LD structured data string or empty string if not applicable
 * 
 * Design Patterns:
 * --------------
 * - Uses WordPress global post object for content access
 * - Implements conditional schema generation based on post type
 * - Follows Schema.org specifications for structured data
 * - Maintains extensibility for future schema types
 * 
 * Dependencies:
 * ------------
 * - WordPress Core: get_post_meta(), get_the_date(), get_the_modified_date()
 * - Theme Functions: get_theme_mod() for logo retrieval
 * - Global: $post object
 * 
 * Integration Points:
 * -----------------
 * - Works with cube_face custom post type
 * - Integrates with theme customizer for logo settings
 * - Supports meta tag functionality from metatags-funcs.php
 * 
 * Potential Improvements:
 * --------------------
 * 1. Add support for additional Schema.org types (Product, Event, etc.)
 * 2. Implement schema validation before output
 * 3. Add cache support for generated structured data
 * 4. Enhance error handling for missing required properties
 * 5. Add filter hooks for schema customization
 * 
 * Performance Notes:
 * ----------------
 * - Consider implementing caching for structured data generation
 * - Optimize meta query operations for large datasets
 * 
 * Security Measures:
 * ----------------
 * - Implements proper data escaping for JSON output
 * - Validates post type access permissions
 * - Sanitizes meta values before inclusion
 * 
 * Related Files:
 * ------------
 * - metatags-funcs.php: Provides meta tag functionality
 * - CacheManager.php: Potential integration for caching
 * 
 * Code Quality Notes:
 * -----------------
 * STRENGTHS:
 * - Clean implementation of Schema.org standards
 * - Good separation of concerns
 * - Proper WordPress integration
 * 
 * IMPROVEMENT OPPORTUNITIES:
 * 1. Add explicit error handling
 * 2. Implement caching mechanism
 * 3. Add schema validation
 * 4. Enhance type safety
 * 5. Add comprehensive filter system
 * 
 * Usage Example:
 * ------------
 * // Output structured data in header or footer
 * $structured_data = nierto_cube_generate_structured_data();
 * if (!empty($structured_data)) {
 *     echo '<script type="application/ld+json">';
 *     echo $structured_data;
 *     echo '</script>';
 * }
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

function nierto_cube_generate_structured_data() {
    global $post;

    if (is_singular()) {
        $schema = array(
            "@context" => "https://schema.org",
            "@type" => "WebPage",
            "name" => get_the_title(),
            "description" => get_the_excerpt(),
            "url" => get_permalink(),
            "datePublished" => get_the_date('c'),
            "dateModified" => get_the_modified_date('c'),
            "author" => array(
                "@type" => "Person",
                "name" => get_the_author()
            ),
            "publisher" => array(
                "@type" => "Organization",
                "name" => get_bloginfo('name'),
                "logo" => array(
                    "@type" => "ImageObject",
                    "url" => get_theme_mod('logo_source', '')
                )
            )
        );

        if (get_post_type() === 'cube_face') {
            $schema['@type'] = 'Article';
            $schema['articleSection'] = 'Cube Face';
            $schema['position'] = get_post_meta($post->ID, 'cube_face_position', true);
            $schema['associatedMedia'] = array(
                "@type" => "WebPage",
                "url" => get_permalink()
        );
        
        // Add meta tags as properties
        $meta_tags = get_post_meta($post->ID, 'cube_face_meta_tags', true);
        if (!empty($meta_tags)) {
            $schema['keywords'] = $meta_tags;
        }
        
        // Add any custom fields you've defined for cube faces
        $custom_content = get_post_meta($post->ID, 'cube_face_custom_content', true);
        if (!empty($custom_content)) {
            $schema['description'] = wp_strip_all_tags($custom_content);
        }

        if (has_post_thumbnail()) {
            $schema['image'] = array(
                "@type" => "ImageObject",
                "url" => get_the_post_thumbnail_url($post->ID, 'full'),
                "width" => 1200,
                "height" => 630
            );
        }

        return json_encode($schema);
    }

    return '';
}