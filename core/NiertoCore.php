<?php
/**
 * NiertoCore System
 * 
 * Central management system for Nierto theme functionality.
 * Provides a modular foundation for different UI implementations
 * while maintaining consistent core services.
 * 
 * @package     NiertoCube
 * @subpackage  Core
 * @version     2.0.0
 * 
 * Architecture Overview:
 * - Implements singleton pattern for core system
 * - Manages module lifecycle and dependencies
 * - Provides centralized configuration management
 * - Ensures proper initialization order
 * - Handles autoloading and error management
 * 
 * Dependency Hierarchy:
 * Level 0 (Base): Error, Cache
 * Level 1: Metrics, API
 * Level 2: Optimization, Manifest
 */

namespace NiertoCube\Core;

if (!defined('ABSPATH')) {
    exit;
}

class NiertoCore {
    /** @var NiertoCore Singleton instance */
    private static $instance = null;
    
    /** @var array Registered modules */
    private $modules = [];
    
    /** @var array System configuration */
    private $config = [];
    
    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Module dependency levels */
    private const MODULE_LEVELS = [
        0 => ['Error', 'Cache'],              // Base level - no dependencies
        1 => ['Metrics', 'API'],              // First level - depends on base
        2 => ['Optimization', 'Manifest']      // Second level - depends on first
    ];

    /** @var array Core module definitions */
    private const CORE_MODULES = [
        // Base Level (No Dependencies)
        'Error' => [
            'class' => 'NiertoCube\\Modules\\ErrorManager',
            'priority' => 1,
            'dependencies' => [],
            'required' => true
        ],
        'Cache' => [
            'class' => 'NiertoCube\\Modules\\CacheManager',
            'priority' => 2,
            'dependencies' => [],
            'required' => true
        ],
        // First Level
        'Metrics' => [
            'class' => 'NiertoCube\\Modules\\MetricsManager',
            'priority' => 3,
            'dependencies' => ['Error', 'Cache'],
            'required' => false
        ],
        'API' => [
            'class' => 'NiertoCube\\Modules\\APIManager',
            'priority' => 4,
            'dependencies' => ['Error', 'Cache'],
            'required' => false
        ],
        // Second Level
        'Optimization' => [
            'class' => 'NiertoCube\\Modules\\OptimizationManager',
            'priority' => 5,
            'dependencies' => ['Error', 'Cache', 'Metrics'],
            'required' => false
        ],
        'Manifest' => [
            'class' => 'NiertoCube\\Modules\\ManifestManager',
            'priority' => 6,
            'dependencies' => ['Error', 'Cache', 'API'],
            'required' => false
        ]
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {
        $this->setupAutoloader();
        $this->loadConfiguration();
    }

    /**
     * Register a module
     * 
     * @throws \Exception If module registration fails
     */
    public function registerModule(
        string $name,
        string $class,
        array $dependencies = [],
        bool $required = false,
        int $priority = 10,
        array $config = []
    ): self {
        // Validate module class
        if (!class_exists($class)) {
            throw new \Exception("Module class does not exist: {$class}");
        }

        if (!in_array(ModuleInterface::class, class_implements($class))) {
            throw new \Exception("Module must implement ModuleInterface: {$class}");
        }

        // Check for duplicate registration
        if (isset($this->modules[$name])) {
            throw new \Exception("Module already registered: {$name}");
        }

        // Validate dependencies
        foreach ($dependencies as $dep) {
            if (!isset(self::CORE_MODULES[$dep]) && !isset($this->modules[$dep])) {
                throw new \Exception("Invalid dependency '{$dep}' for module '{$name}'");
            }
        }

        $this->modules[$name] = [
            'class' => $class,
            'priority' => $priority,
            'dependencies' => $dependencies,
            'required' => $required,
            'config' => $config,
            'instance' => null,
            'initialized' => false
        ];

        return $this;
    }

    /**
     * Initialize the core system
     * 
     * @throws \Exception If initialization fails
     */
    public function initialize(): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Register core modules
            $this->registerCoreModules();

            // Initialize modules by level
            foreach (self::MODULE_LEVELS as $level => $moduleNames) {
                foreach ($moduleNames as $name) {
                    if (isset($this->modules[$name])) {
                        $this->initializeModule($name);
                    }
                }
            }

            $this->initialized = true;

            // Log successful initialization
            if ($this->isModuleInitialized('Error')) {
                $this->getModule('Error')->logMessage(
                    'Core system initialized successfully',
                    'SYSTEM',
                    'INFO'
                );
            }

        } catch (\Exception $e) {
            $this->handleInitializationError($e);
        }
    }

    /**
     * Register core modules
     */
    private function registerCoreModules(): void {
        foreach (self::CORE_MODULES as $name => $info) {
            $this->registerModule(
                $name,
                $info['class'],
                $info['dependencies'],
                $info['required'],
                $info['priority'],
                $this->config['modules'][strtolower($name)] ?? []
            );
        }
    }

    /**
     * Initialize specific module
     * 
     * @throws \Exception If module initialization fails
     */
    private function initializeModule(string $name): void {
        if (!isset($this->modules[$name])) {
            throw new \Exception("Module not registered: {$name}");
        }

        $module = &$this->modules[$name];
        
        // Skip if already initialized
        if ($module['initialized']) {
            return;
        }

        // Check dependencies
        foreach ($module['dependencies'] as $dep) {
            if (!$this->isModuleInitialized($dep)) {
                $this->initializeModule($dep);
            }
        }

        try {
            // Get module instance
            if ($module['instance'] === null) {
                $module['instance'] = $module['class']::getInstance();
            }

            // Prepare module configuration with dependencies
            $config = $module['config'];
            $config['dependencies'] = [];
            
            foreach ($module['dependencies'] as $dep) {
                $config['dependencies'][$dep] = $this->getModule($dep);
            }

            // Initialize module
            $module['instance']->initialize($config);
            $module['initialized'] = true;

        } catch (\Exception $e) {
            if ($module['required']) {
                throw new \Exception(
                    "Failed to initialize required module {$name}: " . $e->getMessage()
                );
            }
            
            // Log error for non-required modules
            if ($this->isModuleInitialized('Error')) {
                $this->getModule('Error')->logError(
                    'module_init_failed',
                    "Failed to initialize module {$name}: " . $e->getMessage()
                );
            }
        }
    }

    /**
     * Get module instance
     * 
     * @throws \Exception If module not found or initialization fails
     */
    public function getModule(string $name): ?ModuleInterface {
        if (!isset($this->modules[$name])) {
            throw new \Exception("Module not found: {$name}");
        }

        if (!$this->isModuleInitialized($name)) {
            $this->initializeModule($name);
        }

        return $this->modules[$name]['instance'];
    }

    /**
     * Check if module is initialized
     */
    public function isModuleInitialized(string $name): bool {
        return isset($this->modules[$name]) && 
               $this->modules[$name]['initialized'] && 
               $this->modules[$name]['instance'] !== null;
    }

    /**
     * Load system configuration
     */
    private function loadConfiguration(): void {
        try {
            $default_config = require get_template_directory() . '/config/default-config.php';
            $custom_config = [];

            $custom_config_file = get_template_directory() . '/config/config.php';
            if (file_exists($custom_config_file)) {
                $custom_config = require $custom_config_file;
            }

            $this->config = array_replace_recursive($default_config, $custom_config);
        } catch (\Exception $e) {
            throw new \Exception("Failed to load configuration: " . $e->getMessage());
        }
    }

    /**
     * Setup autoloader for NiertoCube classes
     */
    private function setupAutoloader(): void {
        spl_autoload_register(function ($class) {
            // Only handle our namespace
            if (strpos($class, 'NiertoCube\\') !== 0) {
                return;
            }

            $class_path = str_replace('\\', '/', $class);
            $class_path = str_replace('NiertoCube/', '', $class_path);
            $file = get_template_directory() . '/inc/' . $class_path . '.php';

            if (file_exists($file)) {
                require_once $file;
            }
        });
    }

    /**
     * Handle initialization error
     */
    private function handleInitializationError(\Exception $e): void {
        $message = "Core initialization failed: " . $e->getMessage();
        
        // Try to log through ErrorManager if available
        if ($this->isModuleInitialized('Error')) {
            $this->getModule('Error')->logError('core_init_failed', $message);
        } else {
            error_log($message);
        }

        throw new \Exception($message);
    }

    /**
     * Get system status
     */
    public function getStatus(): array {
        $status = [
            'initialized' => $this->initialized,
            'version' => $this->config['core']['version'] ?? 'unknown',
            'environment' => $this->config['core']['environment'] ?? 'production',
            'modules' => []
        ];

        foreach ($this->modules as $name => $module) {
            $status['modules'][$name] = [
                'registered' => true,
                'initialized' => $module['initialized'],
                'priority' => $module['priority'],
                'required' => $module['required'],
                'dependencies' => $module['dependencies'],
                'status' => $module['instance'] ? $module['instance']->getStatus() : null
            ];
        }

        return $status;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}