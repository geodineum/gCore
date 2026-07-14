<?php
/**
 * gCore Command Line Interface
 * 
 * Provides command-line access to gCore services, configuration, 
 * and management functions.
 */

// Define constants
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

if (!defined('GCORE_CONFIG_PATH')) {
    define('GCORE_CONFIG_PATH', __DIR__ . '/config');
}

// Core initialization
require_once __DIR__ . '/gcore-standalone.php';

/**
 * Command line interface for gCore
 */
class gCoreCLI {
    /**
     * Available commands
     * 
     * @var array
     */
    private $commands = [
        'help' => 'Show this help message',
        'status' => 'Show gCore status and all service statuses',
        'service' => 'Manage a specific service [status|start|stop|restart] [service_name]',
        'list' => 'List all available services',
        'topology' => 'Manage service topology [visualize|discover|dimensions]',
        'config' => 'Show or modify configuration',
        'test' => 'Run tests',
        'schema' => 'Validate configuration schemas',
        'dependency' => 'Analyze and fix dependencies',
        'check-capabilities' => 'Analyze code to find used capabilities'
    ];
    
    /**
     * gCore instance
     * 
     * @var \gCore\Modules\Core\gCore
     */
    private $gCore;
    
    /**
     * Configuration
     * 
     * @var array
     */
    private $config = [];
    
    /**
     * Command-line arguments
     * 
     * @var array
     */
    private $args = [];
    
    /**
     * Simple mode flag (no ValKey/Redis required)
     * 
     * @var bool
     */
    private $simpleMode = false;
    
    /**
     * Constructor
     * 
     * @param array $args Command-line arguments
     */
    public function __construct(array $args = []) {
        $this->args = $args;
        
        // Check for simple mode flag
        $this->simpleMode = in_array('--simple', $args);
        
        // Default configuration
        $this->config = [
            'core' => [
                'environment' => 'development',
                'debug' => true
            ],
            'site_id' => 'cli',
            'node_id' => gethostname() ?: 'cli_node'
        ];
        
        // Only add storage config if not in simple mode
        if (!$this->simpleMode) {
            $this->config['storage'] = [
                'host' => getenv('VALKEY_HOST') ?: null,
                'port' => (int)(getenv('VALKEY_PORT') ?: 0) ?: null,
                'auth' => getenv('VALKEY_AUTH') ?: null,
                'tls' => getenv('VALKEY_TLS') === 'true',
            ];
        }
    }
    
    /**
     * Initialize gCore
     */
    public function init(): void {
        if ($this->simpleMode) {
            echo "Running in simple mode (no ValKey/Redis required)\n";
            
            try {
                // First try to create ConfigLoader directly
                if (class_exists('\\gCore\\Modules\\Core\\Utils\\ConfigLoader')) {
                    $configLoader = new \gCore\Modules\Core\Utils\ConfigLoader();
                    
                    $topologyPath = GCORE_CONFIG_PATH . '/service_topology.yaml';
                    if (file_exists($topologyPath)) {
                        echo "Loading service topology from file...\n";
                        $this->serviceTopology = $configLoader->load($topologyPath);
                        
                        // Create minimal gCore object
                        $this->gCore = $this->createMinimalGCoreInstance();
                        echo "✅ Simple mode initialized\n";
                        return;
                    }
                }
            } catch (\Exception $e) {
                // Fall back to minimal topology if loading fails
            }
            
            // Create minimal topology information as fallback
            echo "Using minimal service topology information\n";
            $this->serviceTopology = [
                'base_services' => [
                    'ErrorManager' => ['class' => 'gCore\\Modules\\Managers\\Base\\ErrorManager\\ErrorManager'],
                    'CacheManager' => ['class' => 'gCore\\Modules\\Managers\\Base\\CacheManager\\CacheManager'],
                    'SecurityManager' => ['class' => 'gCore\\Modules\\Managers\\Base\\SecurityManager\\SecurityManager'],
                    'APIManager' => ['class' => 'gCore\\Modules\\Managers\\Base\\APIManager\\APIManager']
                ]
            ];
            
            // Create minimal gCore object
            $this->gCore = $this->createMinimalGCoreInstance();
            echo "✅ Simple mode initialized with minimal topology\n";
            return;
        }
        
        try {
            echo "Initializing gCore framework...\n";
            $this->gCore = gcore_init($this->config);
            echo "✅ gCore initialized successfully\n";
        } catch (\Exception $e) {
            echo "❌ Failed to initialize gCore: " . $e->getMessage() . "\n";
            echo "Try running with --simple flag if you don't have ValKey/Redis available.\n";
            exit(1);
        }
    }
    
    /**
     * Create a minimal gCore instance for simple mode
     */
    private function createMinimalGCoreInstance() {
        // Get gCore class via reflection
        $reflectionClass = new \ReflectionClass('\\gCore\\Modules\\Core\\gCore');
        
        // Use getInstance method
        $getInstance = $reflectionClass->getMethod('getInstance');
        $gCore = $getInstance->invoke(null);
        
        // Set properties via reflection
        $serviceTopologyProperty = $reflectionClass->getProperty('serviceTopology');
        $serviceTopologyProperty->setAccessible(true);
        $serviceTopologyProperty->setValue($gCore, $this->serviceTopology);
        
        // Set initialized property
        $initializedProperty = $reflectionClass->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue($gCore, true);
        
        // Create config
        $configProperty = $reflectionClass->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue($gCore, $this->config);
        
        return $gCore;
    }
    
    /**
     * Load service topology from YAML file
     */
    private function loadServiceTopologyFromFile(string $filePath): void {
        $content = file_get_contents($filePath);
        
        // Basic YAML parsing (for simple structures only)
        $topology = [];
        $currentSection = null;
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            // Check if it's a top-level section
            if (substr($line, -1) === ':' && strpos($line, ' ') === false) {
                $currentSection = substr($line, 0, -1);
                $topology[$currentSection] = [];
                continue;
            }
            
            // Check if it's a service definition
            if ($currentSection && preg_match('/^(\s+)(\w+):$/', $line, $matches)) {
                $serviceName = trim($matches[2]);
                $topology[$currentSection][$serviceName] = [];
                continue;
            }
            
            // Check if it's a property
            if (preg_match('/^(\s+)(\w+): (.+)$/', $line, $matches)) {
                $property = trim($matches[2]);
                $value = trim($matches[3]);
                
                // Convert value types
                if ($value === 'true') {
                    $value = true;
                } elseif ($value === 'false') {
                    $value = false;
                } elseif (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                
                // Add to current service if in a service definition
                if ($currentSection && !empty($topology[$currentSection])) {
                    $serviceNames = array_keys($topology[$currentSection]);
                    $lastService = end($serviceNames);
                    
                    if ($lastService) {
                        $topology[$currentSection][$lastService][$property] = $value;
                    }
                }
            }
        }
        
        $this->serviceTopology = $topology;
    }
    
    /**
     * Run the CLI application
     */
    public function run(): void {
        // Remove options from args before getting command
        $commandArgs = array_values(array_filter($this->args, function($arg) {
            return strpos($arg, '--') !== 0;
        }));
        
        $command = $commandArgs[1] ?? 'help';
        
        switch ($command) {
            case 'status':
                $this->showStatus();
                break;
                
            case 'service':
                $action = $commandArgs[2] ?? 'status';
                $service = $commandArgs[3] ?? null;
                $this->manageService($action, $service);
                break;
                
            case 'list':
                $this->listServices();
                break;
                
            case 'topology':
                $action = $commandArgs[2] ?? 'visualize';
                $this->manageTopology($action);
                break;
                
            case 'config':
                $action = $commandArgs[2] ?? 'show';
                $this->manageConfig($action);
                break;
                
            case 'test':
                $type = $commandArgs[2] ?? 'all';
                $this->runTests($type);
                break;
                
            case 'check-capabilities':
                $this->checkCapabilities();
                break;
                
            case 'schema':
                $this->validateSchemas();
                break;
                
            case 'dependency':
                $this->manageDependencies();
                break;
                
            case 'help':
            default:
                $this->showHelp();
                break;
        }
    }
    
    /**
     * Show gCore status
     */
    private function showStatus(): void {
        $this->init();
        
        if ($this->simpleMode) {
            $this->showSimpleModeStatus();
            return;
        }
        
        try {
            $status = $this->gCore->getStatus();
            $services = $this->gCore->getServiceRegistry();
            
            echo "\ngCore Status\n";
            echo "===========\n";
            echo "Environment: " . ($status['config']['environment'] ?? 'unknown') . "\n";
            echo "Debug: " . (($status['config']['debug'] ?? false) ? 'Yes' : 'No') . "\n";
            echo "Health: " . ($status['health'] ? 'Healthy' : 'Unhealthy') . "\n";
            echo "Version: " . ($status['version'] ?? 'unknown') . "\n";
            echo "Uptime: " . round($status['uptime'] ?? 0, 2) . " seconds\n\n";
            
            echo "Services:\n";
            foreach ($services as $id => $service) {
                $state = $service['state'] ?? 'unknown';
                $uptime = isset($service['registered_at']) ? 
                    round(microtime(true) - $service['registered_at'], 2) : 0;
                
                echo sprintf("  %-20s [%-8s] Uptime: %.2f seconds\n",
                    $id,
                    $state,
                    $uptime
                );
            }
            
            echo "\nMetrics:\n";
            if (!empty($status['metrics'])) {
                foreach ($status['metrics'] as $metric => $value) {
                    echo "  $metric: $value\n";
                }
            } else {
                echo "  No metrics available\n";
            }
        } catch (\Exception $e) {
            echo "❌ Error getting gCore status: " . $e->getMessage() . "\n";
            echo "Try running with --simple flag to use the CLI without ValKey/Redis.\n";
        }
    }
    
    /**
     * Show status in simple mode
     */
    private function showSimpleModeStatus(): void {
        echo "\ngCore Status (Simple Mode)\n";
        echo "=======================\n";
        echo "Environment: " . ($this->config['core']['environment'] ?? 'development') . "\n";
        echo "Debug: " . (($this->config['core']['debug'] ?? false) ? 'Yes' : 'No') . "\n";
        echo "Site ID: " . ($this->config['site_id'] ?? 'cli') . "\n";
        echo "Node ID: " . ($this->config['node_id'] ?? 'cli_node') . "\n\n";
        
        echo "Service Categories:\n";
        if (isset($this->serviceTopology) && is_array($this->serviceTopology)) {
            foreach ($this->serviceTopology as $category => $services) {
                if ($category === 'version' || !is_array($services)) {
                    continue;
                }
                
                $count = count($services);
                echo sprintf("  %-20s [%d services]\n", $category, $count);
            }
        } else {
            echo "  No service topology loaded\n";
        }
        
        echo "\nNote: In simple mode, services are not initialized or active.\n";
        echo "Use the 'list' command to see available services.\n";
    }
    
    /**
     * Manage a specific service
     * 
     * @param string $action Action to perform (status|start|stop|restart)
     * @param string|null $service Service name
     */
    private function manageService(string $action, ?string $service): void {
        if ($service === null) {
            echo "❌ Error: Service name is required\n";
            echo "Usage: php gCoreCLI.php service [status|start|stop|restart] [service_name]\n";
            return;
        }
        
        $this->init();
        
        switch ($action) {
            case 'status':
                $this->showServiceStatus($service);
                break;
                
            case 'start':
                $this->startService($service);
                break;
                
            case 'stop':
                $this->stopService($service);
                break;
                
            case 'restart':
                $this->restartService($service);
                break;
                
            default:
                echo "❌ Error: Unknown action '$action'\n";
                echo "Valid actions: status, start, stop, restart\n";
                break;
        }
    }
    
    /**
     * Show status of a specific service
     * 
     * @param string $service Service name
     */
    private function showServiceStatus(string $service): void {
        if (!$this->gCore->hasService($service)) {
            echo "❌ Error: Service '$service' not found\n";
            return;
        }
        
        $status = $this->gCore->getServiceStatus($service);
        
        echo "\nService: $service\n";
        echo str_repeat('=', strlen($service) + 9) . "\n";
        echo "State: " . ($status['state'] ?? 'unknown') . "\n";
        
        if (isset($status['registered_at'])) {
            $uptime = round(microtime(true) - $status['registered_at'], 2);
            echo "Uptime: $uptime seconds\n";
        }
        
        echo "\n";
        
        // Get service instance if available
        if ($this->gCore->isServiceActive($service)) {
            try {
                $instance = $this->gCore->getService($service);
                
                // Show additional information if available
                if (method_exists($instance, 'getMetrics')) {
                    $metrics = $instance->getMetrics();
                    echo "Metrics:\n";
                    foreach ($metrics as $metric => $value) {
                        echo "  $metric: $value\n";
                    }
                    echo "\n";
                }
                
                if (method_exists($instance, 'getCapabilityVector')) {
                    $capabilities = $instance->getCapabilityVector();
                    echo "Capabilities:\n";
                    foreach ($capabilities as $capability => $value) {
                        echo "  $capability: $value\n";
                    }
                    echo "\n";
                }
            } catch (\Exception $e) {
                echo "❌ Error getting service details: " . $e->getMessage() . "\n";
            }
        }
    }
    
    /**
     * Start a service
     * 
     * @param string $service Service name
     */
    private function startService(string $service): void {
        // Check if service already active
        if ($this->gCore->isServiceActive($service)) {
            echo "ℹ️ Service '$service' is already active\n";
            return;
        }
        
        try {
            // Use reflection to call the initializeService method (it's private)
            $reflectionClass = new \ReflectionClass(get_class($this->gCore));
            $method = $reflectionClass->getMethod('initializeService');
            $method->setAccessible(true);
            $method->invoke($this->gCore, $service);
            
            echo "✅ Service '$service' started successfully\n";
        } catch (\Exception $e) {
            echo "❌ Failed to start service '$service': " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Stop a service
     * 
     * @param string $service Service name
     */
    private function stopService(string $service): void {
        // Check if service is active
        if (!$this->gCore->isServiceActive($service)) {
            echo "ℹ️ Service '$service' is not active\n";
            return;
        }
        
        try {
            $result = $this->gCore->stopService($service);
            
            if ($result) {
                echo "✅ Service '$service' stopped successfully\n";
            } else {
                echo "❌ Failed to stop service '$service'\n";
            }
        } catch (\Exception $e) {
            echo "❌ Error stopping service '$service': " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Restart a service
     * 
     * @param string $service Service name
     */
    private function restartService(string $service): void {
        echo "Restarting service '$service'...\n";
        
        // Stop if active
        if ($this->gCore->isServiceActive($service)) {
            $this->stopService($service);
        }
        
        // Start
        $this->startService($service);
    }
    
    /**
     * List all available services
     */
    private function listServices(): void {
        $this->init();
        
        // In simple mode, use the serviceTopology property
        if ($this->simpleMode && isset($this->serviceTopology)) {
            $serviceTopology = $this->serviceTopology;
        } else {
            // Get available services from topology via reflection
            try {
                $reflectionClass = new \ReflectionClass(get_class($this->gCore));
                $property = $reflectionClass->getProperty('serviceTopology');
                $property->setAccessible(true);
                $serviceTopology = $property->getValue($this->gCore);
            } catch (\Exception $e) {
                // Fallback to config
                $serviceTopology = $this->gCore->getConfig()['topology'] ?? [];
            }
        }
        
        if (empty($serviceTopology)) {
            echo "❌ Error: Service topology not loaded\n";
            return;
        }
        
        echo "\nAvailable Services\n";
        echo "=================\n\n";
        
        // Get active services for status comparison
        try {
            $registry = $this->gCore->getServiceRegistry();
        } catch (\Exception $e) {
            // In simple mode, we might not have a valid registry
            $registry = [];
        }
        
        $categoryPrinted = [];
        
        foreach ($serviceTopology as $category => $services) {
            if ($category === 'version' || !is_array($services)) {
                continue;
            }
            
            // Skip empty categories
            if (empty($services)) {
                continue;
            }
            
            // Make sure we only print each category once
            if (isset($categoryPrinted[$category])) {
                continue;
            }
            
            $categoryPrinted[$category] = true;
            
            echo "Category: $category\n";
            echo str_repeat('-', strlen($category) + 10) . "\n";
            
            foreach ($services as $id => $meta) {
                // Skip non-array metadata (sometimes happens with YAML)
                if (!is_array($meta)) {
                    continue;
                }
                
                $active = isset($registry[$id]) && ($registry[$id]['state'] ?? '') === 'active';
                $required = isset($meta['required']) && $meta['required'];
                $class = $meta['class'] ?? 'unknown';
                
                echo sprintf("  %-20s [%s] %s\n",
                    $id,
                    $active ? '✅ active' : '❌ inactive',
                    $required ? '[required]' : ''
                );
                
                if ($class !== 'unknown') {
                    echo "    Class: $class\n";
                }
                
                if (isset($meta['capabilities']) && is_array($meta['capabilities'])) {
                    echo "    Capabilities: " . implode(', ', $meta['capabilities']) . "\n";
                }
                
                echo "\n";
            }
        }
    }
    
    /**
     * Manage service topology
     * 
     * @param string $action Action to perform (visualize|discover|dimensions)
     */
    private function manageTopology(string $action): void {
        $this->init();
        
        // Check if GeometricTopology service is available
        if (!$this->gCore->hasService('geometric_topology')) {
            echo "❌ Error: GeometricTopology service not available\n";
            return;
        }
        
        $topology = $this->gCore->getService('geometric_topology');
        
        switch ($action) {
            case 'visualize':
                $this->visualizeTopology($topology);
                break;
                
            case 'discover':
                $requirements = $this->parseCapabilityRequirements();
                $this->discoverServices($topology, $requirements);
                break;
                
            case 'dimensions':
                $this->listCapabilityDimensions($topology);
                break;
                
            default:
                echo "❌ Error: Unknown action '$action'\n";
                echo "Valid actions: visualize, discover, dimensions\n";
                break;
        }
    }
    
    /**
     * Visualize service topology
     * 
     * @param object $topology GeometricTopology service
     */
    private function visualizeTopology(object $topology): void {
        echo "\nService Topology Visualization\n";
        echo "=============================\n\n";
        
        // Check if the method exists
        if (!method_exists($topology, 'visualize')) {
            echo "❌ Error: Visualization method not available in topology service\n";
            return;
        }
        
        try {
            $visualization = $topology->visualize();
            echo $visualization . "\n";
        } catch (\Exception $e) {
            echo "❌ Error generating topology visualization: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Discover services based on capability requirements
     * 
     * @param object $topology GeometricTopology service
     * @param array $requirements Capability requirements
     */
    private function discoverServices(object $topology, array $requirements): void {
        if (empty($requirements)) {
            echo "❌ Error: No capability requirements specified\n";
            echo "Usage: php gCoreCLI.php topology discover --capability-dimension1=value --capability-dimension2=value ...\n";
            return;
        }
        
        echo "\nDiscovering services with capabilities:\n";
        foreach ($requirements as $dimension => $value) {
            echo "  $dimension: $value\n";
        }
        echo "\n";
        
        try {
            $services = $topology->findServices($requirements);
            
            if (empty($services)) {
                echo "No services found matching the specified requirements.\n";
                return;
            }
            
            echo "Matching Services:\n";
            echo "-----------------\n";
            
            foreach ($services as $id => $info) {
                $type = is_array($info) ? ($info['type'] ?? 'unknown') : 'unknown';
                $score = is_array($info) ? ($info['score'] ?? 0) : 0;
                
                echo sprintf("  %-20s | Type: %-12s | Score: %.2f\n",
                    substr($id, 0, 20),
                    ucfirst($type),
                    $score
                );
            }
        } catch (\Exception $e) {
            echo "❌ Error discovering services: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * List capability dimensions
     * 
     * @param object $topology GeometricTopology service
     */
    private function listCapabilityDimensions(object $topology): void {
        echo "\nCapability Dimensions\n";
        echo "====================\n\n";
        
        try {
            if (method_exists($topology, 'getDimensions')) {
                $dimensions = $topology->getDimensions();
            } elseif (method_exists($topology, 'getCapabilityDimensions')) {
                $dimensions = $topology->getCapabilityDimensions();
            } else {
                echo "❌ Error: Unable to get capability dimensions from topology service\n";
                return;
            }
            
            if (empty($dimensions)) {
                echo "No capability dimensions found.\n";
                return;
            }
            
            foreach ($dimensions as $dimension) {
                echo "- $dimension\n";
            }
        } catch (\Exception $e) {
            echo "❌ Error getting capability dimensions: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Parse capability requirements from command-line arguments
     * 
     * @return array Capability requirements
     */
    private function parseCapabilityRequirements(): array {
        $requirements = [];
        
        foreach ($this->args as $arg) {
            if (strpos($arg, '--capability-') === 0) {
                $parts = explode('=', $arg);
                if (count($parts) === 2) {
                    $dimension = substr($parts[0], 13); // Remove '--capability-'
                    $value = floatval($parts[1]);
                    $requirements[$dimension] = $value;
                }
            }
        }
        
        return $requirements;
    }
    
    /**
     * Manage configuration
     * 
     * @param string $action Action to perform (show|get|set)
     */
    private function manageConfig(string $action): void {
        $this->init();
        
        switch ($action) {
            case 'yaml':
                $this->showYamlConfig();
                break;
                
            case 'show':
                $this->showConfig();
                break;
                
            case 'get':
                $key = $this->args[3] ?? null;
                $this->getConfigValue($key);
                break;
                
            case 'set':
                if ($this->simpleMode) {
                    echo "❌ Configuration changes are not supported in simple mode\n";
                    return;
                }
                
                $key = $this->args[3] ?? null;
                $value = $this->args[4] ?? null;
                $this->setConfigValue($key, $value);
                break;
                
            default:
                echo "❌ Error: Unknown action '$action'\n";
                echo "Valid actions: show, get, set, yaml\n";
                break;
        }
    }
    
    /**
     * Show YAML configuration file contents
     */
    private function showYamlConfig(): void {
        $configFile = GCORE_CONFIG_PATH . '/default_config.yaml';
        if (!file_exists($configFile)) {
            echo "❌ Config file not found: $configFile\n";
            return;
        }
        
        echo "\nContents of $configFile:\n";
        echo "==========================\n\n";
        echo file_get_contents($configFile) . "\n";
    }
    
    /**
     * Show all configuration
     */
    private function showConfig(): void {
        $config = $this->gCore->getConfig();
        
        echo "\nConfiguration\n";
        echo "=============\n\n";
        
        $this->printConfigRecursive($config);
    }
    
    /**
     * Print configuration recursively
     * 
     * @param array $config Configuration array
     * @param string $prefix Prefix for nested keys
     */
    private function printConfigRecursive(array $config, string $prefix = ''): void {
        foreach ($config as $key => $value) {
            $fullKey = $prefix ? "$prefix.$key" : $key;
            
            if (is_array($value) && !$this->isAssociativeArray($value)) {
                // List array
                echo "$fullKey = [" . implode(', ', $value) . "]\n";
            } elseif (is_array($value)) {
                // Nested object
                echo "$fullKey:\n";
                $this->printConfigRecursive($value, $fullKey);
            } else {
                // Simple value
                if (is_bool($value)) {
                    $formatted = $value ? 'true' : 'false';
                } elseif (is_null($value)) {
                    $formatted = 'null';
                } else {
                    $formatted = $value;
                }
                
                echo "$fullKey = $formatted\n";
            }
        }
    }
    
    /**
     * Check if an array is associative
     * 
     * @param array $array Array to check
     * @return bool True if associative
     */
    private function isAssociativeArray(array $array): bool {
        return array_keys($array) !== range(0, count($array) - 1);
    }
    
    /**
     * Get a specific configuration value
     * 
     * @param string|null $key Configuration key (dot notation)
     */
    private function getConfigValue(?string $key): void {
        if ($key === null) {
            echo "❌ Error: Configuration key is required\n";
            echo "Usage: php gCoreCLI.php config get [key]\n";
            return;
        }
        
        $config = $this->gCore->getConfig();
        $parts = explode('.', $key);
        $value = $config;
        
        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                echo "❌ Error: Configuration key '$key' not found\n";
                return;
            }
            
            $value = $value[$part];
        }
        
        echo "$key = ";
        
        if (is_array($value)) {
            echo json_encode($value, JSON_PRETTY_PRINT) . "\n";
        } elseif (is_bool($value)) {
            echo ($value ? 'true' : 'false') . "\n";
        } else {
            echo "$value\n";
        }
    }
    
    /**
     * Set a configuration value
     * 
     * @param string|null $key Configuration key (dot notation)
     * @param string|null $value Value to set
     */
    private function setConfigValue(?string $key, ?string $value): void {
        if ($key === null || $value === null) {
            echo "❌ Error: Configuration key and value are required\n";
            echo "Usage: php gCoreCLI.php config set [key] [value]\n";
            return;
        }
        
        $config = $this->gCore->getConfig();
        $parts = explode('.', $key);
        $lastPart = array_pop($parts);
        $target = &$config;
        
        foreach ($parts as $part) {
            if (!isset($target[$part]) || !is_array($target[$part])) {
                $target[$part] = [];
            }
            
            $target = &$target[$part];
        }
        
        // Convert value from string to appropriate type
        if ($value === 'true') {
            $target[$lastPart] = true;
        } elseif ($value === 'false') {
            $target[$lastPart] = false;
        } elseif ($value === 'null') {
            $target[$lastPart] = null;
        } elseif (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                $target[$lastPart] = floatval($value);
            } else {
                $target[$lastPart] = intval($value);
            }
        } else {
            $target[$lastPart] = $value;
        }
        
        // Update configuration
        $this->gCore->updateConfig($config);
        
        echo "✅ Configuration updated: $key = ";
        
        if (is_bool($target[$lastPart])) {
            echo ($target[$lastPart] ? 'true' : 'false') . "\n";
        } else {
            echo $target[$lastPart] . "\n";
        }
        
        // Warning about service reinitialization
        echo "\n⚠️ Note: Some configuration changes may require service reinitialization\n";
        echo "to take effect. Use 'php gCoreCLI.php service restart [service_name]' if needed.\n";
    }
    
    /**
     * Run tests
     * 
     * @param string $type Test type (unit|integration|performance|all)
     */
    private function runTests(string $type): void {
        echo "\nRunning $type tests...\n";
        
        $testScript = '';
        
        switch ($type) {
            case 'unit':
                $testScript = __DIR__ . '/tests/Unit/CacheManagerTest.php';
                break;
                
            case 'integration':
                $testScript = __DIR__ . '/tests/Integration/ComponentInteractionTest.php';
                break;
                
            case 'performance':
                $testScript = __DIR__ . '/tests/Performance/CacheManagerPerformanceTest.php';
                break;
                
            case 'all':
            default:
                $testScript = __DIR__ . '/tests/test-all.php';
                break;
        }
        
        if (!file_exists($testScript)) {
            echo "❌ Error: Test script '$testScript' not found\n";
            return;
        }
        
        // Execute test
        echo "Executing: $testScript\n\n";
        system('php -f ' . escapeshellarg($testScript));
    }
    
    /**
     * Show help message
     */
    private function showHelp(): void {
        echo "\ngCore CLI - Command Line Interface\n";
        echo "================================\n\n";
        echo "Usage: php gCoreCLI.php [command] [options]\n\n";
        echo "Available commands:\n";
        
        $maxLength = max(array_map('strlen', array_keys($this->commands)));
        
        foreach ($this->commands as $command => $description) {
            echo sprintf("  %-{$maxLength}s  %s\n", $command, $description);
        }
        
        echo "\nGlobal options:\n";
        echo "  --simple       Run in simple mode (no ValKey/Redis required)\n";
        
        echo "\nExamples:\n";
        echo "  php gCoreCLI.php status\n";
        echo "  php gCoreCLI.php service status CacheManager\n";
        echo "  php gCoreCLI.php service restart SecurityManager\n";
        echo "  php gCoreCLI.php topology discover --capability-cache=3.0 --capability-security=2.0\n";
        echo "  php gCoreCLI.php config show\n";
        echo "  php gCoreCLI.php config get core.debug\n";
        echo "  php gCoreCLI.php config set core.debug true\n";
        echo "  php gCoreCLI.php --simple list\n\n";
        
        echo "Simple mode:\n";
        echo "  Use the --simple flag to run commands without requiring ValKey/Redis.\n";
        echo "  This mode provides limited functionality but allows you to explore\n";
        echo "  the service topology and configuration without a database connection.\n";
        echo "  Example: php gCoreCLI.php --simple list\n\n";
    }
    
    /**
     * Analyze code to find used capabilities
     */
    private function checkCapabilities(): void {
        // Get path from arguments
        $path = array_values(array_filter($this->args, function($arg) {
            return strpos($arg, '--') !== 0;
        }))[2] ?? null;
        
        if ($path === null) {
            echo "❌ Error: Path argument is required\n";
            echo "Usage: php gCoreCLI.php check-capabilities [path] [options]\n";
            echo "Options: --recursive, --verbose, --output-config=PATH\n";
            return;
        }
        
        // Execute the capability checker script
        $checkerPath = __DIR__ . '/admin/cli/capability-checker.php';
        
        if (!file_exists($checkerPath)) {
            echo "❌ Error: Capability checker script not found at: $checkerPath\n";
            return;
        }
        
        // Build command arguments
        $cmdArgs = [$path];
        
        // Add options
        if (in_array('--recursive', $this->args) || in_array('-r', $this->args)) {
            $cmdArgs[] = '--recursive';
        }
        
        if (in_array('--verbose', $this->args) || in_array('-v', $this->args)) {
            $cmdArgs[] = '--verbose';
        }
        
        // Find output-config option
        foreach ($this->args as $arg) {
            if (strpos($arg, '--output-config=') === 0) {
                $cmdArgs[] = $arg;
                break;
            }
        }
        
        // Create instance and run
        require_once $checkerPath;
        $checker = new \gCore\Admin\CLI\CapabilityChecker();
        $checker->run($cmdArgs);
    }
    
    /**
     * Validate configuration schemas
     */
    private function validateSchemas(): void {
        $validatorPath = __DIR__ . '/admin/cli/schema-validator.php';
        
        if (!file_exists($validatorPath)) {
            echo "❌ Error: Schema validator script not found at: $validatorPath\n";
            return;
        }
        
        // Execute validator script
        echo "Executing schema validator...\n";
        
        // Build command arguments
        $cmdArgs = array_values(array_filter($this->args, function($arg) {
            return $arg !== 'schema' && $arg !== $this->args[0];
        }));
        
        // Execute as a separate process to ensure clean environment
        system('php -f ' . escapeshellarg($validatorPath) . ' ' . implode(' ', array_map('escapeshellarg', $cmdArgs)));
    }
    
    /**
     * Analyze and fix dependencies
     */
    private function manageDependencies(): void {
        $resolverPath = __DIR__ . '/admin/cli/dependency-resolver.php';
        
        if (!file_exists($resolverPath)) {
            echo "❌ Error: Dependency resolver script not found at: $resolverPath\n";
            return;
        }
        
        // Execute resolver script
        echo "Executing dependency resolver...\n";
        
        // Build command arguments
        $cmdArgs = array_values(array_filter($this->args, function($arg) {
            return $arg !== 'dependency' && $arg !== $this->args[0];
        }));
        
        // Execute as a separate process to ensure clean environment
        system('php -f ' . escapeshellarg($resolverPath) . ' ' . implode(' ', array_map('escapeshellarg', $cmdArgs)));
    }
}

// Create CLI instance and run
$cli = new gCoreCLI($argv);
$cli->run();