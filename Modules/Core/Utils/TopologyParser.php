<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Utils;

/**
 * Utility class for parsing service topology configuration
 */
class TopologyParser {
    /**
     * Check if a service is required
     * 
     * @param string $service Service name
     * @return bool Whether the service is required
     */
    public static function isServiceRequired(string $service): bool {
        // For testing, treat all base managers as required
        $requiredServices = [
            'ErrorManager',
            'CacheManager',
            'SecurityManager',
            'APIManager'
        ];
        
        return in_array($service, $requiredServices);
    }
    
    /**
     * Get service type
     * 
     * @param string $service Service name
     * @return string Service type (singleton, microservice)
     */
    public static function getServiceType(string $service): string {
        // For testing, treat all managers as singletons
        return 'singleton';
    }
    
    /**
     * Get service implementation class
     * 
     * @param string $service Service name
     * @return string Implementation class
     */
    public static function getServiceImplementation(string $service): string {
        $implementations = [
            'ErrorManager' => 'gCore\\Modules\\Managers\\Base\\ErrorManager\\ErrorManager',
            'CacheManager' => 'gCore\\Modules\\Managers\\Base\\CacheManager\\CacheManager',
            'SecurityManager' => 'gCore\\Modules\\Managers\\Base\\SecurityManager\\SecurityManager',
            'APIManager' => 'gCore\\Modules\\Managers\\Base\\APIManager\\APIManager'
        ];
        
        return $implementations[$service] ?? 'stdClass';
    }
    
    /**
     * Resolve service dependencies
     * 
     * @param string $service Service name
     * @return array List of dependency services
     */
    public static function resolveDependencies(string $service): array {
        // Define the dependency graph for core managers
        $dependencies = [
            'ErrorManager' => [],
            'CacheManager' => ['ErrorManager'],
            'SecurityManager' => ['ErrorManager'],
            'APIManager' => ['ErrorManager', 'SecurityManager']
        ];
        
        return $dependencies[$service] ?? [];
    }
}