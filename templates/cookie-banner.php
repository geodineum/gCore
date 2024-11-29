<?php

/**
 * Template for cookie consent banner
 * 
 * @package NiertoCube
 * @subpackage Privacy
 * @version 2.0.0
 */

// Ensure template is not accessed directly
if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="cookie-notice-overlay">
    <div id="cookie-notice">
        <form id="cookie-preferences-form">
            <h2><?php esc_html_e('Cookie Preferences', 'nierto-cube'); ?></h2>
            
            <p><?php esc_html_e('We use cookies to enhance your experience on our website. Please select your preferences below.', 'nierto-cube'); ?></p>
            
            <div class="cookie-categories">
                <?php foreach ($args['categories'] as $category): ?>
                    <div class="cookie-category">
                        <div>
                            <input 
                                type="checkbox" 
                                id="cookie_category_<?php echo esc_attr($category['id']); ?>"
                                name="cookie_category_<?php echo esc_attr($category['id']); ?>"
                                value="true"
                                <?php checked($category['required'], true); ?>
                                <?php disabled($category['required'], true); ?>
                            >
                        </div>
                        <div>
                            <label 
                                for="cookie_category_<?php echo esc_attr($category['id']); ?>"
                                class="cookie-category-label"
                            >
                                <?php echo esc_html($category['name']); ?>
                                <?php if ($category['required']): ?>
                                    <span class="required"><?php esc_html_e('(Required)', 'nierto-cube'); ?></span>
                                <?php endif; ?>
                            </label>
                            <div class="cookie-category-description">
                                <?php echo esc_html($category['description']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($args['privacy_url']): ?>
                <p>
                    <a href="<?php echo esc_url($args['privacy_url']); ?>" target="_blank">
                        <?php esc_html_e('View Privacy Policy', 'nierto-cube'); ?>
                    </a>
                </p>
            <?php endif; ?>

            <div class="cookie-notice-buttons">
                <button type="button" class="cookie-notice-button reject-all">
                    <?php esc_html_e('Reject All', 'nierto-cube'); ?>
                </button>
                <button type="button" class="cookie-notice-button accept-all">
                    <?php esc_html_e('Accept All', 'nierto-cube'); ?>
                </button>
                <button type="submit" class="cookie-notice-button save-preferences">
                    <?php esc_html_e('Save Preferences', 'nierto-cube'); ?>
                </button>
            </div>
        </form>
    </div>
</div>