<?php
/**
 * gCore Debug Helper
 *
 * This file provides WordPress-specific debug functions to diagnose
 * plugin activation issues and monitor operational status.
 */

// If this file is called directly, abort
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug constants and option defaults
 */
if (!defined('GCORE_DEBUG')) {
    define('GCORE_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}

if (!defined('GCORE_DEBUG_LOG')) {
    define('GCORE_DEBUG_LOG', true);
}

if (!defined('GCORE_DEBUG_DISPLAY')) {
    define('GCORE_DEBUG_DISPLAY', defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY);
}

/**
 * Enhanced error handler with diagnostic information
 */
function gcore_activation_error_handler() {
    // Get last error information
    $last_error = error_get_last();

    // Only proceed if we have an error
    if (!$last_error) {
        return;
    }

    // Create log directory if it doesn't exist
    $log_dir = defined('GCORE_LOG_PATH') ? GCORE_LOG_PATH : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/gcore-logs' : __DIR__ . '/logs');
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    // Error type mapping
    $error_types = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict Standards',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
    ];

    $error_type = $error_types[$last_error['type']] ?? 'Unknown Error';

    // Log the error with more details
    $log_file = $log_dir . '/activation_error.log';
    $timestamp = date('Y-m-d H:i:s');
    $error_message = sprintf(
        "[%s] %s:\nMessage: %s\nFile: %s\nLine: %d\n\n",
        $timestamp,
        $error_type,
        $last_error['message'],
        $last_error['file'],
        $last_error['line']
    );

    file_put_contents($log_file, $error_message, FILE_APPEND);

    // Create traceback if possible
    if (function_exists('debug_backtrace') && $last_error['type'] != E_PARSE) {
        try {
            $trace = debug_backtrace();
            if (!empty($trace)) {
                $trace_message = "Traceback:\n";
                foreach ($trace as $i => $step) {
                    $trace_message .= sprintf(
                        "#%d %s(%d): %s%s%s(%s)\n",
                        $i,
                        $step['file'] ?? 'unknown file',
                        $step['line'] ?? 0,
                        $step['class'] ?? '',
                        $step['type'] ?? '',
                        $step['function'] ?? 'unknown function',
                        implode(', ', array_map(function($param) {
                            return is_scalar($param) ? var_export($param, true) : gettype($param);
                        }, $step['args'] ?? []))
                    );
                }
                file_put_contents($log_file, $trace_message . "\n", FILE_APPEND);
            }
        } catch (\Throwable $e) {
            // Ignore errors during traceback generation
        }
    }

    // For all errors, provide detailed information about the environment
    $system_info = sprintf(
        "System Information:\n" .
        "PHP Version: %s\n" .
        "WordPress Version: %s\n" .
        "OS: %s\n" .
        "Server Software: %s\n" .
        "Server API: %s\n" .
        "Memory Usage: %s / %s\n" .
        "Peak Memory Usage: %s\n" .
        "Memory Limit: %s\n" .
        "Max Execution Time: %s\n" .
        "Loaded Extensions: %s\n\n" .
        "gCore Version: %s\n" .
        "ValKey Available: %s\n\n",
        PHP_VERSION,
        $GLOBALS['wp_version'] ?? 'Unknown',
        PHP_OS,
        $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        PHP_SAPI,
        gcore_format_bytes(memory_get_usage(true)),
        ini_get('memory_limit'),
        gcore_format_bytes(memory_get_peak_usage(true)),
        ini_get('memory_limit'),
        ini_get('max_execution_time'),
        implode(', ', array_slice(get_loaded_extensions(), 0, 10)) . '...',
        defined('GCORE_VERSION') ? GCORE_VERSION : 'Unknown',
        gcore_check_valkey_debug() ? 'Yes' : 'No'
    );

    file_put_contents($log_file, $system_info, FILE_APPEND);

    // Check for common error patterns
    $error_context = gcore_analyze_error($last_error);
    if (!empty($error_context)) {
        $context_info = "Error Analysis:\n";
        foreach ($error_context as $key => $value) {
            $context_info .= sprintf("%s: %s\n", $key, $value);
        }
        file_put_contents($log_file, $context_info . "\n", FILE_APPEND);
    }

    // Create a summary entry in the main error log
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log(sprintf(
            'gCore %s: %s in %s on line %d. See %s for details.',
            $error_type,
            $last_error['message'],
            basename($last_error['file']),
            $last_error['line'],
            $log_file
        ));
    }

    // Store the error for admin notice display
    if (in_array($last_error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        update_option('gcore_fatal_error', [
            'type' => $error_type,
            'message' => $last_error['message'],
            'file' => $last_error['file'],
            'line' => $last_error['line'],
            'time' => time(),
            'log_file' => $log_file
        ]);
    }
}

// Format bytes to human-readable format
function gcore_format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Simple ValKey availability check for debugging
function gcore_check_valkey_debug() {
    if (!extension_loaded('redis')) {
        return false;
    }

    try {
        $redis = new \Redis();
        $connected = @$redis->connect('127.0.0.1', 6379, 1); // Quick timeout

        if ($connected) {
            $redis->close();
            return true;
        }
        return false;
    } catch (\Throwable $e) {
        return false;
    }
}

// Analyze error for common patterns
function gcore_analyze_error($error) {
    $context = [];
    $message = $error['message'];
    $file = $error['file'];

    // Class not found errors
    if (preg_match('/Class [\'"]([^\'"]+)[\'"] not found/', $message, $matches)) {
        $class = $matches[1];
        $context['error_type'] = 'Class not found';
        $context['missing_class'] = $class;
        $context['possible_solution'] = 'Check autoloading configuration or ensure this class file exists';

        // Try to find the file that should contain this class
        $class_path = str_replace('\\', '/', $class) . '.php';
        $context['expected_file'] = $class_path;
    }

    // Undefined function errors
    elseif (preg_match('/Call to undefined function ([^\(]+)\(/', $message, $matches)) {
        $function = trim($matches[1]);
        $context['error_type'] = 'Function not found';
        $context['missing_function'] = $function;

        // Check for common PHP extensions
        $extension_functions = [
            'redis_' => 'redis',
            'curl_' => 'curl',
            'mysqli_' => 'mysqli',
            'imagecreate' => 'gd',
            'json_' => 'json',
            'ffi_' => 'ffi'
        ];

        foreach ($extension_functions as $prefix => $ext) {
            if (strpos($function, $prefix) === 0 || $function === $prefix) {
                $context['missing_extension'] = $ext;
                $context['possible_solution'] = "Install the {$ext} PHP extension";
                break;
            }
        }
    }

    // Interface issues
    elseif (preg_match('/must implement interface/', $message)) {
        $context['error_type'] = 'Interface implementation issue';
        $context['possible_solution'] = 'Check that class implements all methods required by the interface';
    }

    // Syntax errors
    elseif ($error['type'] === E_PARSE) {
        $context['error_type'] = 'Syntax error';
        $context['possible_solution'] = 'Check the code syntax around the specified line number';

        // Try to identify the specific syntax issue
        if (strpos($message, 'unexpected') !== false) {
            if (preg_match('/unexpected ([^\s]+)/', $message, $matches)) {
                $context['syntax_issue'] = "Unexpected " . $matches[1];
            }
        }
    }

    // Memory issues
    elseif (strpos($message, 'Allowed memory size') !== false) {
        $context['error_type'] = 'Memory limit exceeded';
        $context['current_limit'] = ini_get('memory_limit');
        $context['possible_solution'] = 'Increase memory_limit in php.ini or use ini_set()';
    }

    // Check for circular dependencies
    elseif (strpos($message, 'circular') !== false || strpos($message, 'dependency') !== false) {
        $context['error_type'] = 'Circular dependency';
        $context['possible_solution'] = 'Check service dependencies for circular references';
    }

    // ValKey/Redis issues
    elseif (strpos($file, 'ValKey') !== false || strpos($file, 'Redis') !== false) {
        $context['error_type'] = 'ValKey/Redis error';
        $context['possible_solution'] = 'Check ValKey/Redis connection and configuration';
        $context['valkey_available'] = gcore_check_valkey_debug() ? 'Yes' : 'No';
    }

    return $context;
}

// Register the error handler for shutdown to catch fatal errors
register_shutdown_function('gcore_activation_error_handler');

/**
 * Helper: strip an absolute path down to a path relative to WP_CONTENT_DIR
 * so that full server paths are never exposed in the admin UI.
 *
 * @param string $path Absolute file path
 * @return string Relative path from wp-content, or basename as last resort
 */
function gcore_relative_log_path($path) {
    if (defined('WP_CONTENT_DIR') && strpos($path, WP_CONTENT_DIR) === 0) {
        return 'wp-content' . substr($path, strlen(WP_CONTENT_DIR));
    }
    // Fallback: show only the filename so no server paths leak
    return basename($path);
}

/**
 * Function to display admin notices about fatal errors
 */
function gcore_admin_fatal_error_notice() {
    $error = get_option('gcore_fatal_error');
    if (!$error) {
        return;
    }

    // Only show for up to 7 days
    if (time() - $error['time'] > 7 * DAY_IN_SECONDS) {
        delete_option('gcore_fatal_error');
        return;
    }

    echo '<div class="notice notice-error">';
    echo '<p><strong>gCore Plugin Fatal Error</strong></p>';
    echo '<p>The gCore plugin encountered a fatal error:</p>';
    echo '<p><strong>' . esc_html($error['type']) . ':</strong> ' . esc_html($error['message']) . '</p>';
    echo '<p>File: <code>' . esc_html(str_replace(ABSPATH, '', $error['file'])) . '</code> on line ' . esc_html($error['line']) . '</p>';

    if (file_exists($error['log_file'])) {
        // Display only the relative path, never the full server path
        echo '<p>Detailed error log: <code>' . esc_html(gcore_relative_log_path($error['log_file'])) . '</code></p>';
        echo '<pre style="background: #fff; padding: 10px; max-height: 200px; overflow: auto; font-size: 12px;">';
        echo htmlspecialchars(file_get_contents($error['log_file'], false, null, -2048)); // Show last 2KB
        echo '</pre>';
    }

    echo '<p>These errors may prevent the plugin from functioning correctly. Please fix the issues or deactivate the plugin.</p>';

    // Add ValKey diagnostic information if relevant
    if (strpos($error['file'], 'ValKey') !== false || strpos($error['message'], 'Redis') !== false) {
        echo '<h3>ValKey/Redis Diagnostic Information</h3>';
        echo '<p>ValKey/Redis Status: <strong>' . (gcore_check_valkey_debug() ? 'Available' : 'Not Available') . '</strong></p>';
        echo '<p>For ValKey/Redis issues, please check:</p>';
        echo '<ul style="list-style-type: disc; padding-left: 20px;">';
        echo '<li>Redis extension is installed: <strong>' . (extension_loaded('redis') ? 'Yes' : 'No') . '</strong></li>';
        echo '<li>ValKey/Redis server is running on localhost:6379</li>';
        echo '<li>Proper authentication is configured if required</li>';
        echo '</ul>';
    }

    // Add diagnostic button — dismiss link now includes a nonce
    $dismiss_url = wp_nonce_url(
        add_query_arg('gcore_dismiss_error', '1'),
        'gcore_dismiss_error',
        '_gcore_dismiss_nonce'
    );
    echo '<p>';
    echo '<a href="' . esc_url(admin_url('tools.php?page=gcore-diagnostics')) . '" class="button button-primary">Run gCore Diagnostics</a> ';
    echo '<a href="' . esc_url($dismiss_url) . '" class="button">Dismiss</a>';
    echo '</p>';
    echo '</div>';
}

// Add admin notice for fatal errors
add_action('admin_notices', 'gcore_admin_fatal_error_notice');

// Handle error dismissal — with nonce verification (CSRF fix)
function gcore_handle_error_dismissal() {
    if (isset($_GET['gcore_dismiss_error']) && current_user_can('manage_options')) {
        // Verify nonce to prevent CSRF
        if (!isset($_GET['_gcore_dismiss_nonce']) || !wp_verify_nonce($_GET['_gcore_dismiss_nonce'], 'gcore_dismiss_error')) {
            wp_die(
                __('Security check failed. Please go back and try again.', 'gcore'),
                __('gCore - Security Error', 'gcore'),
                ['response' => 403]
            );
        }
        delete_option('gcore_fatal_error');
        wp_redirect(remove_query_arg(['gcore_dismiss_error', '_gcore_dismiss_nonce']));
        exit;
    }
}
add_action('admin_init', 'gcore_handle_error_dismissal');

/**
 * Enhanced WordPress debug information
 *
 * @return array Detailed system information
 */
function gcore_debug_wordpress() {
    global $wpdb;

    // Collect theme information
    $theme = wp_get_theme();
    $theme_info = [
        'Name' => $theme->get('Name'),
        'Version' => $theme->get('Version'),
        'ThemeURI' => $theme->get('ThemeURI'),
        'Template' => $theme->get('Template'),
    ];

    // Check for active caching plugins
    $cache_plugins = [];
    $known_cache_plugins = [
        'wp-super-cache/wp-cache.php' => 'WP Super Cache',
        'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
        'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
        'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
        'wp-rocket/wp-rocket.php' => 'WP Rocket',
        'redis-cache/redis-cache.php' => 'Redis Object Cache',
    ];

    $active_plugins = get_option('active_plugins');
    foreach ($active_plugins as $plugin) {
        if (isset($known_cache_plugins[$plugin])) {
            $cache_plugins[$plugin] = $known_cache_plugins[$plugin];
        }
    }

    // Get WordPress database information
    $mysql_version = $wpdb->get_var('SELECT VERSION()');

    // Create debug output
    $debug_info = [
        'WordPress Environment' => [
            'WordPress Version' => get_bloginfo('version'),
            'WordPress URL' => get_bloginfo('url'),
            'Site URL' => get_bloginfo('wpurl'),
            'Multisite' => is_multisite() ? 'Yes' : 'No',
            'Debug Mode' => defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled',
            'Memory Limit' => WP_MEMORY_LIMIT,
            'Table Prefix Length' => strlen($wpdb->prefix),
            'Database Character Set' => $wpdb->get_var("SHOW VARIABLES LIKE 'character_set_database'", 1),
            'Theme' => $theme_info,
            'Cache Plugins' => !empty($cache_plugins) ? $cache_plugins : 'None detected',
            'Child Theme' => is_child_theme() ? 'Yes' : 'No',
        ],
        'Server Environment' => [
            'PHP Version' => PHP_VERSION,
            'MySQL Version' => $mysql_version,
            'Web Server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'Operating System' => PHP_OS,
            'Server API' => PHP_SAPI,
            'PHP Memory Limit' => ini_get('memory_limit'),
            'PHP Time Limit' => ini_get('max_execution_time'),
            'PHP Upload Max Size' => ini_get('upload_max_filesize'),
            'PHP Post Max Size' => ini_get('post_max_size'),
            'PHP Max Input Vars' => ini_get('max_input_vars'),
            'PHP Output Buffering' => ini_get('output_buffering') ? 'Enabled' : 'Disabled',
            'CURL Version' => function_exists('curl_version') ? curl_version()['version'] : 'Not installed',
            'SSL Enabled' => is_ssl() ? 'Yes' : 'No',
        ],
        'PHP Extensions' => [
            'Redis' => extension_loaded('redis') ? 'Enabled' : 'Not installed',
            'FFI' => extension_loaded('ffi') ? 'Enabled' : 'Not installed',
            'GD' => extension_loaded('gd') ? 'Enabled' : 'Not installed',
            'Imagick' => extension_loaded('imagick') ? 'Enabled' : 'Not installed',
            'Mbstring' => extension_loaded('mbstring') ? 'Enabled' : 'Not installed',
            'OpenSSL' => extension_loaded('openssl') ? 'Enabled' : 'Not installed',
            'Curl' => extension_loaded('curl') ? 'Enabled' : 'Not installed',
            'XML' => extension_loaded('xml') ? 'Enabled' : 'Not installed',
            'ZIP' => extension_loaded('zip') ? 'Enabled' : 'Not installed',
        ],
        'gCore Configuration' => [
            'gCore Version' => defined('GCORE_VERSION') ? GCORE_VERSION : 'Not defined',
            'Plugin Path' => defined('GCORE_PLUGIN_DIR') ? GCORE_PLUGIN_DIR : 'Not defined',
            'Plugin URL' => defined('GCORE_PLUGIN_URL') ? GCORE_PLUGIN_URL : 'Not defined',
            'Config Path' => defined('GCORE_CONFIG_PATH') ? GCORE_CONFIG_PATH : 'Not defined',
            'Assets URL' => defined('GCORE_ASSETS_URL') ? GCORE_ASSETS_URL : 'Not defined',
            'Debug Mode' => defined('GCORE_DEBUG') && GCORE_DEBUG ? 'Enabled' : 'Disabled',
            'ValKey Available' => gcore_check_valkey_debug() ? 'Yes' : 'No',
        ],
        'Active Plugins' => $active_plugins,
    ];

    return $debug_info;
}

/**
 * Function to run complete diagnostic check
 */
function gcore_run_diagnostics() {
    // Create log directory
    $log_dir = defined('GCORE_LOG_PATH') ? GCORE_LOG_PATH : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/gcore-logs' : __DIR__ . '/logs');
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . '/diagnostics.log';
    $timestamp = date('Y-m-d H:i:s');

    // Basic system information
    $debug_info = gcore_debug_wordpress();

    // Check ValKey connectivity
    $valkey_check = gcore_check_valkey_connection();

    // Check FFI availability
    $ffi_check = gcore_check_ffi_support();

    // Check file permissions
    $permissions_check = gcore_check_permissions();

    // Check constants
    $constants_check = gcore_check_constants();

    // Log results
    $log_content = "=== gCore Diagnostic Results ({$timestamp}) ===\n\n";

    // Add system info summary
    $log_content .= "== System Information ==\n";
    $log_content .= "WordPress Version: " . $debug_info['WordPress Environment']['WordPress Version'] . "\n";
    $log_content .= "PHP Version: " . $debug_info['Server Environment']['PHP Version'] . "\n";
    $log_content .= "Web Server: " . $debug_info['Server Environment']['Web Server'] . "\n";
    $log_content .= "MySQL Version: " . $debug_info['Server Environment']['MySQL Version'] . "\n\n";

    // Add ValKey/Redis check
    $log_content .= "== ValKey/Redis Check ==\n";
    foreach ($valkey_check as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif (is_array($value)) {
            $value = implode(', ', $value);
        }
        $log_content .= "{$key}: {$value}\n";
    }
    $log_content .= "\n";

    // Add FFI check
    $log_content .= "== FFI Support Check ==\n";
    foreach ($ffi_check as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        }
        $log_content .= "{$key}: {$value}\n";
    }
    $log_content .= "\n";

    // Add permissions check
    $log_content .= "== File Permissions Check ==\n";
    foreach ($permissions_check as $path => $check) {
        $log_content .= "{$path}:\n";
        foreach ($check as $key => $value) {
            $log_content .= "  {$key}: {$value}\n";
        }
    }
    $log_content .= "\n";

    // Add constants check
    $log_content .= "== Constants Check ==\n";
    foreach ($constants_check as $constant => $value) {
        $log_content .= "{$constant}: " . ($value !== null ? $value : 'Not defined') . "\n";
    }
    $log_content .= "\n";

    // Write the full debug info
    $log_content .= "== Full System Information ==\n";
    foreach ($debug_info as $section => $items) {
        $log_content .= "=== {$section} ===\n";
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $log_content .= "{$key}:\n";
                foreach ($value as $subkey => $subvalue) {
                    $log_content .= "  {$subkey}: {$subvalue}\n";
                }
            } else {
                $log_content .= "{$key}: {$value}\n";
            }
        }
        $log_content .= "\n";
    }

    // Write log file
    file_put_contents($log_file, $log_content);

    // Return path to log file
    return $log_file;
}

/**
 * Check ValKey/Redis connection in detail
 */
function gcore_check_valkey_connection() {
    $result = [
        'extension_loaded' => extension_loaded('redis'),
        'connection_test' => false,
        'connection_error' => null,
        'version' => null,
        'diagnostics' => [],
    ];

    if (!$result['extension_loaded']) {
        $result['diagnostics'][] = 'Redis PHP extension is not installed';
        return $result;
    }

    // Try to connect
    try {
        $redis = new \Redis();
        $host = defined('GCORE_VALKEY_HOST') ? GCORE_VALKEY_HOST : '127.0.0.1';
        $port = defined('GCORE_VALKEY_PORT') ? GCORE_VALKEY_PORT : 6379;
        $auth = defined('GCORE_VALKEY_AUTH') ? GCORE_VALKEY_AUTH : null;

        $result['diagnostics'][] = "Attempting connection to {$host}:{$port}";

        $connected = @$redis->connect($host, $port, 2);

        if ($connected) {
            $result['connection_test'] = true;
            $result['diagnostics'][] = "Connection successful";

            // Try authentication if provided
            if ($auth) {
                $result['diagnostics'][] = "Attempting authentication";
                $authResult = $redis->auth($auth);

                if (!$authResult) {
                    $result['diagnostics'][] = "Authentication failed";
                    $result['connection_error'] = "Authentication failed";
                    $result['connection_test'] = false;
                } else {
                    $result['diagnostics'][] = "Authentication successful";
                }
            }

            // If still connected, get version
            if ($result['connection_test']) {
                try {
                    $info = $redis->info();
                    $result['version'] = $info['redis_version'] ?? 'unknown';
                    $result['diagnostics'][] = "ValKey/Redis version: " . $result['version'];

                    // Check if we can set and get a test value
                    $testKey = 'gcore:diagnostics:test';
                    $testValue = 'test_' . time();

                    $redis->setEx($testKey, 30, $testValue);
                    $retrievedValue = $redis->get($testKey);

                    if ($retrievedValue === $testValue) {
                        $result['diagnostics'][] = "Set/Get test successful";
                    } else {
                        $result['diagnostics'][] = "Set/Get test failed: got {$retrievedValue}";
                    }

                    // Clean up
                    $redis->del($testKey);
                } catch (\Throwable $e) {
                    $result['diagnostics'][] = "Error during info command: " . $e->getMessage();
                }
            }

            // Close connection
            $redis->close();
        } else {
            $result['diagnostics'][] = "Connection failed";
            $result['connection_error'] = "Could not connect to {$host}:{$port}";
        }
    } catch (\Throwable $e) {
        $result['diagnostics'][] = "Exception during connection: " . $e->getMessage();
        $result['connection_error'] = $e->getMessage();
    }

    return $result;
}

/**
 * Check FFI support
 */
function gcore_check_ffi_support() {
    $result = [
        'extension_loaded' => extension_loaded('ffi'),
        'enabled_in_php_ini' => false,
        'preload_enabled' => false,
        'can_create_cdata' => false,
        'error' => null,
    ];

    if (!$result['extension_loaded']) {
        $result['error'] = 'FFI extension is not installed';
        return $result;
    }

    // Check if FFI is enabled in php.ini
    $result['enabled_in_php_ini'] = ini_get('ffi.enable') == 1;

    // Check if preload is enabled
    $result['preload_enabled'] = ini_get('opcache.preload') != '';

    // Try to create a simple FFI CData object
    if ($result['enabled_in_php_ini']) {
        try {
            $ffi = \FFI::cdef('int abs(int);');
            $val = $ffi->abs(-42);

            if ($val === 42) {
                $result['can_create_cdata'] = true;
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }
    } else {
        $result['error'] = 'FFI is not enabled in php.ini';
    }

    return $result;
}

/**
 * Check file permissions for critical directories
 */
function gcore_check_permissions() {
    $result = [];

    $base = defined('GCORE_BASE_PATH') ? (string) GCORE_BASE_PATH : '';
    $directories = [
        (defined('GCORE_LOG_PATH') ? GCORE_LOG_PATH : WP_CONTENT_DIR . '/gcore-logs') => 'Logs Directory',
        (defined('GCORE_CACHE_PATH') ? GCORE_CACHE_PATH : WP_CONTENT_DIR . '/gcore-cache') => 'Cache Directory',
        $base                              => 'Framework Directory',
        $base . '/Modules/Core'            => 'Core Modules Directory',
        GCORE_CONFIG_PATH                  => 'Configuration Directory',
    ];

    foreach ($directories as $dir => $label) {
        if (!file_exists($dir)) {
            $result[$dir] = [
                'exists' => false,
                'readable' => false,
                'writable' => false,
                'owner' => 'N/A',
                'permissions' => 'N/A',
                'error' => 'Directory does not exist'
            ];
            continue;
        }

        $result[$dir] = [
            'exists' => true,
            'readable' => is_readable($dir),
            'writable' => is_writable($dir),
            'owner' => function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($dir))['name'] : fileowner($dir),
            'permissions' => substr(sprintf('%o', fileperms($dir)), -4),
        ];

        if (!$result[$dir]['readable']) {
            $result[$dir]['error'] = 'Directory is not readable';
        } elseif (!$result[$dir]['writable']) {
            $result[$dir]['error'] = 'Directory is not writable';
        }
    }

    return $result;
}

/**
 * Check gCore constants — credential values are masked for safe display.
 */
function gcore_check_constants() {
    $constants = [
        'GCORE_VERSION',
        'GCORE_PLUGIN_DIR',
        'GCORE_PLUGIN_URL',
        'GCORE_CONFIG_PATH',
        'GCORE_ASSETS_URL',
        'GCORE_DEBUG',
        'GCORE_DEBUG_LOG',
        'GCORE_DEBUG_DISPLAY',
        'GCORE_VALKEY_HOST',
        'GCORE_VALKEY_PORT',
        'GCORE_VALKEY_AUTH',
        'GCORE_VALKEY_REQUIRED',
        'GCORE_ACTIVATION_MODE',
    ];

    // Constants whose values must never be exposed in full
    $sensitive_constants = [
        'GCORE_VALKEY_AUTH',
    ];

    $result = [];

    foreach ($constants as $constant) {
        if (!defined($constant)) {
            $result[$constant] = null;
            continue;
        }

        $value = constant($constant);

        // Mask sensitive values: show first 3 chars + '***'
        if (in_array($constant, $sensitive_constants, true) && is_string($value) && strlen($value) > 0) {
            $result[$constant] = substr($value, 0, 3) . '***';
        } else {
            $result[$constant] = $value;
        }
    }

    return $result;
}

/**
 * Function to log WordPress plugin information
 */
function gcore_log_wordpress_info() {
    // Create log directory
    $log_dir = defined('GCORE_LOG_PATH') ? GCORE_LOG_PATH : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/gcore-logs' : __DIR__ . '/logs');
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    // Log WordPress information
    $log_file = $log_dir . '/wordpress_info.log';
    $debug_info = gcore_debug_wordpress();

    $log_content = "gCore WordPress Information\n";
    $log_content .= "Date: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($debug_info as $section => $items) {
        $log_content .= "=== {$section} ===\n";
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                $log_content .= "{$key}:\n";
                foreach ($value as $subkey => $subvalue) {
                    if (is_array($subvalue)) {
                        $subvalue = json_encode($subvalue);
                    }
                    $log_content .= "  {$subkey}: {$subvalue}\n";
                }
            } else {
                $log_content .= "{$key}: {$value}\n";
            }
        }
        $log_content .= "\n";
    }

    file_put_contents($log_file, $log_content);

    // Update option to indicate logging has been done
    update_option('gcore_debug_logged', time());

    return $log_file;
}

// Register admin page for diagnostics
function gcore_register_diagnostics_page() {
    add_submenu_page(
        null, // No parent, hidden page
        'gCore Diagnostics',
        'gCore Diagnostics',
        'manage_options',
        'gcore-diagnostics',
        'gcore_diagnostics_page'
    );
}
add_action('admin_menu', 'gcore_register_diagnostics_page');

// Diagnostic page content
function gcore_diagnostics_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $run_diagnostics = isset($_GET['run_diagnostics']);
    $log_file = null;

    if ($run_diagnostics) {
        $log_file = gcore_run_diagnostics();
    }

    echo '<div class="wrap">';
    echo '<h1>gCore Diagnostics</h1>';

    if ($run_diagnostics && $log_file) {
        // Show only relative path in admin UI
        echo '<div class="notice notice-success"><p>Diagnostics completed. Results saved to: <code>' . esc_html(gcore_relative_log_path($log_file)) . '</code></p></div>';

        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
            echo '<h2>Diagnostic Results</h2>';
            echo '<pre style="background: #fff; padding: 15px; border: 1px solid #ccc; overflow: auto; max-height: 500px;">';
            echo esc_html($log_content);
            echo '</pre>';
        }
    }

    echo '<div class="card">';
    echo '<h2>Run System Diagnostics</h2>';
    echo '<p>This tool will check your WordPress environment for compatibility with gCore and generate a detailed report.</p>';
    echo '<p>The diagnostic process checks:</p>';
    echo '<ul style="list-style-type: disc; padding-left: 20px;">';
    echo '<li>PHP configuration and extensions</li>';
    echo '<li>WordPress environment</li>';
    echo '<li>ValKey/Redis connectivity</li>';
    echo '<li>FFI support for geometric topology</li>';
    echo '<li>File permissions</li>';
    echo '<li>System constants</li>';
    echo '</ul>';

    echo '<p><a href="' . esc_url(admin_url('tools.php?page=gcore-diagnostics&run_diagnostics=1')) . '" class="button button-primary">Run Diagnostics</a></p>';
    echo '</div>';

    echo '</div>';
}

// Register hook for error-triggered diagnostics
add_action('admin_init', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'gcore-diagnostics' && isset($_GET['auto_run'])) {
        $log_file = gcore_run_diagnostics();
        wp_redirect(admin_url('tools.php?page=gcore-diagnostics&run_diagnostics=1'));
        exit;
    }
});

/**
 * Perform security validation for gCore operations
 *
 * This is a critical security check that should be used for all administrative
 * operations in the WordPress environment.
 *
 * @param string $capability WordPress capability to check
 * @param string $nonce_action Nonce action name
 * @param string $nonce_field Nonce field name in $_REQUEST
 * @param bool $die Whether to die on failure
 * @return bool True if check passes, false otherwise
 */
function gcore_security_check($capability = 'manage_options', $nonce_action = 'gcore_admin', $nonce_field = 'nonce', $die = true) {
    // Verify user capability
    if (!current_user_can($capability)) {
        if ($die) {
            wp_die(
                __('You do not have sufficient permissions to access this page.', 'gcore'),
                __('gCore - Access Denied', 'gcore'),
                ['response' => 403]
            );
        }
        return false;
    }

    // Verify nonce
    if (!isset($_REQUEST[$nonce_field]) || !wp_verify_nonce($_REQUEST[$nonce_field], $nonce_action)) {
        if ($die) {
            wp_die(
                __('Security check failed. Please reload the page and try again.', 'gcore'),
                __('gCore - Security Error', 'gcore'),
                ['response' => 403]
            );
        }
        return false;
    }

    // Check referrer
    if (!check_admin_referer($nonce_action, $nonce_field, false)) {
        if ($die) {
            wp_die(
                __('Invalid referrer. Security check failed.', 'gcore'),
                __('gCore - Security Error', 'gcore'),
                ['response' => 403]
            );
        }
        return false;
    }

    // All checks passed
    return true;
}

/**
 * Check for gCore requirements and display appropriate notices
 *
 * This function runs checks of all gCore requirements
 * and displays admin notices for any issues found.
 */
function gcore_check_requirements() {
    // Check PHP version
    $php_version = phpversion();
    $php_min_version = '7.2.0';
    $php_recommended_version = '7.4.0';

    if (version_compare($php_version, $php_min_version, '<')) {
        add_action('admin_notices', function() use ($php_version, $php_min_version) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('gCore Error: PHP Version Too Low', 'gcore') . '</strong></p>';
            echo '<p>' . sprintf(__('gCore requires PHP %s or higher. Your current version is %s. Please upgrade your PHP version.', 'gcore'),
                                 $php_min_version, $php_version) . '</p>';
            echo '</div>';
        });
        return false;
    } else if (version_compare($php_version, $php_recommended_version, '<')) {
        add_action('admin_notices', function() use ($php_version, $php_recommended_version) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . __('gCore Warning: PHP Version', 'gcore') . '</strong></p>';
            echo '<p>' . sprintf(__('gCore recommends PHP %s or higher for optimal performance. Your current version is %s.', 'gcore'),
                                 $php_recommended_version, $php_version) . '</p>';
            echo '</div>';
        });
    }

    // Check PHP extensions
    $required_extensions = ['json', 'xml', 'mbstring'];
    $recommended_extensions = ['redis', 'ffi'];
    $missing_required = [];
    $missing_recommended = [];

    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing_required[] = $ext;
        }
    }

    foreach ($recommended_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing_recommended[] = $ext;
        }
    }

    if (!empty($missing_required)) {
        add_action('admin_notices', function() use ($missing_required) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('gCore Error: Missing Required PHP Extensions', 'gcore') . '</strong></p>';
            echo '<p>' . sprintf(__('The following extensions are required by gCore: %s', 'gcore'), implode(', ', $missing_required)) . '</p>';
            echo '</div>';
        });
        return false;
    }

    if (!empty($missing_recommended)) {
        add_action('admin_notices', function() use ($missing_recommended) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . __('gCore Warning: Missing Recommended PHP Extensions', 'gcore') . '</strong></p>';
            echo '<p>' . sprintf(__('The following extensions are recommended for optimal gCore functionality: %s', 'gcore'), implode(', ', $missing_recommended)) . '</p>';
            echo '</div>';
        });
    }

    // Check ValKey/Redis
    if (extension_loaded('redis')) {
        $valkey_connected = false;

        try {
            $redis = new \Redis();
            $host = defined('GCORE_VALKEY_HOST') ? GCORE_VALKEY_HOST : '127.0.0.1';
            $port = defined('GCORE_VALKEY_PORT') ? GCORE_VALKEY_PORT : 6379;
            $valkey_connected = @$redis->connect($host, $port, 1);

            if ($valkey_connected) {
                $redis->close();
            }
        } catch (\Exception $e) {
            // Connection failed, but we'll handle this in the notice
        }

        if (!$valkey_connected) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>' . __('gCore Warning: ValKey/Redis Connection Failed', 'gcore') . '</strong></p>';
                echo '<p>' . __('gCore could not connect to ValKey/Redis. Some features may be unavailable.', 'gcore') . '</p>';
                echo '<p><a href="' . admin_url('admin.php?page=gcore-diagnostics') . '" class="button">' . __('Run Diagnostics', 'gcore') . '</a></p>';
                echo '</div>';
            });
        }
    }

    // Check directory permissions
    $required_dirs = [
        (defined('GCORE_LOG_PATH') ? GCORE_LOG_PATH : WP_CONTENT_DIR . '/gcore-logs') => 'Logs Directory',
        (defined('GCORE_CACHE_PATH') ? GCORE_CACHE_PATH : WP_CONTENT_DIR . '/gcore-cache') => 'Cache Directory'
    ];

    $permission_issues = [];

    foreach ($required_dirs as $dir => $label) {
        // Create directory if it doesn't exist
        if (!file_exists($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                $permission_issues[] = sprintf(__('Could not create %s (%s)', 'gcore'), $label, $dir);
                continue;
            }
        }

        // Check if writable
        if (!is_writable($dir)) {
            $permission_issues[] = sprintf(__('%s is not writable (%s)', 'gcore'), $label, $dir);
        }
    }

    if (!empty($permission_issues)) {
        add_action('admin_notices', function() use ($permission_issues) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . __('gCore Error: Permission Issues', 'gcore') . '</strong></p>';
            echo '<p>' . __('The following directories have permission issues:', 'gcore') . '</p>';
            echo '<ul>';
            foreach ($permission_issues as $issue) {
                echo '<li>' . esc_html($issue) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        });
        return false;
    }

    return true;
}

/**
 * Log a security-related event
 *
 * This function provides a standardized way to log security events
 * across the gCore framework in the WordPress environment.
 *
 * @param string $event_type The type of security event (e.g., 'login_attempt', 'service_toggle')
 * @param array $context Additional context for the event
 * @return bool Whether the event was successfully logged
 */
function gcore_log_security_event($event_type, $context = []) {
    global $wpdb;

    // Ensure we have a WordPress database connection
    if (!isset($wpdb) || !$wpdb) {
        error_log("gCore security event could not be logged (no database connection): {$event_type}");
        return false;
    }

    // Get the current user
    $user_id = get_current_user_id();

    // Get the IP address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Determine severity based on event type
    $severity = 'info';
    $critical_events = [
        'login_failure', 'permission_denied', 'security_check_failed',
        'nonce_verification_failed', 'authentication_failure'
    ];

    $warning_events = [
        'service_toggle', 'configuration_change', 'password_reset',
        'user_role_change', 'plugin_activate', 'plugin_deactivate'
    ];

    if (in_array($event_type, $critical_events)) {
        $severity = 'critical';
    } else if (in_array($event_type, $warning_events)) {
        $severity = 'warning';
    }

    // Create a message from the context
    $message = "Security event: {$event_type}";

    // Insert into the security events table
    $table_name = $wpdb->prefix . 'gcore_security_events';

    // Check if table exists — use $wpdb->prepare() to avoid SQL injection
    $table_exists = $wpdb->get_var(
        $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
    ) === $table_name;

    if ($table_exists) {
        $result = $wpdb->insert(
            $table_name,
            [
                'event_type' => $event_type,
                'severity' => $severity,
                'message' => $message,
                'context' => json_encode($context),
                'ip_address' => $ip_address,
                'user_id' => $user_id,
                'created_at' => current_time('mysql')
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%d', '%s'
            ]
        );

        if ($result === false) {
            error_log("gCore security event database insertion failed: {$wpdb->last_error}");
            return false;
        }
    } else {
        // Fallback to error_log if table doesn't exist
        error_log("gCore security event ({$severity}): {$message} - " . json_encode($context));
    }

    return true;
}

/**
 * Log an error in the gCore system
 *
 * @param string $error_type The type of error
 * @param string $message The error message
 * @param array $context Additional context
 * @return bool Whether the error was successfully logged
 */
function gcore_log_error($error_type, $message, $context = []) {
    // Create log directory if it doesn't exist
    $log_dir = defined('GCORE_LOG_PATH') ? GCORE_LOG_PATH : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/gcore-logs' : __DIR__ . '/logs');
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . '/errors.log';
    $timestamp = date('Y-m-d H:i:s');

    $log_entry = "[{$timestamp}] {$error_type}: {$message}\n";

    if (!empty($context)) {
        $log_entry .= "Context: " . json_encode($context) . "\n";
    }

    $log_entry .= "\n";

    $result = file_put_contents($log_file, $log_entry, FILE_APPEND);

    // Also log to WordPress error log
    error_log("gCore Error ({$error_type}): {$message}");

    return $result !== false;
}

/**
 * Generate a secure nonce field for WordPress admin forms
 *
 * This is a specialized version of wp_nonce_field() that adds additional
 * security features like user binding and IP binding.
 *
 * @param string $action The nonce action
 * @param string $name The nonce name (field name)
 * @param bool $referrer Whether to include the referrer field
 * @param bool $echo Whether to echo the field (true) or return it (false)
 * @return string The nonce field HTML
 */
function gcore_nonce_field($action = 'gcore_action', $name = 'gcore_nonce', $referrer = true, $echo = true) {
    // Generate a base nonce
    $nonce = wp_create_nonce($action);

    // Create a user-specific salt
    $user_id = get_current_user_id();
    $user_salt = $user_id > 0 ? get_user_meta($user_id, 'session_tokens', true) : '';

    // Include a timestamp for expiration
    $timestamp = time();

    // Get IP address for IP binding
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';

    // Create a combined nonce with user binding and IP binding
    $enhanced_nonce = $nonce . '|' . $timestamp . '|' . md5($user_salt . '|' . $ip_address);

    // Create the nonce field
    $field = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($enhanced_nonce) . '" />';

    // Add the referrer field if requested
    if ($referrer) {
        $field .= wp_referer_field(false);
    }

    // Echo or return the field
    if ($echo) {
        echo $field;
    }

    return $field;
}

/**
 * Verify a secure nonce created with gcore_nonce_field()
 *
 * @param string $nonce The nonce to verify
 * @param string $action The nonce action
 * @return bool|int False if the nonce is invalid, 1 if the nonce is valid and fresh, 2 if the nonce is valid but expired
 */
function gcore_verify_nonce($nonce, $action = 'gcore_action') {
    // Split the enhanced nonce into its components
    $parts = explode('|', $nonce);

    // Check if we have all parts
    if (count($parts) !== 3) {
        return false;
    }

    // Extract the components
    list($base_nonce, $timestamp, $user_hash) = $parts;

    // Verify the base nonce
    $result = wp_verify_nonce($base_nonce, $action);

    // If the base nonce is invalid, return false
    if (!$result) {
        return false;
    }

    // Verify the user binding and IP binding
    $user_id = get_current_user_id();
    $user_salt = $user_id > 0 ? get_user_meta($user_id, 'session_tokens', true) : '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $expected_hash = md5($user_salt . '|' . $ip_address);

    // If the user or IP has changed, the nonce is invalid
    if ($user_hash !== $expected_hash) {
        // Log the security event
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('nonce_verification_failed', [
                'action' => $action,
                'expected_user_id' => $user_id,
                'reason' => 'User or IP mismatch',
                'ip_address' => $ip_address
            ]);
        }
        return false;
    }

    // Verify the timestamp (24 hour validity)
    $time_diff = time() - (int)$timestamp;
    $valid_period = DAY_IN_SECONDS; // Adjustable validity period

    // If the timestamp is too old, return 2 (expired)
    if ($time_diff > $valid_period) {
        // Log the security event
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('nonce_expired', [
                'action' => $action,
                'user_id' => $user_id,
                'timestamp' => $timestamp,
                'expiration' => $valid_period,
                'time_diff' => $time_diff
            ]);
        }
        return 2;
    }

    // All checks passed, return 1 (valid and fresh)
    return 1;
}

/**
 * Sanitize and validate data from form submissions
 *
 * This function provides a centralized way to sanitize and validate
 * data from form submissions in the WordPress environment.
 *
 * @param string $type The type of data to sanitize (text, email, url, int, etc.)
 * @param mixed $data The data to sanitize
 * @param array $options Options for validation (min, max, required, etc.)
 * @return mixed The sanitized data or null if validation failed
 */
function gcore_sanitize_input($type, $data, $options = []) {
    // Default options
    $defaults = [
        'required' => false,
        'min' => null,
        'max' => null,
        'pattern' => null,
        'allowed_html' => [],
        'default' => null
    ];

    // Merge options with defaults
    $options = array_merge($defaults, $options);

    // Check if required
    if ($options['required'] && (is_null($data) || $data === '')) {
        return $options['default'];
    }

    // If data is empty and not required, return default
    if (is_null($data) || $data === '') {
        return $options['default'];
    }

    // Sanitize based on type
    switch ($type) {
        case 'text':
            $data = sanitize_text_field($data);
            break;

        case 'textarea':
            $data = sanitize_textarea_field($data);
            break;

        case 'email':
            $data = sanitize_email($data);
            // Validate email
            if (!is_email($data)) {
                return $options['default'];
            }
            break;

        case 'url':
            $data = sanitize_url($data);
            break;

        case 'int':
            $data = (int) $data;
            // Validate min/max
            if (isset($options['min']) && $data < $options['min']) {
                $data = $options['min'];
            }
            if (isset($options['max']) && $data > $options['max']) {
                $data = $options['max'];
            }
            break;

        case 'float':
            $data = (float) $data;
            // Validate min/max
            if (isset($options['min']) && $data < $options['min']) {
                $data = $options['min'];
            }
            if (isset($options['max']) && $data > $options['max']) {
                $data = $options['max'];
            }
            break;

        case 'bool':
            $data = (bool) $data;
            break;

        case 'html':
            // Only allow specified HTML tags
            if (!empty($options['allowed_html'])) {
                $data = wp_kses($data, $options['allowed_html']);
            } else {
                // Use WordPress defaults for allowed HTML
                $data = wp_kses_post($data);
            }
            break;

        case 'color':
            // Validate color code
            if (!preg_match('/^#[a-f0-9]{6}$/i', $data)) {
                return $options['default'];
            }
            break;

        case 'filename':
            // Sanitize filename
            $data = sanitize_file_name($data);
            break;

        case 'key':
            // Sanitize key
            $data = sanitize_key($data);
            break;

        default:
            // For unknown types, just sanitize as text
            $data = sanitize_text_field($data);
            break;
    }

    // Check pattern if provided
    if (isset($options['pattern']) && !preg_match($options['pattern'], $data)) {
        return $options['default'];
    }

    return $data;
}

/**
 * Secure form handler for WordPress admin forms
 *
 * This function provides a standardized way to handle form submissions
 * in the WordPress admin, with built-in security checks and validation.
 *
 * @param string $action The form action
 * @param array $fields Array of field definitions with types and validation options
 * @param callable $callback The callback to handle the form data if validation passes
 * @return mixed The result of the callback or false if validation failed
 */
function gcore_handle_admin_form($action, $fields, $callback) {
    // Check for form submission
    if (!isset($_POST['gcore_form_action']) || $_POST['gcore_form_action'] !== $action) {
        return false;
    }

    // Verify nonce
    if (!isset($_POST['gcore_nonce']) || !gcore_verify_nonce($_POST['gcore_nonce'], $action)) {
        // Log the security event
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('form_nonce_verification_failed', [
                'action' => $action,
                'user_id' => get_current_user_id(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }

        // Add error message
        add_settings_error(
            'gcore_form',
            'nonce_error',
            __('Security check failed. Please try again.', 'gcore'),
            'error'
        );

        return false;
    }

    // Check capability
    $capability = 'manage_options';
    if (isset($fields['_capability'])) {
        $capability = $fields['_capability'];
        unset($fields['_capability']);
    }

    if (!current_user_can($capability)) {
        // Log the security event
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('form_capability_check_failed', [
                'action' => $action,
                'user_id' => get_current_user_id(),
                'capability' => $capability,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }

        // Add error message
        add_settings_error(
            'gcore_form',
            'capability_error',
            __('You do not have permission to perform this action.', 'gcore'),
            'error'
        );

        return false;
    }

    // Sanitize and validate fields
    $sanitized_data = [];
    $validation_errors = [];

    foreach ($fields as $field_name => $field_config) {
        // Skip if field is not required and not present
        if (!isset($_POST[$field_name]) && (!isset($field_config['required']) || !$field_config['required'])) {
            continue;
        }

        // Get field value
        $field_value = $_POST[$field_name] ?? null;

        // Get field type
        $field_type = $field_config['type'] ?? 'text';

        // Sanitize and validate
        $sanitized_value = gcore_sanitize_input($field_type, $field_value, $field_config);

        // Check if validation failed
        if (isset($field_config['required']) && $field_config['required'] && ($sanitized_value === null || $sanitized_value === '')) {
            $validation_errors[$field_name] = sprintf(
                __('The field "%s" is required.', 'gcore'),
                $field_config['label'] ?? $field_name
            );
            continue;
        }

        // Add to sanitized data
        $sanitized_data[$field_name] = $sanitized_value;
    }

    // If there are validation errors, add them to settings errors
    if (!empty($validation_errors)) {
        foreach ($validation_errors as $field_name => $error_message) {
            add_settings_error(
                'gcore_form',
                'validation_error_' . $field_name,
                $error_message,
                'error'
            );
        }

        return false;
    }

    // Log the form submission
    if (function_exists('gcore_log_security_event')) {
        gcore_log_security_event('form_submission', [
            'action' => $action,
            'user_id' => get_current_user_id(),
            'fields' => array_keys($sanitized_data),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    // Call the callback with sanitized data
    return call_user_func($callback, $sanitized_data);
}

/**
 * Create a secure admin form
 *
 * This function generates HTML for a secure admin form that can
 * be processed with gcore_handle_admin_form().
 *
 * @param string $action The form action
 * @param array $fields Array of field definitions
 * @param string $submit_text The text for the submit button
 * @param string $method The form method (post or get)
 * @return string The form HTML
 */
function gcore_admin_form($action, $fields, $submit_text = 'Save Changes', $method = 'post') {
    $output = '<form method="' . esc_attr($method) . '" class="gcore-admin-form">';

    // Add hidden action field
    $output .= '<input type="hidden" name="gcore_form_action" value="' . esc_attr($action) . '" />';

    // Add nonce field
    $output .= gcore_nonce_field($action, 'gcore_nonce', true, false);

    // Add fields
    foreach ($fields as $field_name => $field_config) {
        // Skip special fields
        if ($field_name === '_capability') {
            continue;
        }

        // Get field type
        $field_type = $field_config['type'] ?? 'text';

        // Get field label
        $field_label = $field_config['label'] ?? ucfirst(str_replace('_', ' ', $field_name));

        // Get field description
        $field_description = $field_config['description'] ?? '';

        // Get field value
        $field_value = $field_config['value'] ?? '';

        // Required flag
        $required = isset($field_config['required']) && $field_config['required'] ? 'required' : '';

        // Field container
        $output .= '<div class="gcore-form-field">';

        // Label
        $output .= '<label for="' . esc_attr($field_name) . '">' . esc_html($field_label);
        if ($required) {
            $output .= ' <span class="required">*</span>';
        }
        $output .= '</label>';

        // Field
        switch ($field_type) {
            case 'textarea':
                $output .= '<textarea name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" ' . $required . '>' .
                           esc_textarea($field_value) . '</textarea>';
                break;

            case 'checkbox':
                $checked = !empty($field_value) ? 'checked' : '';
                $output .= '<input type="checkbox" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="1" ' .
                           $checked . ' ' . $required . ' />';
                break;

            case 'select':
                $output .= '<select name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" ' . $required . '>';
                foreach ($field_config['options'] as $option_value => $option_label) {
                    $selected = ($field_value == $option_value) ? 'selected' : '';
                    $output .= '<option value="' . esc_attr($option_value) . '" ' . $selected . '>' .
                               esc_html($option_label) . '</option>';
                }
                $output .= '</select>';
                break;

            case 'radio':
                foreach ($field_config['options'] as $option_value => $option_label) {
                    $checked = ($field_value == $option_value) ? 'checked' : '';
                    $output .= '<label class="radio-label">';
                    $output .= '<input type="radio" name="' . esc_attr($field_name) . '" value="' . esc_attr($option_value) . '" ' .
                               $checked . ' ' . $required . ' />';
                    $output .= esc_html($option_label) . '</label>';
                }
                break;

            case 'color':
                $output .= '<input type="color" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' .
                           esc_attr($field_value) . '" ' . $required . ' />';
                break;

            case 'email':
                $output .= '<input type="email" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' .
                           esc_attr($field_value) . '" ' . $required . ' />';
                break;

            case 'url':
                $output .= '<input type="url" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' .
                           esc_attr($field_value) . '" ' . $required . ' />';
                break;

            case 'number':
                $min = isset($field_config['min']) ? 'min="' . esc_attr($field_config['min']) . '"' : '';
                $max = isset($field_config['max']) ? 'max="' . esc_attr($field_config['max']) . '"' : '';
                $step = isset($field_config['step']) ? 'step="' . esc_attr($field_config['step']) . '"' : '';

                $output .= '<input type="number" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' .
                           esc_attr($field_value) . '" ' . $min . ' ' . $max . ' ' . $step . ' ' . $required . ' />';
                break;

            case 'hidden':
                $output .= '<input type="hidden" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' .
                           esc_attr($field_value) . '" />';
                break;

            case 'text':
            default:
                $output .= '<input type="text" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_name) . '" value="' .
                           esc_attr($field_value) . '" ' . $required . ' />';
                break;
        }

        // Description
        if (!empty($field_description)) {
            $output .= '<p class="description">' . esc_html($field_description) . '</p>';
        }

        $output .= '</div>';
    }

    // Submit button
    $output .= '<p class="submit">';
    $output .= '<input type="submit" name="submit" id="submit" class="button button-primary" value="' . esc_attr($submit_text) . '">';
    $output .= '</p>';

    $output .= '</form>';

    return $output;
}

/**
 * Create a table for security events if it doesn't exist
 *
 * This function should be called during plugin activation to ensure
 * the security events table exists in the WordPress database.
 *
 * @return bool True if table creation was successful or already exists
 */
function gcore_create_security_events_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gcore_security_events';

    // Check if table already exists — use $wpdb->prepare()
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
        return true;
    }

    // Include WordPress database upgrade functions
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // SQL for creating the table
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        event_type varchar(50) NOT NULL,
        severity varchar(20) NOT NULL,
        message text NOT NULL,
        context text,
        ip_address varchar(45) NOT NULL,
        user_id bigint(20) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY event_type (event_type),
        KEY severity (severity),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    // Create the table
    $result = dbDelta($sql);

    // Check if table was created successfully — use $wpdb->prepare()
    return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
}

/**
 * Retrieve security events from the database with filtering
 *
 * @param array $args Query arguments including filters, limits, etc.
 * @return array Array of security events
 */
function gcore_get_security_events($args = []) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'gcore_security_events';

    // Check if table exists — use $wpdb->prepare()
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
        return [];
    }

    // Default arguments
    $defaults = [
        'limit' => 50,
        'offset' => 0,
        'order' => 'DESC',
        'orderby' => 'created_at',
        'event_type' => '',
        'severity' => '',
        'user_id' => '',
        'ip_address' => '',
        'date_from' => '',
        'date_to' => '',
    ];

    // Parse arguments
    $args = wp_parse_args($args, $defaults);

    // Start building query
    $sql = "SELECT * FROM {$table_name} WHERE 1=1";

    // Add filters
    if (!empty($args['event_type'])) {
        $sql .= $wpdb->prepare(" AND event_type = %s", $args['event_type']);
    }

    if (!empty($args['severity'])) {
        $sql .= $wpdb->prepare(" AND severity = %s", $args['severity']);
    }

    if (!empty($args['user_id'])) {
        $sql .= $wpdb->prepare(" AND user_id = %d", $args['user_id']);
    }

    if (!empty($args['ip_address'])) {
        $sql .= $wpdb->prepare(" AND ip_address = %s", $args['ip_address']);
    }

    if (!empty($args['date_from'])) {
        $sql .= $wpdb->prepare(" AND created_at >= %s", $args['date_from']);
    }

    if (!empty($args['date_to'])) {
        $sql .= $wpdb->prepare(" AND created_at <= %s", $args['date_to']);
    }

    // Add order and limit
    $sql .= " ORDER BY " . sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
    $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $args['limit'], $args['offset']);

    // Execute query
    $results = $wpdb->get_results($sql, ARRAY_A);

    // Process results
    foreach ($results as &$event) {
        // Parse context JSON
        if (!empty($event['context'])) {
            $event['context'] = json_decode($event['context'], true);
        } else {
            $event['context'] = [];
        }

        // Add user display name
        if (!empty($event['user_id'])) {
            $user = get_userdata($event['user_id']);
            $event['user_display_name'] = $user ? $user->display_name : __('Unknown User', 'gcore');
        } else {
            $event['user_display_name'] = __('System', 'gcore');
        }
    }

    return $results;
}

/**
 * Check if user has enabled enhanced security features
 *
 * @return bool True if enhanced security is enabled
 */
function gcore_is_enhanced_security_enabled() {
    $settings = get_option('gcore_settings', []);
    $security_level = $settings['security_level'] ?? 'medium';

    return $security_level === 'high';
}

/**
 * Apply security headers for WordPress admin pages
 *
 * This function should be called during admin_init or similar hook
 * to add security headers to WordPress admin pages.
 */
function gcore_apply_security_headers() {
    // Only apply if enhanced security is enabled
    if (!gcore_is_enhanced_security_enabled()) {
        return;
    }

    // Content Security Policy
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'", // Unsafe needed for WordPress admin
        "style-src 'self' 'unsafe-inline'", // Unsafe needed for WordPress admin
        "img-src 'self' data: https:",
        "connect-src 'self'",
        "font-src 'self'",
        "object-src 'none'",
        "media-src 'self'",
        "frame-src 'self'",
        "form-action 'self'",
        "base-uri 'self'",
        "frame-ancestors 'self'"
    ];

    // Apply headers
    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Apply HSTS if on HTTPS
    if (is_ssl()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Generate a random token for single-use authentication
 *
 * @param string $action The action associated with the token
 * @param int $user_id User ID to associate with the token
 * @param int $expiration Token expiration in seconds (default 1 hour)
 * @return string The generated token
 */
function gcore_generate_secure_token($action, $user_id = 0, $expiration = 3600) {
    // Generate a random string
    $token = wp_generate_password(32, false);

    // Store the token in transients with expiration
    $token_data = [
        'user_id' => $user_id ? $user_id : get_current_user_id(),
        'action' => $action,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created' => time()
    ];

    set_transient('gcore_token_' . $token, $token_data, $expiration);

    // Log token creation if enhanced security is enabled
    if (gcore_is_enhanced_security_enabled()) {
        gcore_log_security_event('token_created', [
            'action' => $action,
            'user_id' => $token_data['user_id'],
            'expiration' => $expiration,
            'ip' => $token_data['ip']
        ]);
    }

    return $token;
}

/**
 * Verify a secure token and validate its conditions
 *
 * @param string $token The token to verify
 * @param string $action The expected action associated with the token
 * @param bool $validate_ip Whether to validate the IP address (default true)
 * @param bool $validate_user Whether to validate the user ID (default true)
 * @param bool $delete_after_verify Whether to delete the token after verification (default true)
 * @return bool|array False if invalid, token data if valid
 */
function gcore_verify_secure_token($token, $action, $validate_ip = true, $validate_user = true, $delete_after_verify = true) {
    // Get token data
    $token_data = get_transient('gcore_token_' . $token);

    // Check if token exists
    if (!$token_data) {
        // Log token verification failure
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('token_verification_failed', [
                'action' => $action,
                'reason' => 'Token not found or expired',
                'token' => substr($token, 0, 8) . '...' // Only log part of the token for security
            ]);
        }
        return false;
    }

    // Validate action
    if ($token_data['action'] !== $action) {
        // Log token verification failure
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('token_verification_failed', [
                'action' => $action,
                'expected_action' => $token_data['action'],
                'reason' => 'Action mismatch'
            ]);
        }
        return false;
    }

    // Validate IP address if requested
    if ($validate_ip && ($token_data['ip'] !== ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
        // Log token verification failure
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('token_verification_failed', [
                'action' => $action,
                'expected_ip' => $token_data['ip'],
                'actual_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'reason' => 'IP mismatch'
            ]);
        }
        return false;
    }

    // Validate user if requested
    if ($validate_user && ($token_data['user_id'] !== get_current_user_id())) {
        // Log token verification failure
        if (function_exists('gcore_log_security_event')) {
            gcore_log_security_event('token_verification_failed', [
                'action' => $action,
                'expected_user_id' => $token_data['user_id'],
                'actual_user_id' => get_current_user_id(),
                'reason' => 'User mismatch'
            ]);
        }
        return false;
    }

    // Delete token if requested (for single-use tokens)
    if ($delete_after_verify) {
        delete_transient('gcore_token_' . $token);
    }

    // Log successful token verification
    if (function_exists('gcore_log_security_event')) {
        gcore_log_security_event('token_verified', [
            'action' => $action,
            'user_id' => $token_data['user_id']
        ]);
    }

    return $token_data;
}

/**
 * Secure REST API endpoints based on security configuration
 *
 * This function should be called during rest_authentication_errors filter
 *
 * @param WP_Error|null|bool $result The current authentication status
 * @return WP_Error|null|bool Updated authentication status
 */
function gcore_secure_rest_api($result) {
    // Only modify the result if it's not already a WP_Error
    if (!is_wp_error($result)) {
        // Get security settings
        $settings = get_option('gcore_settings', []);
        $security_level = $settings['security_level'] ?? 'medium';

        // If security level is high, require authentication for all endpoints
        if ($security_level === 'high') {
            // Get current route
            $route = !empty($GLOBALS['wp']->query_vars['rest_route'])
                ? $GLOBALS['wp']->query_vars['rest_route']
                : null;

            // Skip authentication for public endpoints
            $public_endpoints = [
                '/wp/v2/posts',
                '/wp/v2/pages',
                '/wp/v2/categories',
                '/wp/v2/tags'
            ];

            $is_public = false;
            if ($route) {
                foreach ($public_endpoints as $endpoint) {
                    if (strpos($route, $endpoint) === 0 && $_SERVER['REQUEST_METHOD'] === 'GET') {
                        $is_public = true;
                        break;
                    }
                }
            }

            // If not a public endpoint and not authenticated, deny access
            if (!$is_public && !is_user_logged_in()) {
                // Log the unauthorized access
                if (function_exists('gcore_log_security_event')) {
                    gcore_log_security_event('rest_api_unauthorized', [
                        'route' => $route,
                        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                }

                return new WP_Error(
                    'rest_not_logged_in',
                    __('You must be logged in to access this endpoint.', 'gcore'),
                    ['status' => 401]
                );
            }
        }
    }

    return $result;
}

/**
 * Initialize security features
 *
 * This function should be called during plugin initialization to set up
 * all the necessary security hooks.
 */
function gcore_init_security_features() {
    // Create security events table during plugin activation
    register_activation_hook(__FILE__, 'gcore_create_security_events_table');

    // Apply security headers
    add_action('admin_init', 'gcore_apply_security_headers');

    // Secure REST API
    add_filter('rest_authentication_errors', 'gcore_secure_rest_api');

    // Enhance login security
    add_filter('authenticate', 'gcore_enhance_login_security', 30, 3);

    // Log failed login attempts
    add_action('wp_login_failed', 'gcore_log_failed_login');

    // Log successful logins
    add_action('wp_login', 'gcore_log_successful_login', 10, 2);

    // Check user permissions on admin pages
    add_action('admin_init', 'gcore_verify_admin_permissions');
}

/**
 * Enhance login security with additional checks
 *
 * @param WP_User|WP_Error|null $user The user being authenticated
 * @param string $username The username
 * @param string $password The password
 * @return WP_User|WP_Error User if successful, WP_Error if failed
 */
function gcore_enhance_login_security($user, $username, $password) {
    // Only process if high security is enabled and we have a username
    if (!gcore_is_enhanced_security_enabled() || empty($username)) {
        return $user;
    }

    // Get IP address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Check for brute force attempts
    $attempt_count = get_transient('gcore_login_attempts_' . $ip_address);
    $max_attempts = 5;

    if ($attempt_count && $attempt_count >= $max_attempts) {
        // Log the blocked login attempt
        gcore_log_security_event('login_blocked', [
            'username' => $username,
            'ip_address' => $ip_address,
            'reason' => 'Too many failed attempts'
        ]);

        // Return error
        return new WP_Error(
            'too_many_attempts',
            sprintf(
                __('Too many failed login attempts. Please try again in %d minutes.', 'gcore'),
                ceil(get_option('_transient_timeout_gcore_login_attempts_' . $ip_address) - time()) / 60
            )
        );
    }

    // If error occurred during authentication, track the attempt
    if (is_wp_error($user)) {
        $attempt_count = (int)$attempt_count + 1;
        set_transient('gcore_login_attempts_' . $ip_address, $attempt_count, 15 * MINUTE_IN_SECONDS);
    }

    return $user;
}

/**
 * Log failed login attempts
 *
 * @param string $username The username that failed login
 */
function gcore_log_failed_login($username) {
    if (function_exists('gcore_log_security_event')) {
        gcore_log_security_event('login_failure', [
            'username' => $username,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }
}

/**
 * Log successful logins
 *
 * @param string $username The username
 * @param WP_User $user The authenticated user object
 */
function gcore_log_successful_login($username, $user) {
    if (function_exists('gcore_log_security_event')) {
        gcore_log_security_event('login_success', [
            'username' => $username,
            'user_id' => $user->ID,
            'user_role' => reset($user->roles),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }

    // Reset login attempts for this IP
    delete_transient('gcore_login_attempts_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/**
 * Verify user permissions on admin pages
 *
 * This function checks if the current user has appropriate permissions
 * to access the current admin page. If not, they are redirected to the dashboard.
 */
function gcore_verify_admin_permissions() {
    global $pagenow;

    // Only check permissions if enhanced security is enabled
    if (!gcore_is_enhanced_security_enabled()) {
        return;
    }

    // Skip for login, registration, etc.
    $public_pages = ['wp-login.php', 'wp-register.php', 'wp-signup.php'];
    if (in_array($pagenow, $public_pages)) {
        return;
    }

    // Get current screen
    $screen = get_current_screen();

    // Check if page is an gCore admin page
    if ($screen && strpos($screen->id, 'gcore') !== false) {
        $required_capability = 'manage_options';

        // Check for specific capabilities based on page
        if (strpos($screen->id, 'gcore-logs') !== false) {
            $required_capability = 'manage_options';
        } elseif (strpos($screen->id, 'gcore-security') !== false) {
            $required_capability = 'manage_options';
        } elseif (strpos($screen->id, 'gcore-cache') !== false) {
            $required_capability = 'manage_options';
        }

        // Verify capability
        if (!current_user_can($required_capability)) {
            // Log security event
            if (function_exists('gcore_log_security_event')) {
                gcore_log_security_event('admin_access_denied', [
                    'user_id' => get_current_user_id(),
                    'screen' => $screen->id,
                    'required_capability' => $required_capability
                ]);
            }

            // Redirect to dashboard
            wp_redirect(admin_url());
            exit;
        }
    }
}
