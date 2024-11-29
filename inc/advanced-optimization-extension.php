<?php
/**
 * Advanced OptimizationManager Extensions
 * 
 * Additional optimization techniques for the NiertoCube theme that address often
 * overlooked performance opportunities.
 * 
 * @package     NiertoCube
 * @subpackage  Modules
 * @since       2.0.0
 */

namespace NiertoCube\Modules;

trait AdvancedOptimizations {
    /**
     * Initialize advanced optimizations
     */
    private function initializeAdvancedOptimizations(): void {
        // DNS Prefetching
        add_action('wp_head', [$this, 'manageDnsPrefetch'], 1);

        // Font Display Optimization
        add_filter('style_loader_tag', [$this, 'optimizeFontLoading'], 10, 4);

        // Image Loading Optimization
        add_filter('the_content', [$this, 'optimizeImageLoading']);
        add_filter('post_thumbnail_html', [$this, 'optimizeImageLoading']);

        // DOM Size Management
        add_action('template_redirect', [$this, 'monitorDomSize']);

        // Memory Usage Optimization
        add_action('init', [$this, 'optimizeMemoryUsage'], 1);

        // Database Query Optimization
        add_action('pre_get_posts', [$this, 'optimizeWpQueries']);

        // Output Buffer Management
        add_action('template_redirect', [$this, 'startOutputBuffer']);
        add_action('shutdown', [$this, 'endOutputBuffer'], 0);

        // HTTP/2 Server Push
        add_action('send_headers', [$this, 'setupHttp2ServerPush']);

        // Media Query Optimization
        add_action('wp_enqueue_scripts', [$this, 'optimizeMediaQueries'], 999);
    }

    /**
     * Manage DNS prefetching
     */
    public function manageDnsPrefetch(): void {
        // Remove WordPress default DNS prefetch
        remove_action('wp_head', 'wp_resource_hints', 2);

        // Add custom DNS prefetch for commonly used domains
        $domains = [
            'https://fonts.googleapis.com',
            'https://fonts.gstatic.com',
            'https://cdnjs.cloudflare.com'
        ];

        foreach ($domains as $domain) {
            printf(
                '<link rel="dns-prefetch" href="%s">' . PHP_EOL,
                esc_url($domain)
            );
        }
    }

    /**
     * Optimize font loading with font-display property
     */
    public function optimizeFontLoading(string $html, string $handle, string $href, string $media): string {
        if (strpos($href, 'fonts.googleapis.com') !== false) {
            $html = str_replace("rel='stylesheet'", 
                "rel='stylesheet' media='print' onload='this.media=\"all\"' font-display='swap'",
                $html
            );
        }
        return $html;
    }

    /**
     * Optimize image loading with modern attributes
     */
    public function optimizeImageLoading(string $content): string {
        // Add loading="lazy" and decoding="async" to images
        $content = preg_replace(
            '/<img(.*?)>/i',
            '<img$1 loading="lazy" decoding="async">',
            $content
        );

        // Add srcset for responsive images
        $content = preg_replace_callback(
            '/<img(.*?)src=[\'"](.*?)[\'"].*?>/i',
            [$this, 'addSrcSet'],
            $content
        );

        return $content;
    }

    /**
     * Add srcset to images
     */
    private function addSrcSet(array $matches): string {
        if (strpos($matches[0], 'srcset') !== false) {
            return $matches[0];
        }

        $src = $matches[2];
        $upload_dir = wp_upload_dir();
        
        if (strpos($src, $upload_dir['baseurl']) === false) {
            return $matches[0];
        }

        $image_id = attachment_url_to_postid($src);
        if (!$image_id) {
            return $matches[0];
        }

        $srcset = wp_get_attachment_image_srcset($image_id);
        if (!$srcset) {
            return $matches[0];
        }

        return str_replace(
            '<img',
            '<img srcset="' . esc_attr($srcset) . '"',
            $matches[0]
        );
    }

    /**
     * Monitor and optimize DOM size
     */
    public function monitorDomSize(): void {
        if (!$this->config['debug']) {
            return;
        }

        add_action('wp_footer', function() {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const domSize = document.getElementsByTagName('*').length;
                if (domSize > 1500) {
                    console.warn(`Large DOM size detected: ${domSize} elements`);
                }
                
                const domDepth = (function getMaxDepth(element, depth = 0) {
                    if (!element.children.length) {
                        return depth;
                    }
                    return Math.max(...Array.from(element.children)
                        .map(child => getMaxDepth(child, depth + 1)));
                })(document.body);

                if (domDepth > 20) {
                    console.warn(`Deep DOM nesting detected: ${domDepth} levels`);
                }
            });
            </script>
            <?php
        });
    }

    /**
     * Optimize memory usage
     */
    public function optimizeMemoryUsage(): void {
        // Disable post revisions accumulation
        if (!defined('WP_POST_REVISIONS')) {
            define('WP_POST_REVISIONS', 5);
        }

        // Clean up auto-drafts
        $deleted = $GLOBALS['wpdb']->query("
            DELETE FROM $GLOBALS[wpdb]->posts 
            WHERE post_status = 'auto-draft' 
            AND DATE_SUB(NOW(), INTERVAL 7 DAY) > post_date
        ");

        if ($deleted && $this->metrics) {
            $this->metrics->recordMetric('auto_drafts_cleaned', $deleted);
        }

        // Optimize transients
        $deleted = $GLOBALS['wpdb']->query("
            DELETE FROM $GLOBALS[wpdb]->options 
            WHERE option_name LIKE '\_transient\_%' 
            AND option_value < UNIX_TIMESTAMP()
        ");

        if ($deleted && $this->metrics) {
            $this->metrics->recordMetric('expired_transients_cleaned', $deleted);
        }
    }

    /**
     * Optimize WordPress queries
     */
    public function optimizeWpQueries(\WP_Query $query): void {
        if ($query->is_main_query() && !is_admin()) {
            // Disable meta queries when not needed
            $query->set('update_post_meta_cache', false);
            $query->set('update_post_term_cache', false);

            // Optimize author queries
            if ($query->is_author()) {
                $query->set('post_type', ['post', 'cube_face']);
                $query->set('posts_per_page', 20);
                $query->set('no_found_rows', true);
            }

            // Optimize archive queries
            if ($query->is_archive()) {
                $query->set('no_found_rows', true);
            }
        }
    }

    /**
     * Start output buffering for HTML optimization
     */
    public function startOutputBuffer(): void {
        ob_start([$this, 'optimizeHtmlOutput']);
    }

    /**
     * End output buffering
     */
    public function endOutputBuffer(): void {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    /**
     * Optimize HTML output
     */
    public function optimizeHtmlOutput(string $buffer): string {
        if (!$this->config['enabled'] || is_admin()) {
            return $buffer;
        }

        // Remove HTML comments (except IE conditionals)
        $buffer = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $buffer);

        // Remove whitespace
        $buffer = preg_replace('/\s+/u', ' ', $buffer);
        $buffer = preg_replace('/>\s+</u', '><', $buffer);

        // Remove query strings from static resources
        $buffer = preg_replace('/\?ver=[^"\']+(["\'])/i', '$1', $buffer);

        return $buffer;
    }

    /**
     * Setup HTTP/2 Server Push
     */
    public function setupHttp2ServerPush(): void {
        if (!$this->config['enabled']) {
            return;
        }

        $critical_resources = [
            get_template_directory_uri() . '/css/critical.css' => 'style',
            get_template_directory_uri() . '/js/essential.js' => 'script'
        ];

        foreach ($critical_resources as $uri => $type) {
            header(
                sprintf(
                    'Link: <%s>; rel=preload; as=%s',
                    esc_url($uri),
                    esc_attr($type)
                ),
                false
            );
        }
    }

    /**
     * Optimize media queries in stylesheets
     */
    public function optimizeMediaQueries(): void {
        global $wp_styles;

        if (!$this->config['enabled'] || !is_object($wp_styles)) {
            return;
        }

        foreach ($wp_styles->registered as $handle => $style) {
            // Skip core WordPress styles
            if (strpos($handle, 'wp-') === 0) {
                continue;
            }

            // Optimize print stylesheets
            if (isset($style->extra['media']) && $style->extra['media'] === 'print') {
                $wp_styles->add_data($handle, 'media', 'print');
                $wp_styles->add_data($handle, 'onload', "this.media='all'");
            }

            // Add media loading optimization
            if (!isset($style->extra['media'])) {
                $wp_styles->add_data($handle, 'media', 'print');
                $wp_styles->add_data($handle, 'onload', "this.media='all'");
            }
        }
    }
}