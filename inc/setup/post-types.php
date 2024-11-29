<?php
/**
 * NiertoCube Post Types Management
 * 
 * Manages registration and configuration of custom post types,
 * particularly the cube_face post type and its associated metadata.
 * 
 * @package     NiertoCube
 * @subpackage  PostTypes
 * @version     2.0.0
 */

namespace NiertoCube\PostTypes;

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Core\NiertoCore;

if (!defined('ABSPATH')) {
    exit;
}

class PostTypeManager implements ModuleInterface {
    /** @var PostTypeManager Singleton instance */
    private static $instance = null;
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var array Configuration settings */
    private $config = [];

    /** @var array Registered meta boxes */
    private $meta_boxes = [];

    /** @var array Default face positions */
    private const FACE_POSITIONS = [
        'face0' => 'Face 0 (Top)',
        'face1' => 'Face 1 (Front)',
        'face2' => 'Face 2 (Right)',
        'face3' => 'Face 3 (Back)',
        'face4' => 'Face 4 (Left)',
        'face5' => 'Face 5 (Bottom)'
    ];

    /** @var array Face templates */
    private const FACE_TEMPLATES = [
        'standard' => 'Standard Template',
        'multi_post' => 'Multi-Post Template',
        'settings' => 'Settings Template'
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
     * Initialize module
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            $this->config = array_merge([
                'post_type' => 'cube_face',
                'capability_type' => 'post',
                'supports' => ['title', 'editor', 'custom-fields', 'thumbnail', 'widgets'],
                'show_in_rest' => true,
                'menu_position' => 5,
                'menu_icon' => 'dashicons-cube'
            ], $config);

            // Register hooks
            add_action('init', [$this, 'registerPostTypes']);
            add_action('add_meta_boxes', [$this, 'registerMetaBoxes']);
            add_action('save_post_' . $this->config['post_type'], [$this, 'saveMetaBoxes']);
            add_filter('enter_title_here', [$this, 'modifyTitlePlaceholder']);
            add_filter('post_updated_messages', [$this, 'customizeUpdateMessages']);

            $this->initialized = true;

        } catch (\Exception $e) {
            if ($error = NiertoCore::getInstance()->getModule('Error')) {
                $error->logError('post_types_init_failed', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Register cube face post type
     */
    public function registerPostTypes(): void {
        register_post_type($this->config['post_type'], [
            'labels' => [
                'name' => __('Cube Faces', 'nierto-cube'),
                'singular_name' => __('Cube Face', 'nierto-cube'),
                'add_new' => __('Add New Face', 'nierto-cube'),
                'add_new_item' => __('Add New Cube Face', 'nierto-cube'),
                'edit_item' => __('Edit Cube Face', 'nierto-cube'),
                'new_item' => __('New Cube Face', 'nierto-cube'),
                'view_item' => __('View Cube Face', 'nierto-cube'),
                'search_items' => __('Search Cube Faces', 'nierto-cube'),
                'not_found' => __('No cube faces found', 'nierto-cube'),
                'not_found_in_trash' => __('No cube faces found in trash', 'nierto-cube'),
                'menu_name' => __('Cube Faces', 'nierto-cube')
            ],
            'public' => true,
            'hierarchical' => false,
            'show_in_menu' => true,
            'show_in_admin_bar' => true,
            'supports' => $this->config['supports'],
            'show_in_rest' => $this->config['show_in_rest'],
            'menu_position' => $this->config['menu_position'],
            'menu_icon' => $this->config['menu_icon'],
            'capability_type' => $this->config['capability_type'],
            'has_archive' => false,
            'rewrite' => ['slug' => 'face']
        ]);
    }

    /**
     * Register meta boxes
     */
    public function registerMetaBoxes(): void {
        add_meta_box(
            'cube_face_position',
            __('Face Position', 'nierto-cube'),
            [$this, 'renderPositionMetaBox'],
            $this->config['post_type'],
            'side',
            'high'
        );

        add_meta_box(
            'cube_face_template',
            __('Face Template', 'nierto-cube'),
            [$this, 'renderTemplateMetaBox'],
            $this->config['post_type'],
            'side',
            'high'
        );
    }

    /**
     * Render position meta box
     */
    public function renderPositionMetaBox($post): void {
        wp_nonce_field('cube_face_position_nonce', 'cube_face_position_nonce');
        $current_position = get_post_meta($post->ID, '_cube_face_position', true);
        ?>
        <select name="cube_face_position" id="cube_face_position" class="widefat">
            <?php foreach (self::FACE_POSITIONS as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current_position, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php _e('Select the position of this face on the cube.', 'nierto-cube'); ?>
        </p>
        <?php
    }

    /**
     * Render template meta box
     */
    public function renderTemplateMetaBox($post): void {
        wp_nonce_field('cube_face_template_nonce', 'cube_face_template_nonce');
        $current_template = get_post_meta($post->ID, '_cube_face_template', true);
        ?>
        <select name="cube_face_template" id="cube_face_template" class="widefat">
            <?php foreach (self::FACE_TEMPLATES as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($current_template, $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php _e('Select the template type for this face.', 'nierto-cube'); ?>
        </p>
        <?php
    }

    /**
     * Save meta box data
     */
    public function saveMetaBoxes($post_id): void {
        // Position meta box
        if (isset($_POST['cube_face_position_nonce']) && 
            wp_verify_nonce($_POST['cube_face_position_nonce'], 'cube_face_position_nonce')) {
            if (isset($_POST['cube_face_position'])) {
                update_post_meta(
                    $post_id,
                    '_cube_face_position',
                    sanitize_text_field($_POST['cube_face_position'])
                );
            }
        }

        // Template meta box
        if (isset($_POST['cube_face_template_nonce']) && 
            wp_verify_nonce($_POST['cube_face_template_nonce'], 'cube_face_template_nonce')) {
            if (isset($_POST['cube_face_template'])) {
                update_post_meta(
                    $post_id,
                    '_cube_face_template',
                    sanitize_text_field($_POST['cube_face_template'])
                );
            }
        }

        // Clear related caches
        if ($cache = NiertoCore::getInstance()->getModule('Cache')) {
            $cache->delete("face_content_{$post_id}");
        }
    }

    /**
     * Modify title placeholder
     */
    public function modifyTitlePlaceholder($title): string {
        $screen = get_current_screen();
        if ($screen->post_type === $this->config['post_type']) {
            $title = __('Enter face title', 'nierto-cube');
        }
        return $title;
    }

    /**
     * Customize update messages
     */
    public function customizeUpdateMessages($messages): array {
        global $post;

        $messages[$this->config['post_type']] = [
            0 => '', 
            1 => __('Cube face updated.', 'nierto-cube'),
            2 => __('Custom field updated.', 'nierto-cube'),
            3 => __('Custom field deleted.', 'nierto-cube'),
            4 => __('Cube face updated.', 'nierto-cube'),
            5 => isset($_GET['revision']) ? sprintf(
                __('Cube face restored to revision from %s', 'nierto-cube'),
                wp_post_revision_title((int) $_GET['revision'], false)
            ) : false,
            6 => __('Cube face published.', 'nierto-cube'),
            7 => __('Cube face saved.', 'nierto-cube'),
            8 => __('Cube face submitted.', 'nierto-cube'),
            9 => sprintf(
                __('Cube face scheduled for: <strong>%1$s</strong>.', 'nierto-cube'),
                date_i18n(__('M j, Y @ G:i', 'nierto-cube'), strtotime($post->post_date))
            ),
            10 => __('Cube face draft updated.', 'nierto-cube')
        ];

        return $messages;
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
            'post_type' => $this->config['post_type'],
            'show_in_rest' => $this->config['show_in_rest'],
            'registered_meta_boxes' => array_keys($this->meta_boxes)
        ];
    }
}

// Initialize PostTypeManager
add_action('after_setup_theme', function() {
    $post_types = PostTypeManager::getInstance();
    $post_types->initialize();
}, 10);