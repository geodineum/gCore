<?php
declare(strict_types=1);
namespace gCore\Modules\Core;

use gCore\Modules\Core\Utils\PHPCompatibilityHelper;

/**
 * WordPress Compatibility Bridge
 * 
 * Provides utility functions for ensuring WordPress compatibility
 * with different PHP versions, especially handling features that
 * may not be available in older PHP versions running WordPress.
 * 
 * @package gCore
 * @subpackage Core
 */
class WordPressCompatibilityBridge {
    /**
     * @var PHPCompatibilityHelper
     */
    private $compatHelper;
    
    /**
     * @var array Config
     */
    private $config;

    /**
     * Constructor
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'wp_min_version' => '5.2',
            'php_min_version' => '7.2',
            'environment' => 'wordpress',
            'add_polyfills' => true,
            'enable_hooks' => true
        ], $config);
        
        $this->compatHelper = new PHPCompatibilityHelper();
        
        // Register compatibility hooks if in WordPress
        if ($this->isWordPressEnvironment() && $this->config['enable_hooks']) {
            $this->registerCompatibilityHooks();
        }
        
        // Add polyfills if needed
        if ($this->config['add_polyfills']) {
            $this->addPolyfills();
        }
    }
    
    /**
     * Check if running in WordPress environment
     */
    public function isWordPressEnvironment(): bool {
        return defined('ABSPATH') && function_exists('add_action');
    }
    
    /**
     * Register compatibility hooks
     */
    private function registerCompatibilityHooks(): void {
        // Add admin notice if PHP version is below recommended
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            add_action('admin_notices', function() {
                // Using traditional anonymous function for PHP 7.2+ compatibility
                echo '<div class="notice notice-warning is-dismissible"><p>' . 
                     sprintf(
                         __('gCore: You are running PHP %1$s. For optimal performance, we recommend updating to PHP 7.4 or higher.', 'gcore'),
                         PHP_VERSION
                     ) . 
                     '</p></div>';
            });
        }
        
        // Add admin capability check wrapper
        add_filter('gcore_check_capability', [$this, 'checkWordPressCapability'], 10, 2);
        
        // Add WordPress-specific error handling
        add_filter('gcore_handle_error', [$this, 'handleWordPressError'], 10, 2);
    }
    
    /**
     * Add polyfills for missing PHP functions
     */
    private function addPolyfills(): void {
        // Polyfill for str_contains (PHP 8.0+)
        if (!function_exists('str_contains')) {
            function str_contains(string $haystack, string $needle): bool {
                return $needle === '' || strpos($haystack, $needle) !== false;
            }
        }
        
        // Polyfill for str_starts_with (PHP 8.0+)
        if (!function_exists('str_starts_with')) {
            function str_starts_with(string $haystack, string $needle): bool {
                return $needle === '' || strpos($haystack, $needle) === 0;
            }
        }
        
        // Polyfill for str_ends_with (PHP 8.0+)
        if (!function_exists('str_ends_with')) {
            function str_ends_with(string $haystack, string $needle): bool {
                return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
            }
        }
        
        // Additional polyfills can be added as needed
    }
    
    /**
     * Get a compatible callable/closure for older PHP versions
     * 
     * @param callable $callback The original callback
     * @return callable A compatible callback
     */
    public function getCompatibleCallback(callable $callback): callable {
        // For PHP 7.2+, just return the original callback
        return $callback;
    }
    
    /**
     * Safe wrapper for WordPress capability checks
     * 
     * @param string $capability The capability to check
     * @param mixed $object_id Optional object ID for context
     * @return bool Whether the current user has the capability
     */
    public function checkWordPressCapability(string $capability, $object_id = null): bool {
        if (!function_exists('current_user_can')) {
            return false;
        }
        
        if ($object_id !== null) {
            return current_user_can($capability, $object_id);
        }
        
        return current_user_can($capability);
    }
    
    /**
     * WordPress-specific error handling
     * 
     * @param \Throwable $error The error to handle
     * @param array $context Additional context information
     * @return mixed The error handling result
     */
    public function handleWordPressError(\Throwable $error, array $context = []) {
        // Check if we should use WP_Error
        if (class_exists('WP_Error') && ($context['use_wp_error'] ?? true)) {
            return new \WP_Error(
                'gcore_error',
                $error->getMessage(),
                [
                    'exception' => $error,
                    'file' => $error->getFile(),
                    'line' => $error->getLine(),
                    'trace' => $error->getTraceAsString(),
                    'context' => $context
                ]
            );
        }
        
        // Log to WordPress error log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                'gCore Error: %s in %s on line %d',
                $error->getMessage(),
                $error->getFile(),
                $error->getLine()
            ));
        }
        
        return null;
    }
    
    /**
     * Check if a feature is available in the current PHP version
     * 
     * @param string $feature The PHP feature to check
     * @return bool Whether the feature is available
     */
    public function hasFeature(string $feature): bool {
        return $this->compatHelper->hasFeature($feature);
    }
    
    /**
     * Get object properties as array for PHP < 7.4
     * 
     * @param object $object The object to convert
     * @return array The object properties as array
     */
    public function objectToArray(object $object): array {
        return $this->compatHelper->objectToArray($object);
    }
    
    /**
     * Convert WordPress user capability to gCore capability
     * 
     * @param string $capability WordPress capability name
     * @return string gCore capability name
     */
    public function mapWordPressCapability(string $capability): string {
        $capabilityMap = [
            'manage_options' => 'admin',
            'edit_posts' => 'editor',
            'read' => 'view',
            'upload_files' => 'upload',
            'install_plugins' => 'system_admin'
        ];
        
        return $capabilityMap[$capability] ?? $capability;
    }
    
    /**
     * Create a WordPress admin notice handler function
     * 
     * @param string $message The message to display
     * @param string $type The notice type (error, warning, success, info)
     * @param bool $dismissible Whether the notice is dismissible
     * @return callable A function that outputs the admin notice
     */
    public function createAdminNoticeHandler(string $message, string $type = 'warning', bool $dismissible = true): callable {
        return function() use ($message, $type, $dismissible) {
            $class = 'notice notice-' . $type;
            if ($dismissible) {
                $class .= ' is-dismissible';
            }
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        };
    }
}

// Create global instance if in WordPress environment
if (!function_exists('gcore_wp_compat') && defined('ABSPATH')) {
    /**
     * Get the WordPress compatibility bridge instance
     *
     * @param array $config Optional configuration options
     * @return WordPressCompatibilityBridge The compatibility bridge instance
     */
    function gcore_wp_compat(array $config = []): WordPressCompatibilityBridge {
        static $instance = null;
        
        if ($instance === null) {
            $instance = new WordPressCompatibilityBridge($config);
        }
        
        return $instance;
    }
}