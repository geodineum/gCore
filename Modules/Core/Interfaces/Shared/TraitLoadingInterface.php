<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait Loading Interface
 * 
 * Defines the contract for dynamic trait loading systems.
 */
interface TraitLoadingInterface {
    /**
     * Initialize traits
     * 
     * @param array $traits Trait configurations
     * @return bool Success status
     */
    public function initializeTraits(array $traits): bool;
    
    /**
     * Load trait
     * 
     * @param string $trait Trait name
     * @param array $config Trait configuration
     * @return bool Success status
     */
    public function loadTrait(string $trait, array $config): bool;
    
    /**
     * Unload trait
     * 
     * @param string $trait Trait name
     * @return bool Success status
     */
    public function unloadTrait(string $trait): bool;
    
    /**
     * Check if trait is active
     * 
     * @param string $trait Trait name
     * @return bool True if trait is active
     */
    public function hasActiveTrait(string $trait): bool;
    
    /**
     * Get all active traits
     * 
     * @return array List of active traits
     */
    public function getActiveTraits(): array;
    
    /**
     * Get trait configuration
     * 
     * @param string $trait Trait name
     * @return array|null Trait configuration or null if not found
     */
    public function getTraitConfig(string $trait): ?array;
}