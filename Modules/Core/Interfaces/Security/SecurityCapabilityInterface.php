<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Security;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Security Capability Interface
 * 
 * Defines the contract for security capability checking.
 */
interface SecurityCapabilityInterface {
    /**
     * Check if user has a capability
     * 
     * @param string $user User identifier
     * @param string $capability Capability to check
     * @return bool True if user has capability
     */
    public function hasCapability(string $user, string $capability): bool;
    
    /**
     * Get user capabilities
     * 
     * @param string $user User identifier
     * @return array List of user capabilities
     */
    public function getUserCapabilities(string $user): array;
    
    /**
     * Add capability to user
     * 
     * @param string $user User identifier
     * @param string $capability Capability to add
     * @return bool Success status
     */
    public function addCapability(string $user, string $capability): bool;
    
    /**
     * Remove capability from user
     * 
     * @param string $user User identifier
     * @param string $capability Capability to remove
     * @return bool Success status
     */
    public function removeCapability(string $user, string $capability): bool;
}