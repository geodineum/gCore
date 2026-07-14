<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Main;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Platform Interface
 * 
 * Defines the contract for platform-specific functionality.
 */
interface PlatformInterface {
    /**
     * Get platform information
     * 
     * @return array Platform information
     */
    public function getPlatformInfo(): array;
    
    /**
     * Check if running in a specific environment
     * 
     * @param string $environment Environment name
     * @return bool True if in the specified environment
     */
    public function isEnvironment(string $environment): bool;
    
    /**
     * Get platform capabilities
     * 
     * @return array List of supported capabilities
     */
    public function getCapabilities(): array;
}