<?php
/**
 * NiertoCube Module Interface
 * 
 * Defines the contract that all NiertoCube modules must implement.
 * 
 * @package     NiertoCube
 * @subpackage  Core
 * @version     2.0.0
 */

namespace NiertoCube\Core;

interface ModuleInterface {
    /**
     * Get singleton instance
     */
    public static function getInstance(): self;

    /**
     * Initialize the module
     * 
     * @param array $config Configuration options
     */
    public function initialize(array $config = []): void;

    /**
     * Get module configuration
     */
    public function getConfig(): array;

    /**
     * Update module configuration
     */
    public function updateConfig(array $config): void;

    /**
     * Check if module is initialized
     */
    public function isInitialized(): bool;

    /**
     * Get module status information
     */
    public function getStatus(): array;
}