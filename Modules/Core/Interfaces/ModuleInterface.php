<?php
declare(strict_types=1);
/**
 * gCore Module Interface
 * 
 * Defines the contract that all gCore modules must implement.
 * 
 * @package     gCore
 * @subpackage  Core
 * @version     2.0.0
 */
namespace gCore\Modules\Core\Interfaces;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

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