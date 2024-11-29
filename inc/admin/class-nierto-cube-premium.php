<?php
if (!defined('ABSPATH')) {
    exit;
}

class NiertoCube_Premium {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('nierto_cube_admin_page', array($this, 'render_premium_plugins_section'));
        add_action('wp_ajax_nierto_cube_activate_premium_plugin', array($this, 'handle_plugin_activation'));
    }

    public function render_premium_plugins_section() {
        $premium_plugins = [
            'Woocommerce Plugin' => 'Integrate with WooCommerce and display product images inside the cube.',
            'LLM Plugin' => 'Connect to your own local LLM or an LLM hosted by Agentique.ai.',
            'SEO Plugin' => 'Enhance SEO scores specifically for NiertoCube.',
            'Image Optimization Plugin' => 'Render images on cube sides and optimize uploaded images.',
            'Contact Plugin' => 'Specialized contact form with call-to-action buttons for messaging apps.'
        ];

        echo '<div class="nierto-cube-premium-plugins">';
        foreach ($premium_plugins as $plugin_name => $description) {
            $slug = sanitize_title($plugin_name);
            ?>
            <div class="premium-plugin-item">
                <h3><?php echo esc_html($plugin_name); ?></h3>
                <p><?php echo esc_html($description); ?></p>
                <input type="text" id="<?php echo esc_attr($slug); ?>_key" name="<?php echo esc_attr($slug); ?>_key" placeholder="COMING SOON!" disabled>
                <button class="button activate-plugin" data-plugin="<?php echo esc_attr($slug); ?>" disabled>Activate</button>
                <button class="button more-info" data-plugin="<?php echo esc_attr($slug); ?>">More Info</button>
            </div>
            <?php
        }
        echo '</div>';

        $this->enqueue_premium_scripts();
    }

    private function enqueue_premium_scripts() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const moreInfoButtons = document.querySelectorAll('.more-info');
            moreInfoButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const plugin = this.getAttribute('data-plugin');
                    const infoUrl = `https://nierto.com/plugins/${plugin}-info`;
                    window.open(infoUrl, '_blank');
                });
            });
        });
        </script>
        <?php
    }

    public function handle_plugin_activation() {
        check_ajax_referer('nierto_cube_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $plugin = sanitize_text_field($_POST['plugin']);
        wp_send_json_success('Plugin activation placeholder for: ' . $plugin);
    }
}

// Initialize the premium plugins functionality
NiertoCube_Premium::get_instance();