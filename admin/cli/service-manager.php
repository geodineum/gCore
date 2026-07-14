<?php
/**
 * gCore Service Manager CLI Tool
 * 
 * This tool provides command-line interface for monitoring and managing gCore services.
 * It allows you to check service status, start/stop services, and view configuration.
 * 
 * Usage:
 *   php service-manager.php [command] [options]
 * 
 * Commands:
 *   status              Show status of all services
 *   start [service]     Start a specific service
 *   stop [service]      Stop a specific service
 *   restart [service]   Restart a specific service
 *   config [service]    Show configuration for a service
 *   list                List all available services
 *   help                Show this help message
 */

// Define constants
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2));
}

if (!defined('GCORE_CONFIG_PATH')) {
    define('GCORE_CONFIG_PATH', ABSPATH . '/config');
}

// Include gCore standalone initialization
require_once ABSPATH . '/gcore-standalone.php';

// Parse command-line arguments
$args = $_SERVER['argv'];
$command = $args[1] ?? 'help';

// CLI Color Helper
class CLIColors {
    const RESET = "\033[0m";
    const GREEN = "\033[32m";
    const RED = "\033[31m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const MAGENTA = "\033[35m";
    const CYAN = "\033[36m";
    const WHITE = "\033[37m";
    const GRAY = "\033[90m";
    
    public static function green($text) {
        return self::GREEN . $text . self::RESET;
    }
    
    public static function red($text) {
        return self::RED . $text . self::RESET;
    }
    
    public static function yellow($text) {
        return self::YELLOW . $text . self::RESET;
    }
    
    public static function blue($text) {
        return self::BLUE . $text . self::RESET;
    }
    
    public static function magenta($text) {
        return self::MAGENTA . $text . self::RESET;
    }
    
    public static function cyan($text) {
        return self::CYAN . $text . self::RESET;
    }
    
    public static function white($text) {
        return self::WHITE . $text . self::RESET;
    }
    
    public static function gray($text) {
        return self::GRAY . $text . self::RESET;
    }
}

/**
 * Show help
 */
function showHelp() {
    echo "\n" . CLIColors::cyan("gCore Service Manager CLI") . "\n";
    echo CLIColors::cyan(str_repeat("=", 24)) . "\n\n";
    echo CLIColors::white("Usage:") . "\n";
    echo "  php service-manager.php [command] [options]\n\n";
    echo CLIColors::white("Commands:") . "\n";
    echo "  " . CLIColors::yellow("status") . "              Show status of all services\n";
    echo "  " . CLIColors::yellow("start [service]") . "     Start a specific service\n";
    echo "  " . CLIColors::yellow("stop [service]") . "      Stop a specific service\n";
    echo "  " . CLIColors::yellow("restart [service]") . "   Restart a specific service\n";
    echo "  " . CLIColors::yellow("config [service]") . "    Show configuration for a service\n";
    echo "  " . CLIColors::yellow("list") . "                List all available services\n";
    echo "  " . CLIColors::yellow("help") . "                Show this help message\n\n";
    echo CLIColors::white("Examples:") . "\n";
    echo "  php service-manager.php status\n";
    echo "  php service-manager.php start CacheManager\n";
    echo "  php service-manager.php config SecurityManager\n";
}

/**
 * Initialize gCore
 */
function initializeGCore() {
    // Default configuration for CLI
    $config = [
        'core' => [
            'environment' => 'development',
            'debug' => true
        ],
        'site_id' => 'cli',
        'node_id' => gethostname() ?: 'cli_node',
        'storage' => [
            'host' => getenv('VALKEY_HOST') ?: null,
            'port' => (int)(getenv('VALKEY_PORT') ?: 0) ?: null,
            'auth' => getenv('VALKEY_AUTH') ?: null,
            'tls' => getenv('VALKEY_TLS') === 'true',
        ]
    ];
    
    try {
        echo "Initializing gCore...\n";
        $gCore = gcore_init($config);
        echo CLIColors::green("✓") . " gCore initialized successfully\n";
        return $gCore;
    } catch (\Exception $e) {
        echo CLIColors::red("✗ Failed to initialize gCore: ") . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Show status of all services
 */
function showStatus($gCore) {
    // Get core status
    $status = $gCore->getStatus();
    $services = $gCore->getServiceRegistry();
    
    echo "\n" . CLIColors::cyan("gCore Status") . "\n";
    echo CLIColors::cyan(str_repeat("=", 12)) . "\n";
    echo CLIColors::white("Environment: ") . ($status['config']['environment'] ?? 'unknown') . "\n";
    echo CLIColors::white("Debug: ") . (($status['config']['debug'] ?? false) ? 'Yes' : 'No') . "\n";
    echo CLIColors::white("Health: ") . ($status['health'] ? CLIColors::green('Healthy') : CLIColors::red('Unhealthy')) . "\n";
    echo CLIColors::white("Version: ") . ($status['version'] ?? 'unknown') . "\n";
    echo CLIColors::white("Uptime: ") . round($status['uptime'] ?? 0, 2) . " seconds\n\n";
    
    // Table header
    echo CLIColors::white(sprintf("%-20s %-12s %-10s %-20s\n", "Service", "State", "Uptime", "Type"));
    echo str_repeat("-", 65) . "\n";
    
    // Table rows
    foreach ($services as $id => $service) {
        $state = $service['state'] ?? 'unknown';
        $stateColor = $state === 'active' ? CLIColors::green($state) : CLIColors::red($state);
        
        $uptime = isset($service['registered_at']) 
            ? round(microtime(true) - $service['registered_at'], 2) . 's'
            : 'N/A';
            
        $type = $service['type'] ?? 'unknown';
        
        echo sprintf("%-20s %-12s %-10s %-20s\n",
            $id,
            $stateColor,
            $uptime,
            $type
        );
    }
    
    echo "\n";
    
    // Metrics header
    echo CLIColors::cyan("System Metrics") . "\n";
    echo CLIColors::cyan(str_repeat("=", 14)) . "\n";
    
    // Metrics
    if (!empty($status['metrics'])) {
        foreach ($status['metrics'] as $metric => $value) {
            echo CLIColors::white(sprintf("%-20s", $metric)) . " $value\n";
        }
    } else {
        echo "No metrics available\n";
    }
    
    echo "\n";
}

/**
 * List all available services
 */
function listServices($gCore) {
    try {
        // Get topology data using reflection
        $reflectionClass = new \ReflectionClass(get_class($gCore));
        $property = $reflectionClass->getProperty('serviceTopology');
        $property->setAccessible(true);
        $serviceTopology = $property->getValue($gCore);
        
        // Get registry data for status
        $registry = $gCore->getServiceRegistry();
        
        echo "\n" . CLIColors::cyan("Available Services") . "\n";
        echo CLIColors::cyan(str_repeat("=", 18)) . "\n\n";
        
        foreach ($serviceTopology as $category => $services) {
            if ($category === 'version' || !is_array($services)) {
                continue;
            }
            
            echo CLIColors::white("Category: ") . CLIColors::magenta($category) . "\n";
            echo str_repeat("-", strlen($category) + 10) . "\n";
            
            foreach ($services as $id => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                
                $active = isset($registry[$id]) && ($registry[$id]['state'] ?? '') === 'active';
                $required = isset($meta['required']) && $meta['required'];
                $class = $meta['class'] ?? 'unknown';
                
                echo sprintf("  %-20s [%s] %s\n",
                    $id,
                    $active ? CLIColors::green('✓ active') : CLIColors::red('✗ inactive'),
                    $required ? CLIColors::yellow('[required]') : ''
                );
                
                if ($class !== 'unknown') {
                    echo "    " . CLIColors::gray("Class: $class") . "\n";
                }
                
                if (isset($meta['capabilities']) && is_array($meta['capabilities'])) {
                    echo "    " . CLIColors::gray("Capabilities: " . implode(', ', $meta['capabilities'])) . "\n";
                }
                
                echo "\n";
            }
        }
    } catch (\Exception $e) {
        echo CLIColors::red("Error fetching service list: ") . $e->getMessage() . "\n";
    }
}

/**
 * Start a service
 */
function startService($gCore, $serviceName) {
    if (!$serviceName) {
        echo CLIColors::red("Error: Service name is required") . "\n";
        echo "Usage: php service-manager.php start [service_name]\n";
        return;
    }
    
    try {
        // Check if service already active
        if ($gCore->isServiceActive($serviceName)) {
            echo CLIColors::yellow("Service '$serviceName' is already active") . "\n";
            return;
        }
        
        // Use reflection to call the initializeService method (it's private)
        $reflectionClass = new \ReflectionClass(get_class($gCore));
        $method = $reflectionClass->getMethod('initializeService');
        $method->setAccessible(true);
        $method->invoke($gCore, $serviceName);
        
        echo CLIColors::green("✓ Service '$serviceName' started successfully") . "\n";
    } catch (\Exception $e) {
        echo CLIColors::red("✗ Failed to start service '$serviceName': ") . $e->getMessage() . "\n";
    }
}

/**
 * Stop a service
 */
function stopService($gCore, $serviceName) {
    if (!$serviceName) {
        echo CLIColors::red("Error: Service name is required") . "\n";
        echo "Usage: php service-manager.php stop [service_name]\n";
        return;
    }
    
    try {
        // Check if service is active
        if (!$gCore->isServiceActive($serviceName)) {
            echo CLIColors::yellow("Service '$serviceName' is not active") . "\n";
            return;
        }
        
        $result = $gCore->stopService($serviceName);
        
        if ($result) {
            echo CLIColors::green("✓ Service '$serviceName' stopped successfully") . "\n";
        } else {
            echo CLIColors::red("✗ Failed to stop service '$serviceName'") . "\n";
        }
    } catch (\Exception $e) {
        echo CLIColors::red("✗ Error stopping service '$serviceName': ") . $e->getMessage() . "\n";
    }
}

/**
 * Restart a service
 */
function restartService($gCore, $serviceName) {
    if (!$serviceName) {
        echo CLIColors::red("Error: Service name is required") . "\n";
        echo "Usage: php service-manager.php restart [service_name]\n";
        return;
    }
    
    echo "Restarting service '$serviceName'...\n";
    
    // Stop if active
    if ($gCore->isServiceActive($serviceName)) {
        stopService($gCore, $serviceName);
    }
    
    // Start
    startService($gCore, $serviceName);
}

/**
 * Show service configuration
 */
function showServiceConfig($gCore, $serviceName) {
    if (!$serviceName) {
        echo CLIColors::red("Error: Service name is required") . "\n";
        echo "Usage: php service-manager.php config [service_name]\n";
        return;
    }
    
    try {
        // Check if service exists
        if (!$gCore->hasService($serviceName)) {
            echo CLIColors::red("Error: Service '$serviceName' not found") . "\n";
            return;
        }
        
        // Get service instance
        $instance = $gCore->getService($serviceName);
        
        // Check if the service has a getConfig method
        if (!method_exists($instance, 'getConfig')) {
            echo CLIColors::yellow("Service '$serviceName' does not expose configuration") . "\n";
            return;
        }
        
        $config = $instance->getConfig();
        
        if (empty($config)) {
            echo CLIColors::yellow("Service '$serviceName' has no configuration") . "\n";
            return;
        }
        
        echo "\n" . CLIColors::cyan("Configuration for $serviceName") . "\n";
        echo CLIColors::cyan(str_repeat("=", 23 + strlen($serviceName))) . "\n\n";
        
        // Print config recursively
        printConfigRecursive($config);
        
    } catch (\Exception $e) {
        echo CLIColors::red("Error fetching configuration: ") . $e->getMessage() . "\n";
    }
}

/**
 * Print configuration recursively with indentation
 */
function printConfigRecursive($config, $prefix = '', $indent = 0) {
    $indentation = str_repeat('  ', $indent);
    
    foreach ($config as $key => $value) {
        $fullKey = $prefix ? "$prefix.$key" : $key;
        
        if (is_array($value) && !isAssociativeArray($value)) {
            // List array
            echo $indentation . CLIColors::white($key) . ": [" . implode(', ', $value) . "]\n";
        } elseif (is_array($value)) {
            // Nested object
            echo $indentation . CLIColors::white($key) . ":\n";
            printConfigRecursive($value, $fullKey, $indent + 1);
        } else {
            // Simple value
            if (is_bool($value)) {
                $formatted = $value ? CLIColors::green('true') : CLIColors::red('false');
            } elseif (is_null($value)) {
                $formatted = CLIColors::gray('null');
            } else {
                $formatted = $value;
            }
            
            echo $indentation . CLIColors::white($key) . ": $formatted\n";
        }
    }
}

/**
 * Check if an array is associative
 */
function isAssociativeArray($array) {
    return array_keys($array) !== range(0, count($array) - 1);
}

// Execute command
switch ($command) {
    case 'status':
        $gCore = initializeGCore();
        showStatus($gCore);
        break;
        
    case 'list':
        $gCore = initializeGCore();
        listServices($gCore);
        break;
        
    case 'start':
        $serviceName = $args[2] ?? null;
        $gCore = initializeGCore();
        startService($gCore, $serviceName);
        break;
        
    case 'stop':
        $serviceName = $args[2] ?? null;
        $gCore = initializeGCore();
        stopService($gCore, $serviceName);
        break;
        
    case 'restart':
        $serviceName = $args[2] ?? null;
        $gCore = initializeGCore();
        restartService($gCore, $serviceName);
        break;
        
    case 'config':
        $serviceName = $args[2] ?? null;
        $gCore = initializeGCore();
        showServiceConfig($gCore, $serviceName);
        break;
        
    case 'help':
    default:
        showHelp();
        break;
}