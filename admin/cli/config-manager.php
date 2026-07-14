<?php
/**
 * gCore Configuration Manager CLI Tool
 * 
 * This tool provides command-line interface for managing gCore configurations.
 * It allows you to view, edit, and validate configuration files.
 * 
 * Usage:
 *   php config-manager.php [command] [options]
 * 
 * Commands:
 *   view [file]            View a configuration file
 *   edit [file] [key] [value]  Edit a configuration value
 *   validate [file]        Validate a configuration file 
 *   list                   List all configuration files
 *   help                   Show this help message
 */

// Define constants
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2));
}

if (!defined('GCORE_CONFIG_PATH')) {
    define('GCORE_CONFIG_PATH', ABSPATH . '/config');
}

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
    echo "\n" . CLIColors::cyan("gCore Configuration Manager CLI") . "\n";
    echo CLIColors::cyan(str_repeat("=", 31)) . "\n\n";
    echo CLIColors::white("Usage:") . "\n";
    echo "  php config-manager.php [command] [options]\n\n";
    echo CLIColors::white("Commands:") . "\n";
    echo "  " . CLIColors::yellow("view [file]") . "            View a configuration file\n";
    echo "  " . CLIColors::yellow("edit [file] [key] [value]") . "  Edit a configuration value\n";
    echo "  " . CLIColors::yellow("validate [file]") . "        Validate a configuration file\n";
    echo "  " . CLIColors::yellow("list") . "                   List all configuration files\n";
    echo "  " . CLIColors::yellow("help") . "                   Show this help message\n\n";
    echo CLIColors::white("Examples:") . "\n";
    echo "  php config-manager.php view default_config.yaml\n";
    echo "  php config-manager.php edit default_config.yaml core.debug true\n";
    echo "  php config-manager.php validate service_topology.yaml\n";
}

/**
 * Load a YAML file
 */
function loadYamlFile($filePath) {
    // Create full path if not already absolute
    if (strpos($filePath, '/') !== 0) {
        $filePath = GCORE_CONFIG_PATH . '/' . $filePath;
    }
    
    // Check if file exists
    if (!file_exists($filePath)) {
        echo CLIColors::red("Error: File not found: ") . $filePath . "\n";
        return null;
    }
    
    // Try to use Symfony YAML if available
    if (class_exists('\\Symfony\\Component\\Yaml\\Yaml')) {
        try {
            return \Symfony\Component\Yaml\Yaml::parseFile($filePath);
        } catch (\Exception $e) {
            echo CLIColors::red("Error parsing YAML: ") . $e->getMessage() . "\n";
            return null;
        }
    }
    
    // Try to use ConfigLoader if available
    if (class_exists('\\gCore\\Modules\\Core\\Utils\\ConfigLoader')) {
        try {
            $loader = new \gCore\Modules\Core\Utils\ConfigLoader();
            return $loader->load($filePath);
        } catch (\Exception $e) {
            echo CLIColors::red("Error using ConfigLoader: ") . $e->getMessage() . "\n";
            return null;
        }
    }
    
    // Fallback to displaying file contents
    echo CLIColors::yellow("Warning: YAML parser not available. Showing raw file content.\n\n");
    return file_get_contents($filePath);
}

/**
 * Save a YAML file
 */
function saveYamlFile($filePath, $data) {
    // Create full path if not already absolute
    if (strpos($filePath, '/') !== 0) {
        $filePath = GCORE_CONFIG_PATH . '/' . $filePath;
    }
    
    // Try to use Symfony YAML if available
    if (class_exists('\\Symfony\\Component\\Yaml\\Yaml')) {
        try {
            $yaml = \Symfony\Component\Yaml\Yaml::dump($data, 4, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            file_put_contents($filePath, $yaml);
            return true;
        } catch (\Exception $e) {
            echo CLIColors::red("Error generating YAML: ") . $e->getMessage() . "\n";
            return false;
        }
    }
    
    // Otherwise, warn that we can't save
    echo CLIColors::red("Error: YAML parser not available for saving changes.\n");
    return false;
}

/**
 * List all configuration files
 */
function listConfigFiles() {
    echo "\n" . CLIColors::cyan("Configuration Files") . "\n";
    echo CLIColors::cyan(str_repeat("=", 19)) . "\n\n";
    
    // Get all YAML files
    $files = glob(GCORE_CONFIG_PATH . '/*.yaml');
    
    // Add subdirectories
    $subdirs = glob(GCORE_CONFIG_PATH . '/*', GLOB_ONLYDIR);
    foreach ($subdirs as $dir) {
        $subfiles = glob($dir . '/*.yaml');
        $files = array_merge($files, $subfiles);
    }
    
    if (empty($files)) {
        echo CLIColors::yellow("No configuration files found in ") . GCORE_CONFIG_PATH . "\n";
        return;
    }
    
    // Group files by directory
    $groupedFiles = [];
    foreach ($files as $file) {
        $dir = dirname($file);
        $filename = basename($file);
        
        if (!isset($groupedFiles[$dir])) {
            $groupedFiles[$dir] = [];
        }
        
        $groupedFiles[$dir][] = $filename;
    }
    
    // Print grouped files
    foreach ($groupedFiles as $dir => $dirFiles) {
        $relativePath = str_replace(GCORE_CONFIG_PATH . '/', '', $dir);
        if ($relativePath === GCORE_CONFIG_PATH) {
            $relativePath = '(root)';
        }
        
        echo CLIColors::white("Directory: ") . CLIColors::magenta($relativePath) . "\n";
        echo str_repeat("-", strlen($relativePath) + 12) . "\n";
        
        foreach ($dirFiles as $file) {
            $path = $dir === GCORE_CONFIG_PATH ? $file : ($relativePath . '/' . $file);
            $size = filesize($dir . '/' . $file);
            $modified = date('Y-m-d H:i:s', filemtime($dir . '/' . $file));
            
            echo sprintf("  %-30s %8s bytes   Modified: %s\n",
                $path,
                number_format($size),
                $modified
            );
        }
        
        echo "\n";
    }
}

/**
 * View a configuration file
 */
function viewConfigFile($fileName) {
    if (!$fileName) {
        echo CLIColors::red("Error: File name is required") . "\n";
        echo "Usage: php config-manager.php view [file]\n";
        return;
    }
    
    // Create full path if not already absolute
    if (strpos($fileName, '/') !== 0) {
        $filePath = GCORE_CONFIG_PATH . '/' . $fileName;
    } else {
        $filePath = $fileName;
    }
    
    // Check if file exists
    if (!file_exists($filePath)) {
        echo CLIColors::red("Error: File not found: ") . $filePath . "\n";
        return;
    }
    
    echo "\n" . CLIColors::cyan("Configuration File: ") . basename($filePath) . "\n";
    echo CLIColors::cyan(str_repeat("=", 20 + strlen(basename($filePath)))) . "\n\n";
    
    $data = loadYamlFile($filePath);
    
    if (is_string($data)) {
        // Raw file content
        echo $data . "\n";
    } else if (is_array($data)) {
        // Parsed YAML
        printConfigRecursive($data);
    }
}

/**
 * Edit a configuration value
 */
function editConfigValue($fileName, $key, $value) {
    if (!$fileName || !$key || $value === null) {
        echo CLIColors::red("Error: File name, key, and value are required") . "\n";
        echo "Usage: php config-manager.php edit [file] [key] [value]\n";
        return;
    }
    
    // Load the configuration
    $data = loadYamlFile($fileName);
    
    if (!is_array($data)) {
        echo CLIColors::red("Error: Could not parse configuration file as YAML") . "\n";
        return;
    }
    
    // Parse key path (e.g. "core.debug")
    $keyParts = explode('.', $key);
    
    // Set value in nested structure
    $reference = &$data;
    $lastKey = array_pop($keyParts);
    
    foreach ($keyParts as $part) {
        if (!isset($reference[$part]) || !is_array($reference[$part])) {
            $reference[$part] = [];
        }
        $reference = &$reference[$part];
    }
    
    // Convert value to appropriate type
    if ($value === 'true') {
        $value = true;
    } elseif ($value === 'false') {
        $value = false;
    } elseif ($value === 'null') {
        $value = null;
    } elseif (is_numeric($value)) {
        if (strpos($value, '.') !== false) {
            $value = (float) $value;
        } else {
            $value = (int) $value;
        }
    }
    
    // Store old value for display
    $oldValue = isset($reference[$lastKey]) ? $reference[$lastKey] : null;
    
    // Set the value
    $reference[$lastKey] = $value;
    
    // Save the file
    if (saveYamlFile($fileName, $data)) {
        echo CLIColors::green("✓ Configuration updated successfully") . "\n";
        echo "File: " . $fileName . "\n";
        echo "Key: " . $key . "\n";
        
        if (is_bool($oldValue)) {
            $formattedOld = $oldValue ? 'true' : 'false';
        } elseif (is_null($oldValue)) {
            $formattedOld = 'null';
        } else {
            $formattedOld = $oldValue;
        }
        
        if (is_bool($value)) {
            $formattedNew = $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            $formattedNew = 'null';
        } else {
            $formattedNew = $value;
        }
        
        echo "Old value: " . $formattedOld . "\n";
        echo "New value: " . $formattedNew . "\n";
    }
}

/**
 * Validate a configuration file
 */
function validateConfigFile($fileName) {
    if (!$fileName) {
        echo CLIColors::red("Error: File name is required") . "\n";
        echo "Usage: php config-manager.php validate [file]\n";
        return;
    }
    
    echo "\n" . CLIColors::cyan("Validating Configuration: ") . $fileName . "\n";
    echo CLIColors::cyan(str_repeat("=", 26 + strlen($fileName))) . "\n\n";
    
    // Check if SchemaRegistry exists
    if (class_exists('\\gCore\\Modules\\Core\\Utils\\SchemaRegistry')) {
        try {
            // Load the file
            $data = loadYamlFile($fileName);
            
            // Determine schema based on filename
            $schemaName = pathinfo($fileName, PATHINFO_FILENAME);
            
            // Create SchemaRegistry
            $registry = new \gCore\Modules\Core\Utils\SchemaRegistry();
            
            echo "Validating against schema: " . $schemaName . "\n\n";
            
            // Validate
            $result = $registry->validate($data, $schemaName);
            
            if ($result === true) {
                echo CLIColors::green("✓ Configuration is valid!") . "\n";
            } else {
                echo CLIColors::red("✗ Validation failed:") . "\n\n";
                
                foreach ($result as $error) {
                    echo "- " . CLIColors::red($error['property']) . ": " . $error['message'] . "\n";
                }
            }
        } catch (\Exception $e) {
            echo CLIColors::red("Error validating configuration: ") . $e->getMessage() . "\n";
        }
    } else {
        // Just try to parse the YAML
        $data = loadYamlFile($fileName);
        
        if (is_array($data)) {
            echo CLIColors::yellow("⚠ Limited validation: SchemaRegistry not available") . "\n";
            echo CLIColors::green("✓ File parsed as valid YAML") . "\n";
            
            // Show file structure summary
            echo "\nFile structure summary:\n";
            $this->summarizeStructure($data);
        } else {
            echo CLIColors::red("✗ File could not be parsed as valid YAML") . "\n";
        }
    }
}

/**
 * Summarize configuration structure
 */
function summarizeStructure($data, $prefix = '', $level = 0) {
    $indentation = str_repeat('  ', $level);
    
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $count = count($value);
            $type = is_associative_array($value) ? 'object' : 'array';
            
            echo $indentation . CLIColors::white($key) . ": " . 
                 CLIColors::gray("($type with $count items)") . "\n";
                 
            if ($level < 2) { // Only go 3 levels deep
                summarizeStructure($value, "$prefix.$key", $level + 1);
            }
        } else {
            $type = gettype($value);
            if (is_string($value) && strlen($value) > 30) {
                $value = substr($value, 0, 27) . '...';
            }
            
            if (is_bool($value)) {
                $formatted = $value ? 'true' : 'false';
            } elseif (is_null($value)) {
                $formatted = 'null';
            } else {
                $formatted = (string) $value;
            }
            
            echo $indentation . CLIColors::white($key) . ": " . 
                 CLIColors::gray("($type)") . " $formatted\n";
        }
    }
}

/**
 * Print configuration recursively with indentation
 */
function printConfigRecursive($config, $indent = 0) {
    $indentation = str_repeat('  ', $indent);
    
    foreach ($config as $key => $value) {
        if (is_array($value) && !is_associative_array($value)) {
            // List array
            echo $indentation . CLIColors::white($key) . ": [" . implode(', ', $value) . "]\n";
        } elseif (is_array($value)) {
            // Nested object
            echo $indentation . CLIColors::white($key) . ":\n";
            printConfigRecursive($value, $indent + 1);
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
function is_associative_array($array) {
    if (!is_array($array)) return false;
    return array_keys($array) !== range(0, count($array) - 1);
}

// Execute command
switch ($command) {
    case 'list':
        listConfigFiles();
        break;
        
    case 'view':
        $fileName = $args[2] ?? null;
        viewConfigFile($fileName);
        break;
        
    case 'edit':
        $fileName = $args[2] ?? null;
        $key = $args[3] ?? null;
        $value = $args[4] ?? null;
        editConfigValue($fileName, $key, $value);
        break;
        
    case 'validate':
        $fileName = $args[2] ?? null;
        validateConfigFile($fileName);
        break;
        
    case 'help':
    default:
        showHelp();
        break;
}