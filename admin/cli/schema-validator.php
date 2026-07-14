<?php
/**
 * Schema Validator CLI Tool
 * Validates configuration files against their schemas
 */

namespace gCore\Admin\CLI;

// Bootstrap core
require_once __DIR__ . '/../../Modules/Core/gCore.php';

use Modules\Core\Utils\ConfigLoader;
use Modules\Core\Utils\SchemaRegistry;
use Modules\Core\Exceptions\ValidationException;

/**
 * Schema Validator class
 */
class SchemaValidator
{
    /** @var ConfigLoader */
    private $configLoader;
    
    /** @var string Config directory */
    private $configDir;
    
    /** @var bool Verbose output */
    private $verbose = false;
    
    /** @var bool Fix mode */
    private $fix = false;
    
    /** @var array Validation results */
    private $results = [];
    
    /**
     * Constructor
     *
     * @param string $configDir Config directory
     * @param bool $verbose Enable verbose output
     * @param bool $fix Enable fix mode
     */
    public function __construct(string $configDir = './config', bool $verbose = false, bool $fix = false)
    {
        $this->configDir = rtrim($configDir, '/');
        $this->verbose = $verbose;
        $this->fix = $fix;
        
        $this->configLoader = new ConfigLoader($configDir);
        
        // Initialize SchemaRegistry
        SchemaRegistry::initialize($configDir . '/schemas');
    }
    
    /**
     * Run validation
     *
     * @param array|null $files Specific files to validate
     * @return bool True if all validations passed
     */
    public function run(?array $files = null): bool
    {
        $this->log("Schema Validator starting...", true);
        $this->log("Config directory: {$this->configDir}", true);
        
        // Check for missing schemas
        $missing = SchemaRegistry::validateSchemaExistence($this->configDir . '/schemas');
        if (!empty($missing)) {
            $this->log("Missing schemas: " . implode(', ', $missing), true);
        }
        
        if ($files === null) {
            // Validate all known configuration files
            $this->validateAllConfigs();
        } else {
            // Validate specific files
            foreach ($files as $file) {
                $this->validateFile($file);
            }
        }
        
        // Print summary
        $this->printSummary();
        
        return empty(array_filter($this->results, function($result) {
            return $result['status'] === 'fail';
        }));
    }
    
    /**
     * Validate all known configuration files
     */
    private function validateAllConfigs(): void
    {
        $this->log("Validating all configuration files...", true);
        
        // Core config files
        $this->validateFile('default_config.yaml', 'core');
        
        // Environment-specific configs
        $environments = ['development', 'staging', 'production', 'wordpress'];
        foreach ($environments as $env) {
            $path = "environments/{$env}.yaml";
            if (file_exists("{$this->configDir}/{$path}")) {
                $this->validateFile($path, 'environment');
            }
        }
        
        // Service configuration files
        $serviceFiles = [
            'services.yaml' => 'services',
            'dependencies.yaml' => 'dependencies',
            'service_topology.yaml' => 'service_topology',
            'multisite.yaml' => 'multisite',
            'lua_scripts.yaml' => 'lua_scripts'
        ];
        
        foreach ($serviceFiles as $file => $schema) {
            if (file_exists("{$this->configDir}/{$file}")) {
                $this->validateFile($file, $schema);
            }
        }
        
        // Manager configuration files
        $managerFiles = glob("{$this->configDir}/managers/*.yaml");
        foreach ($managerFiles as $file) {
            $manager = basename($file, '.yaml');
            $this->validateFile("managers/{$manager}.yaml", "manager/{$manager}");
        }
        
        // Trait configuration files
        $this->validateTraitFiles();
    }
    
    /**
     * Validate trait configuration files
     */
    private function validateTraitFiles(): void
    {
        $this->log("Validating trait configuration files...", true);
        
        $traitDirs = glob("{$this->configDir}/traits/*", GLOB_ONLYDIR);
        foreach ($traitDirs as $dir) {
            $manager = basename($dir);
            $traitFiles = glob("{$dir}/*.yaml");
            
            foreach ($traitFiles as $file) {
                $trait = basename($file, '.yaml');
                $this->validateFile("traits/{$manager}/{$trait}.yaml", "trait/{$manager}/{$trait}");
            }
        }
    }
    
    /**
     * Validate a specific file
     *
     * @param string $file File path relative to config directory
     * @param string|null $schemaKey Schema key
     * @return bool True if validation passed
     */
    private function validateFile(string $file, ?string $schemaKey = null): bool
    {
        $this->log("Validating {$file}...");
        
        // Derive schema key from file path if not provided
        if ($schemaKey === null) {
            $schemaKey = $this->deriveSchemaKey($file);
            if ($schemaKey === null) {
                $this->log("  Unable to determine schema for {$file}", true);
                $this->results[$file] = [
                    'status' => 'skip',
                    'message' => 'No schema available'
                ];
                return false;
            }
        }
        
        try {
            $config = $this->configLoader->loadYamlFile($file);
            $result = $this->configLoader->validateWithSchema($config, $schemaKey);
            
            $this->log("  Schema validation passed.", true);
            $this->results[$file] = [
                'status' => 'pass',
                'message' => 'Validation successful'
            ];
            return true;
            
        } catch (ValidationException $e) {
            $this->log("  Schema validation failed: {$e->getMessage()}", true);
            $this->results[$file] = [
                'status' => 'fail',
                'message' => $e->getMessage()
            ];
            return false;
            
        } catch (\Exception $e) {
            $this->log("  Error validating {$file}: {$e->getMessage()}", true);
            $this->results[$file] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            return false;
        }
    }
    
    /**
     * Derive schema key from file path
     *
     * @param string $file File path
     * @return string|null Schema key
     */
    private function deriveSchemaKey(string $file): ?string
    {
        // Remove extension
        $path = pathinfo($file, PATHINFO_FILENAME);
        
        // Special case handling
        if (strpos($file, 'traits/') === 0) {
            // Format: traits/Manager/Trait.yaml -> trait/Manager/Trait
            $parts = explode('/', $file);
            if (count($parts) === 3) {
                return 'trait/' . $parts[1] . '/' . pathinfo($parts[2], PATHINFO_FILENAME);
            }
        } elseif (strpos($file, 'managers/') === 0) {
            // Format: managers/Manager.yaml -> manager/Manager
            $parts = explode('/', $file);
            if (count($parts) === 2) {
                return 'manager/' . pathinfo($parts[1], PATHINFO_FILENAME);
            }
        } elseif (strpos($file, 'environments/') === 0) {
            // Format: environments/*.yaml -> environment
            return 'environment';
        } elseif ($file === 'default_config.yaml') {
            return 'core';
        }
        
        // Default: try to use the filename as schema key
        return pathinfo($file, PATHINFO_FILENAME);
    }
    
    /**
     * Print validation summary
     */
    private function printSummary(): void
    {
        $totalFiles = count($this->results);
        $passed = count(array_filter($this->results, function($result) {
            return $result['status'] === 'pass';
        }));
        $failed = count(array_filter($this->results, function($result) {
            return $result['status'] === 'fail';
        }));
        $errors = count(array_filter($this->results, function($result) {
            return $result['status'] === 'error';
        }));
        $skipped = count(array_filter($this->results, function($result) {
            return $result['status'] === 'skip';
        }));
        
        echo "\n=== Schema Validation Summary ===\n";
        echo "Total files: {$totalFiles}\n";
        echo "Passed: {$passed}\n";
        echo "Failed: {$failed}\n";
        echo "Errors: {$errors}\n";
        echo "Skipped: {$skipped}\n";
        
        if ($failed > 0 || $errors > 0) {
            echo "\nFailed/Error files:\n";
            foreach ($this->results as $file => $result) {
                if ($result['status'] === 'fail' || $result['status'] === 'error') {
                    echo "  - {$file}: {$result['message']}\n";
                }
            }
        }
        
        if ($skipped > 0 && $this->verbose) {
            echo "\nSkipped files:\n";
            foreach ($this->results as $file => $result) {
                if ($result['status'] === 'skip') {
                    echo "  - {$file}: {$result['message']}\n";
                }
            }
        }
    }
    
    /**
     * Log message to console
     *
     * @param string $message Message to log
     * @param bool $force Force output even if not verbose
     */
    private function log(string $message, bool $force = false): void
    {
        if ($this->verbose || $force) {
            echo $message . "\n";
        }
    }
}

// Run from CLI
if (PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    $options = getopt('c:vf', ['config:', 'verbose', 'fix', 'help']);
    
    if (isset($options['help'])) {
        echo "Schema Validator CLI Tool\n";
        echo "Usage: php " . basename(__FILE__) . " [options] [files...]\n";
        echo "Options:\n";
        echo "  -c, --config=DIR   Set configuration directory (default: ./config)\n";
        echo "  -v, --verbose      Enable verbose output\n";
        echo "  -f, --fix          Fix validation errors when possible\n";
        echo "  --help             Show this help message\n";
        exit(0);
    }
    
    $configDir = $options['c'] ?? $options['config'] ?? './config';
    $verbose = isset($options['v']) || isset($options['verbose']);
    $fix = isset($options['f']) || isset($options['fix']);
    
    // Get files to validate (remaining arguments)
    $files = array_values(array_filter($_SERVER['argv'], function($arg) {
        return $arg[0] !== '-' && $arg !== basename(__FILE__);
    }));
    
    $validator = new SchemaValidator($configDir, $verbose, $fix);
    $success = $validator->run($files ?: null);
    
    exit($success ? 0 : 1);
}