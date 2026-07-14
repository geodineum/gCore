<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Microservice Factory Interface
 * 
 * Defines the contract for microservice factories.
 */
interface MicroserviceFactoryInterface {
    /**
     * Create a microservice instance
     * 
     * @param string $type Microservice type
     * @param array $config Microservice configuration
     * @return MicroserviceInterface|null Microservice instance or null on failure
     */
    public function createMicroservice(string $type, array $config): ?MicroserviceInterface;
    
    /**
     * Get supported microservice types
     * 
     * @return array List of supported microservice types
     */
    public function getSupportedTypes(): array;
    
    /**
     * Check if a microservice type is supported
     * 
     * @param string $type Microservice type
     * @return bool True if type is supported
     */
    public function isTypeSupported(string $type): bool;
}