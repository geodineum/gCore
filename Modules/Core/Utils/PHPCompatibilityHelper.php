<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Utils;

/**
 * PHP Compatibility Helper
 * 
 * Provides utilities for ensuring compatibility with different PHP versions,
 * particularly for WordPress integration which may run on older PHP versions.
 * 
 * Features:
 * - PHP version detection and compatibility checks
 * - Polyfills for newer PHP features
 * - Helper methods for version-dependent code paths
 * - WordPress-specific compatibility adaptations
 * 
 * @package gCore
 * @subpackage Core\Utils
 */
class PHPCompatibilityHelper {
    /**
     * @var array Configuration
     */
    private $config;

    /**
     * @var array Compatibility flags
     */
    private $compatFlags;

    /**
     * Constructor
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'min_php_version' => '7.2',
            'recommended_php_version' => '7.4',
            'compatibility_mode' => true,
            'show_warnings' => true,
            'error_log' => true
        ], $config);

        $this->compatFlags = [
            'typed_properties' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'arrow_functions' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'spread_operator' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'null_coalesce_assign' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'union_types' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'named_arguments' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'fiber_support' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'readonly_properties' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'first_class_callable' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'ffi_support' => extension_loaded('ffi') && version_compare(PHP_VERSION, '7.4.0', '>=')
        ];
    }

    /**
     * Check if current PHP version meets minimum requirements
     * 
     * @return bool True if PHP version is compatible
     */
    public function isCompatible(): bool {
        return version_compare(PHP_VERSION, $this->config['min_php_version'], '>=');
    }

    /**
     * Get compatibility status with details
     * 
     * @return array Status details
     */
    public function getCompatibilityStatus(): array {
        return [
            'current_version' => PHP_VERSION,
            'min_version' => $this->config['min_php_version'],
            'recommended_version' => $this->config['recommended_php_version'],
            'is_compatible' => $this->isCompatible(),
            'features' => $this->compatFlags,
            'environment' => [
                'sapi' => PHP_SAPI,
                'os' => PHP_OS,
                'extensions' => get_loaded_extensions()
            ]
        ];
    }

    /**
     * Check if specific PHP feature is available
     * 
     * @param string $feature Feature name
     * @return bool True if feature is available
     */
    public function hasFeature(string $feature): bool {
        return isset($this->compatFlags[$feature]) && $this->compatFlags[$feature];
    }

    /**
     * Log compatibility warning
     * 
     * @param string $message Warning message
     * @return void
     */
    public function logCompatWarning(string $message): void {
        if ($this->config['show_warnings']) {
            if ($this->config['error_log']) {
                error_log("PHP Compatibility Warning: {$message}");
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log("gCore PHP Compatibility Warning: {$message}");
            }
        }
    }

    /**
     * Create array from object properties (for PHP < 7.4 without property type hints)
     * 
     * @param object $object Source object
     * @return array Property array
     */
    public function objectToArray($object): array {
        if (!is_object($object)) {
            return [];
        }
        
        return get_object_vars($object);
    }

    /**
     * Safe implementation of null coalescing assignment (PHP < 7.4)
     * 
     * @param mixed &$var Variable to assign to
     * @param mixed $value Value to assign if $var is null
     * @return mixed The resulting value
     */
    public function nullCoalesceAssign(&$var, $value) {
        if ($this->hasFeature('null_coalesce_assign')) {
            $var ??= $value;
        } else {
            if ($var === null) {
                $var = $value;
            }
        }
        return $var;
    }

    /**
     * Safe str_contains polyfill for PHP < 8.0
     * 
     * @param string $haystack The string to search in
     * @param string $needle The substring to search for
     * @return bool True if $needle is in $haystack
     */
    public function strContains(string $haystack, string $needle): bool {
        if (function_exists('str_contains')) {
            return str_contains($haystack, $needle);
        }
        
        return $needle === '' || strpos($haystack, $needle) !== false;
    }

    /**
     * Safe str_starts_with polyfill for PHP < 8.0
     * 
     * @param string $haystack The string to search in
     * @param string $needle The substring to check for at the start
     * @return bool True if $haystack starts with $needle
     */
    public function strStartsWith(string $haystack, string $needle): bool {
        if (function_exists('str_starts_with')) {
            return str_starts_with($haystack, $needle);
        }
        
        return $needle === '' || strpos($haystack, $needle) === 0;
    }

    /**
     * Safe str_ends_with polyfill for PHP < 8.0
     * 
     * @param string $haystack The string to search in
     * @param string $needle The substring to check for at the end
     * @return bool True if $haystack ends with $needle
     */
    public function strEndsWith(string $haystack, string $needle): bool {
        if (function_exists('str_ends_with')) {
            return str_ends_with($haystack, $needle);
        }
        
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }

    /**
     * Get an array key with fallback (compatible with all PHP versions)
     * 
     * @param array $array The array to access
     * @param string|int $key The key to access
     * @param mixed $default The default value if key doesn't exist
     * @return mixed The value or default
     */
    public function arrayGet(array $array, $key, $default = null) {
        return isset($array[$key]) ? $array[$key] : $default;
    }

    /**
     * Safe array_key_first polyfill for PHP < 7.3
     * 
     * @param array $array The array
     * @return string|int|null The first key or null for empty array
     */
    public function arrayKeyFirst(array $array) {
        if (function_exists('array_key_first')) {
            return array_key_first($array);
        }
        
        if (empty($array)) {
            return null;
        }
        
        reset($array);
        return key($array);
    }

    /**
     * Safe array_key_last polyfill for PHP < 7.3
     * 
     * @param array $array The array
     * @return string|int|null The last key or null for empty array
     */
    public function arrayKeyLast(array $array) {
        if (function_exists('array_key_last')) {
            return array_key_last($array);
        }
        
        if (empty($array)) {
            return null;
        }
        
        end($array);
        return key($array);
    }

    /**
     * Convert Arrow Function to standard closure for PHP < 7.4
     * Not fully compatible, but provides a standard closure alternative
     * 
     * @param callable $function Function to adapt
     * @return callable Regular closure function
     */
    public function createClosure(callable $function): callable {
        return $function;
    }

    /**
     * Check if WordPress integration is active
     * 
     * @return bool True if WordPress is detected
     */
    public function isWordPressEnvironment(): bool {
        return defined('ABSPATH') && function_exists('add_action');
    }

    /**
     * WordPress-specific compatibility checks
     * 
     * @return array WordPress compatibility status
     */
    public function getWordPressCompatibility(): array {
        if (!$this->isWordPressEnvironment()) {
            return ['is_wp' => false];
        }

        global $wp_version;
        
        return [
            'is_wp' => true,
            'wp_version' => $wp_version ?? 'unknown',
            'min_wp_version' => '5.2', // Minimum WordPress version for gCore
            'is_multisite' => is_multisite(),
            'use_object_cache' => wp_using_ext_object_cache(),
            'capabilities' => [
                'unfiltered_html' => current_user_can('unfiltered_html'),
                'manage_options' => current_user_can('manage_options')
            ]
        ];
    }

    /**
     * Check if a class uses typed properties (PHP 7.4+)
     * 
     * @param string $className The class to check
     * @return bool True if class uses typed properties
     */
    public function classUsesTypedProperties(string $className): bool {
        if (!class_exists($className)) {
            return false;
        }
        
        // If PHP version doesn't support typed properties, return false
        if (!$this->hasFeature('typed_properties')) {
            return false;
        }
        
        $reflection = new \ReflectionClass($className);
        $properties = $reflection->getProperties();
        
        foreach ($properties as $property) {
            if ($property->hasType()) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Returns whether the current PHP version is compatible for WordPress
     * 
     * WordPress environments often use older PHP versions
     * 
     * @return bool True if PHP version is safe for WordPress
     */
    public function isWordPressPhpVersionCompatible(): bool {
        // WordPress recommends PHP 7.4+, but supports 5.6+
        // gCore requires at least 7.2 for some functionality
        return version_compare(PHP_VERSION, '7.2.0', '>=');
    }
}