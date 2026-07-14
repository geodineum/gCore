<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Services;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Microservice Interface
 * 
 * Defines the contract for microservices.
 */
interface MicroserviceInterface {
    /**
     * Initialize the microservice
     * 
     * @param array $config Microservice configuration
     * @return bool Success status
     */
    public function initialize(array $config): bool;
    
    /**
     * Start the microservice
     * 
     * @return bool Success status
     */
    public function start(): bool;
    
    /**
     * Stop the microservice
     * 
     * @return bool Success status
     */
    public function stop(): bool;
    
    /**
     * Get microservice status
     * 
     * @return array Status information
     */
    public function getStatus(): array;
    
    /**
     * Execute a command
     * 
     * @param string $command Command to execute
     * @param array $params Command parameters
     * @return mixed Command result
     */
    public function execute(string $command, array $params = []);
}