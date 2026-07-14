<?php
/**
 * gCore Dependency Resolver CLI Tool
 * 
 * This tool helps developers analyze, detect, and fix circular dependencies
 * in the gCore service architecture.
 * 
 * Usage:
 *   php dependency-resolver.php [options]
 * 
 * Options:
 *   --analyze                 Analyze dependencies and report issues
 *   --fix                     Attempt to automatically fix circular dependencies
 *   --generate-graph          Generate dependency graph in DOT format
 *   --config=<path>           Path to config directory (default: ../config)
 *   --export=<path>           Export results to file
 *   --strategy=<strategy>     Resolution strategy (strict|relaxed|auto-fix)
 *   --verbose                 Show detailed output
 *   --help                    Show this help message
 */

// Bootstrap
define('GCORE_ROOT', dirname(__DIR__, 1));
require_once GCORE_ROOT . '/vendor/autoload.php';

use gCore\Modules\Core\Utils\DependencyResolver;
use gCore\Modules\Core\Adapters\Shared\ValKeyStorage;
use gCore\Modules\Core\Exceptions\CircularDependencyException;

// Parse command-line arguments
$options = getopt('', [
    'analyze',
    'fix',
    'generate-graph',
    'config:',
    'export:',
    'strategy:',
    'verbose',
    'help'
]);

// Show help if requested or no options provided
if (isset($options['help']) || empty($options)) {
    echo file_get_contents(__FILE__, false, null, 0, strpos(file_get_contents(__FILE__), '*/') + 2);
    exit(0);
}

// Set defaults
$configDir = $options['config'] ?? GCORE_ROOT . '/config';
$strategy = $options['strategy'] ?? 'strict';
$verbose = isset($options['verbose']);
$exportPath = $options['export'] ?? null;

// Validate strategy
if (!in_array($strategy, ['strict', 'relaxed', 'auto-fix'])) {
    echo "Error: Invalid strategy '{$strategy}'. Valid options are: strict, relaxed, auto-fix\n";
    exit(1);
}

try {
    // Create ValKey connection (will be stubbed if not available)
    $valkey = getValKeyConnection();
    
    // Create resolver
    $resolver = new DependencyResolver($strategy, $valkey);
    
    // Load configuration
    // CONSOLIDATED 2026-02-04: dependencies now embedded in services.yaml
    $servicesConfig = $configDir . '/services.yaml';

    if (!file_exists($servicesConfig)) {
        echo "Error: Services configuration not found at {$servicesConfig}\n";
        exit(1);
    }

    // Dependencies are now in services.yaml under each service's 'dependencies' and 'requires_capabilities' keys
    $dependenciesConfig = $servicesConfig;  // Alias for compatibility
    
    // Main operations
    if (isset($options['analyze'])) {
        analyzeServices($resolver, $servicesConfig, $dependenciesConfig, $verbose, $exportPath);
    }
    
    if (isset($options['fix'])) {
        fixCircularDependencies($resolver, $servicesConfig, $dependenciesConfig, $verbose, $exportPath);
    }
    
    if (isset($options['generate-graph'])) {
        generateDependencyGraph($resolver, $servicesConfig, $dependenciesConfig, $exportPath);
    }
    
} catch (Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    if ($verbose) {
        echo "Exception trace:\n";
        echo $e->getTraceAsString() . "\n";
    }
    exit(1);
}

/**
 * Analyze service dependencies for issues
 */
function analyzeServices(DependencyResolver $resolver, string $servicesConfig, string $dependenciesConfig, bool $verbose, ?string $exportPath = null): void {
    echo "Analyzing service dependencies...\n";
    
    try {
        // Load from YAML
        $resolver->loadFromYaml($servicesConfig, $dependenciesConfig);
        
        // Get the load order
        $loadOrder = $resolver->getLoadOrder();
        
        echo "✅ No circular dependencies detected!\n";
        echo "Load order: " . implode(' → ', $loadOrder) . "\n";
        
        // Perform additional analysis
        $analysis = $resolver->analyzeCircularDependencies();
        
        // Show potential issues
        if (!empty($analysis['potential_cycles'])) {
            echo "\n⚠️ Potential circular dependency risks found:\n";
            
            foreach ($analysis['potential_cycles'] as $index => $issue) {
                echo ($index + 1) . ". Service: {$issue['service']} (Dependency depth: {$issue['depth']})\n";
                echo "   Has mutual dependencies with: " . implode(', ', $issue['mutual_dependencies']) . "\n";
            }
            
            // Show recommendations
            echo "\nRecommendations:\n";
            foreach ($analysis['recommendations'] as $index => $rec) {
                echo ($index + 1) . ". {$rec}\n";
            }
        }
        
        // Show statistics
        echo "\nStatistics:\n";
        echo "- Total services: {$analysis['service_stats']['total_services']}\n";
        echo "- Average dependencies per service: " . number_format($analysis['service_stats']['avg_dependencies'], 2) . "\n";
        echo "- Maximum dependency depth: {$analysis['service_stats']['max_dependency_depth']}\n";
        
        // Export results if requested
        if ($exportPath) {
            $exportData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'load_order' => $loadOrder,
                'analysis' => $analysis,
                'status' => 'success'
            ];
            
            file_put_contents($exportPath, json_encode($exportData, JSON_PRETTY_PRINT));
            echo "\nResults exported to {$exportPath}\n";
        }
        
    } catch (CircularDependencyException $e) {
        echo "❌ Circular dependency detected: {$e->getVisualPath()}\n";
        
        if ($verbose) {
            echo "\nDetailed error: {$e->getMessage()}\n";
        }
        
        if ($exportPath) {
            $exportData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => $e->getMessage(),
                'cycle_path' => $e->getPath(),
                'status' => 'error'
            ];
            
            file_put_contents($exportPath, json_encode($exportData, JSON_PRETTY_PRINT));
            echo "\nResults exported to {$exportPath}\n";
        }
        
        exit(1);
    }
}

/**
 * Attempt to fix circular dependencies
 */
function fixCircularDependencies(DependencyResolver $resolver, string $servicesConfig, string $dependenciesConfig, bool $verbose, ?string $exportPath = null): void {
    echo "Attempting to fix circular dependencies...\n";
    
    // Create a resolver with auto-fix strategy
    $fixResolver = new DependencyResolver('auto-fix');
    
    try {
        // Load from YAML
        $fixResolver->loadFromYaml($servicesConfig, $dependenciesConfig);
        
        // Get the fixed load order
        $loadOrder = $fixResolver->getLoadOrder();
        
        echo "✅ Dependencies fixed successfully!\n";
        echo "New load order: " . implode(' → ', $loadOrder) . "\n";
        
        // Export results if requested
        if ($exportPath) {
            $exportData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'load_order' => $loadOrder,
                'fixed' => true,
                'status' => 'success'
            ];
            
            file_put_contents($exportPath, json_encode($exportData, JSON_PRETTY_PRINT));
            echo "\nResults exported to {$exportPath}\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Failed to fix dependencies: {$e->getMessage()}\n";
        
        if ($verbose) {
            echo "\nDetailed error: {$e->getMessage()}\n";
            echo "Exception trace:\n";
            echo $e->getTraceAsString() . "\n";
        }
        
        exit(1);
    }
}

/**
 * Generate dependency graph in DOT format
 */
function generateDependencyGraph(DependencyResolver $resolver, string $servicesConfig, string $dependenciesConfig, ?string $exportPath = null): void {
    echo "Generating dependency graph...\n";
    
    try {
        // Load from YAML
        $resolver->loadFromYaml($servicesConfig, $dependenciesConfig);
        
        // Generate DOT format graph
        $dotGraph = $resolver->generateDependencyGraph();
        
        if ($exportPath) {
            file_put_contents($exportPath, $dotGraph);
            echo "✅ Graph exported to {$exportPath}\n";
            echo "You can visualize this graph using Graphviz: dot -Tpng {$exportPath} -o dependency-graph.png\n";
        } else {
            echo $dotGraph . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Failed to generate graph: {$e->getMessage()}\n";
        exit(1);
    }
}

/**
 * Get ValKey connection or create a stub if not available
 */
function getValKeyConnection(): ValKeyStorage {
    try {
        return new ValKeyStorage([
            'host' => getenv('VALKEY_HOST') ?: null,
            'port' => getenv('VALKEY_PORT') ? (int)getenv('VALKEY_PORT') : null,
            'timeout' => 2.0
        ]);
    } catch (Exception $e) {
        // Create a stub implementation
        return new class() extends ValKeyStorage {
            private $data = [];
            
            public function __construct() {
                // No-op constructor
            }
            
            public function isConnected(): bool {
                return true;
            }
            
            public function get($key) {
                return $this->data[$key] ?? null;
            }
            
            public function set($key, $value, $options = null) {
                $this->data[$key] = $value;
                return true;
            }
            
            public function delete($key) {
                unset($this->data[$key]);
                return true;
            }
            
            public function runScript($script, $keys = [], $args = []) {
                return null;
            }
        };
    }
}