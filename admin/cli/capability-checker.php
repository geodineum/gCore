<?php
/**
 * gCore Capability Checker
 * 
 * Analyzes PHP code to identify which gCore capabilities, managers, and traits are being used.
 * Generates an optimized configuration with only the required components.
 */

namespace gCore\Admin\CLI;

// Bootstrap for direct execution
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    // Get the gCore root directory
    $gCoreRoot = dirname(dirname(__DIR__));
    
    // Include the necessary files
    require_once $gCoreRoot . '/bootstrap.php';
    
    // Parse command line arguments
    $args = $argv;
    array_shift($args); // Remove script name
    
    // Create and run the capability checker
    $checker = new CapabilityChecker();
    $checker->run($args);
    exit;
}

class CapabilityChecker {
    /** @var string gCore root path */
    private $gCoreRoot;
    
    /** @var array Command line arguments */
    private $args = [];
    
    /** @var bool Whether to scan recursively */
    private $recursive = false;
    
    /** @var bool Whether to show verbose output */
    private $verbose = false;
    
    /** @var string|null Output configuration file */
    private $outputConfig = null;
    
    /** @var array Found capabilities */
    private $foundCapabilities = [];
    
    /** @var array Found traits */
    private $foundTraits = [];
    
    /** @var array Found managers */
    private $foundManagers = [];
    
    /** @var array Found function calls */
    private $foundFunctions = [];
    
    /** @var array Known gCore managers */
    private $knownManagers = [
        'SecurityManager',
        'CacheManager',
        'ErrorManager',
        'APIManager',
        'ManifestManager',
        'CookieManager',
        'ResourceManager',
        'StateManager',
        'MetricsManager'
    ];
    
    /** @var array Known capability patterns */
    private $capabilityPatterns = [
        'hasCapability',
        'requireCapability',
        'checkCapability'
    ];
    
    /** @var array Known topology functions */
    private $topologyFunctions = [
        'geometric_discover',
        'geometric_register',
        'discover_services'
    ];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->gCoreRoot = dirname(dirname(__DIR__));
    }
    
    /**
     * Run the capability checker
     * 
     * @param array $args Command line arguments
     */
    public function run(array $args = []): void {
        // Parse arguments
        $this->parseArguments($args);
        
        // Check if we have a target path
        if (empty($this->args)) {
            $this->showHelp();
            return;
        }
        
        $path = $this->args[0];
        
        // Check if path exists
        if (!file_exists($path)) {
            echo "❌ Error: Path not found: $path\n";
            return;
        }
        
        echo "Scanning for capability usage in: $path" . ($this->recursive ? " (recursive)" : "") . "\n";
        
        // Scan the path
        $phpFiles = $this->findPhpFiles($path, $this->recursive);
        
        echo "Found " . count($phpFiles) . " PHP files to scan.\n\n";
        
        // Process each file
        foreach ($phpFiles as $file) {
            if ($this->verbose) {
                echo "Scanning: $file\n";
            }
            
            $this->scanFile($file);
        }
        
        // Show results
        $this->showResults();
        
        // Generate configuration if requested
        if ($this->outputConfig) {
            $this->generateConfig();
        }
    }
    
    /**
     * Parse command line arguments
     * 
     * @param array $args Command line arguments
     */
    private function parseArguments(array $args): void {
        // Process options
        for ($i = 0; $i < count($args); $i++) {
            if ($args[$i] === '--recursive' || $args[$i] === '-r') {
                $this->recursive = true;
            } elseif ($args[$i] === '--verbose' || $args[$i] === '-v') {
                $this->verbose = true;
            } elseif (strpos($args[$i], '--output-config=') === 0) {
                $this->outputConfig = substr($args[$i], 16);
            } elseif (strpos($args[$i], '--help') === 0 || strpos($args[$i], '-h') === 0) {
                $this->showHelp();
                exit;
            } else {
                $this->args[] = $args[$i];
            }
        }
    }
    
    /**
     * Show help information
     */
    private function showHelp(): void {
        echo "gCore Capability Checker\n";
        echo "=====================\n\n";
        echo "Analyzes PHP code to identify which gCore capabilities, managers, and traits are being used.\n";
        echo "Generates an optimized configuration with only the required components.\n\n";
        echo "Usage: php capability-checker.php [path] [options]\n\n";
        echo "Options:\n";
        echo "  --recursive, -r       Scan subdirectories recursively\n";
        echo "  --verbose, -v         Show detailed scan information\n";
        echo "  --output-config=PATH  Generate minimal configuration file\n";
        echo "  --help, -h            Show this help information\n\n";
        echo "Examples:\n";
        echo "  php capability-checker.php /path/to/project --recursive --output-config=config/minimal.yaml\n";
        echo "  php capability-checker.php /path/to/file.php --verbose\n";
    }
    
    /**
     * Find PHP files in a directory
     * 
     * @param string $path Directory or file path
     * @param bool $recursive Whether to scan recursively
     * @return array List of PHP files
     */
    private function findPhpFiles(string $path, bool $recursive): array {
        $files = [];
        
        if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            $files[] = $path;
        } elseif (is_dir($path)) {
            $items = scandir($path);
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                
                $itemPath = $path . '/' . $item;
                
                if (is_file($itemPath) && pathinfo($itemPath, PATHINFO_EXTENSION) === 'php') {
                    $files[] = $itemPath;
                } elseif ($recursive && is_dir($itemPath)) {
                    $files = array_merge($files, $this->findPhpFiles($itemPath, true));
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Scan a PHP file for capability usage
     * 
     * @param string $file File path
     */
    private function scanFile(string $file): void {
        $content = file_get_contents($file);
        
        if ($content === false) {
            if ($this->verbose) {
                echo "❌ Could not read file: $file\n";
            }
            return;
        }
        
        // First, do some regex-based checks for common patterns
        $this->scanForManagerReferences($content, $file);
        
        // Use token_get_all to parse PHP code
        $tokens = token_get_all($content);
        
        for ($i = 0; $i < count($tokens); $i++) {
            // Skip non-token entries
            if (!is_array($tokens[$i])) {
                continue;
            }
            
            // Check for namespace and use statements
            if ($tokens[$i][0] === T_USE) {
                $this->processUseStatement($tokens, $i);
            }
            
            // Check for capability functions
            if ($tokens[$i][0] === T_STRING) {
                $this->processString($tokens, $i);
            }
            
            // Check for variable assignments with manager types
            if ($tokens[$i][0] === T_VARIABLE && $i > 2 && isset($tokens[$i-2][0]) && $tokens[$i-2][0] === T_STRING) {
                $this->processVariableAssignment($tokens, $i);
            }
        }
    }
    
    /**
     * Scan for manager references using regex patterns
     * 
     * @param string $content File content
     * @param string $file File path for logging
     */
    private function scanForManagerReferences(string $content, string $file): void {
        // Look for getService calls
        if (preg_match_all('/getService\([\'"]([a-zA-Z]+)[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $service) {
                foreach ($this->knownManagers as $manager) {
                    if ($service === $manager) {
                        if ($this->verbose) {
                            echo "  Found manager reference: $manager (getService)\n";
                        }
                        $this->foundManagers[$manager] = $manager;
                    }
                }
            }
        }
        
        // Look for helper functions like gcore_get_*_manager()
        if (preg_match_all('/gcore_get_([a-z_]+)_manager/', $content, $matches)) {
            foreach ($matches[1] as $managerType) {
                $managerName = ucfirst($managerType) . 'Manager';
                if (in_array($managerName, $this->knownManagers)) {
                    if ($this->verbose) {
                        echo "  Found manager reference: $managerName (helper function)\n";
                    }
                    $this->foundManagers[$managerName] = $managerName;
                }
            }
        }
        
        // Look for class type hints
        foreach ($this->knownManagers as $manager) {
            $pattern = '/\\\\' . $manager . '(?:\s+|\$|\))/';
            if (preg_match($pattern, $content)) {
                if ($this->verbose) {
                    echo "  Found manager reference: $manager (type hint)\n";
                }
                $this->foundManagers[$manager] = $manager;
            }
        }
    }
    
    /**
     * Process a variable assignment that might involve a manager
     * 
     * @param array $tokens Token array
     * @param int $index Current index
     */
    private function processVariableAssignment(array $tokens, int $index): void {
        // Check if this might be a type hint
        $potentialType = $tokens[$index-2][1];
        
        foreach ($this->knownManagers as $manager) {
            if ($potentialType === $manager) {
                if ($this->verbose) {
                    echo "  Found manager reference: $manager (variable type hint)\n";
                }
                $this->foundManagers[$manager] = $manager;
            }
        }
    }
    
    /**
     * Process a use statement
     * 
     * @param array $tokens Token array
     * @param int $index Current index
     */
    private function processUseStatement(array $tokens, int &$index): void {
        $useStatement = '';
        $index++;
        
        // Skip whitespace
        while ($index < count($tokens) && is_array($tokens[$index]) && $tokens[$index][0] === T_WHITESPACE) {
            $index++;
        }
        
        // Collect the use statement
        while ($index < count($tokens)) {
            if (is_array($tokens[$index])) {
                // Check for semicolon
                if ($tokens[$index][0] === T_WHITESPACE && strpos($tokens[$index][1], ';') !== false) {
                    break;
                }
                
                $useStatement .= $tokens[$index][1];
            } else {
                // End of use statement
                if ($tokens[$index] === ';') {
                    break;
                }
                
                $useStatement .= $tokens[$index];
            }
            
            $index++;
        }
        
        // Check for gCore traits and managers
        $components = explode('\\', $useStatement);
        $lastComponent = end($components);
        
        // Check for traits
        if (strpos($lastComponent, 'Trait') !== false) {
            $this->foundTraits[$lastComponent] = $lastComponent;
        }
        
        // Check for managers
        foreach ($this->knownManagers as $manager) {
            if (strpos($useStatement, $manager) !== false) {
                $this->foundManagers[$manager] = $manager;
            }
        }
    }
    
    /**
     * Process a string token
     * 
     * @param array $tokens Token array
     * @param int $index Current index
     */
    private function processString(array $tokens, int $index): void {
        $string = $tokens[$index][1];
        
        // Check for capability functions
        foreach ($this->capabilityPatterns as $pattern) {
            if ($string === $pattern) {
                // Look for the capability name in the next tokens
                $this->extractCapabilityName($tokens, $index);
            }
        }
        
        // Check for manager references
        foreach ($this->knownManagers as $manager) {
            if ($string === $manager) {
                $this->foundManagers[$manager] = $manager;
            }
        }
        
        // Check for topology functions
        foreach ($this->topologyFunctions as $function) {
            if ($string === $function) {
                $this->foundFunctions[$function] = $function;
            }
        }
    }
    
    /**
     * Extract capability name from a function call
     * 
     * @param array $tokens Token array
     * @param int $index Current index
     */
    private function extractCapabilityName(array $tokens, int $index): void {
        // Function name found, now look for opening parenthesis and string parameter
        $index++;
        
        // Skip whitespace
        while ($index < count($tokens) && is_array($tokens[$index]) && $tokens[$index][0] === T_WHITESPACE) {
            $index++;
        }
        
        // Check for opening parenthesis
        if ($index < count($tokens) && $tokens[$index] === '(') {
            $index++;
            
            // Skip whitespace
            while ($index < count($tokens) && is_array($tokens[$index]) && $tokens[$index][0] === T_WHITESPACE) {
                $index++;
            }
            
            // Check for string parameter
            if ($index < count($tokens) && is_array($tokens[$index]) && 
                ($tokens[$index][0] === T_CONSTANT_ENCAPSED_STRING || $tokens[$index][0] === T_STRING)) {
                $capability = trim($tokens[$index][1], '\'"');
                $this->foundCapabilities[$capability] = $capability;
            }
        }
    }
    
    /**
     * Show scan results
     */
    private function showResults(): void {
        echo "Capability Analysis Results:\n";
        echo "===========================\n\n";
        
        echo "traits (" . count($this->foundTraits) . "):\n";
        foreach ($this->foundTraits as $trait) {
            echo "  - $trait\n";
        }
        echo "\n";
        
        echo "capabilities (" . count($this->foundCapabilities) . "):\n";
        foreach ($this->foundCapabilities as $capability) {
            echo "  - $capability\n";
        }
        echo "\n";
        
        echo "managers (" . count($this->foundManagers) . "):\n";
        foreach ($this->foundManagers as $manager) {
            echo "  - $manager\n";
        }
        echo "\n";
        
        echo "functions (" . count($this->foundFunctions) . "):\n";
        foreach ($this->foundFunctions as $function) {
            echo "  - $function\n";
        }
        echo "\n";
        
        $total = count($this->foundTraits) + count($this->foundCapabilities) + 
                 count($this->foundManagers) + count($this->foundFunctions);
        
        echo "Total distinct capability indicators found: $total\n\n";
    }
    
    /**
     * Generate minimal configuration file
     */
    private function generateConfig(): void {
        echo "Generating minimal configuration file: {$this->outputConfig}\n";
        
        // Create configuration array
        $config = [
            'core' => [
                'environment' => 'development',
                'debug' => true
            ],
            'services' => []
        ];
        
        // Add required managers
        foreach ($this->foundManagers as $manager) {
            $managerKey = strtolower($manager);
            $config['services'][$managerKey] = [
                'class' => "gCore\\Modules\\Managers\\Base\\$manager\\$manager",
                'enabled' => true
            ];
            
            // Add traits if found
            $traitsForManager = [];
            foreach ($this->foundTraits as $trait) {
                // For now, just associate traits with managers based on naming patterns
                if (strpos($trait, str_replace('Manager', '', $manager)) !== false) {
                    $traitsForManager[] = $trait;
                }
            }
            
            if (!empty($traitsForManager)) {
                $config['services'][$managerKey]['traits'] = $traitsForManager;
            }
        }
        
        // If no managers found but we've found capabilities, add SecurityManager by default
        if (empty($config['services']) && !empty($this->foundCapabilities)) {
            $config['services']['securitymanager'] = [
                'class' => "gCore\\Modules\\Managers\\Base\\SecurityManager\\SecurityManager",
                'enabled' => true
            ];
        }
        
        // Add capabilities
        if (!empty($this->foundCapabilities)) {
            $config['capabilities'] = [];
            foreach ($this->foundCapabilities as $capability) {
                $config['capabilities'][$capability] = true;
            }
        }
        
        // Ensure the directory exists
        $dir = dirname($this->outputConfig);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Generate YAML
        $yaml = "# gCore Minimal Configuration\n";
        $yaml .= "# Generated by capability-checker.php on " . date('Y-m-d H:i:s') . "\n";
        $yaml .= "# This configuration includes only the components detected in your codebase\n\n";
        
        $yaml .= $this->arrayToYaml($config);
        
        // Write to file
        if (file_put_contents($this->outputConfig, $yaml)) {
            echo "✅ Configuration file generated successfully!\n";
            echo "Note: This is a minimal starting configuration. You may need to customize it further.\n";
        } else {
            echo "❌ Error: Could not write configuration file.\n";
        }
    }
    
    /**
     * Convert an array to YAML format
     * 
     * @param array $array Input array
     * @param int $indent Indentation level
     * @return string YAML content
     */
    private function arrayToYaml(array $array, int $indent = 0): string {
        $yaml = '';
        $space = str_repeat('  ', $indent);
        
        foreach ($array as $key => $value) {
            if (is_array($value) && !$this->isSequentialArray($value)) {
                // Associative array
                $yaml .= "$space$key:\n";
                $yaml .= $this->arrayToYaml($value, $indent + 1);
            } elseif (is_array($value)) {
                // Sequential array
                $yaml .= "$space$key:\n";
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $yaml .= "$space  -\n";
                        $yaml .= $this->arrayToYaml($item, $indent + 2);
                    } else {
                        $yaml .= "$space  - $item\n";
                    }
                }
            } else {
                // Scalar value
                if (is_bool($value)) {
                    $yaml .= "$space$key: " . ($value ? 'true' : 'false') . "\n";
                } else {
                    $yaml .= "$space$key: $value\n";
                }
            }
        }
        
        return $yaml;
    }
    
    /**
     * Check if an array is sequential (numeric keys)
     * 
     * @param array $array Input array
     * @return bool True if sequential
     */
    private function isSequentialArray(array $array): bool {
        return array_keys($array) === range(0, count($array) - 1);
    }
}