<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Main;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * System Configuration Interface
 * 
 * Defines the contract for system configuration.
 */
interface SystemConfigInterface {
    /**
     * Get configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    public function get(string $key, $default = null);
    
    /**
     * Set configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return bool Success status
     */
    public function set(string $key, $value): bool;
    
    /**
     * Check if configuration key exists
     * 
     * @param string $key Configuration key
     * @return bool True if key exists
     */
    public function has(string $key): bool;
    
    /**
     * Load configuration from file
     * 
     * @param string $file Configuration file path
     * @return bool Success status
     */
    public function load(string $file): bool;
    
    /**
     * Save configuration to file
     * 
     * @param string $file Configuration file path
     * @return bool Success status
     */
    public function save(string $file): bool;
}