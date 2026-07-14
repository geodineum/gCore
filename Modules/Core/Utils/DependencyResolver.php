<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Utils;

use gCore\Modules\Core\Shared\DependencyBundle;
use gCore\Modules\Core\Exceptions\CircularDependencyException;
use gCore\Modules\Core\Exceptions\ValidationException;
use gCore\Modules\Core\Adapters\Shared\ValKeyStorage;

/**
 * DependencyResolver - Dependency resolution for gCore services
 *
 * This utility class provides dependency resolution with 
 * circular dependency detection and visualization. It can analyze dependency
 * configurations, create load ordering, and provide guidance for resolving
 * circular dependencies.
 *
 * Features:
 * - YAML configuration loading with validation
 * - Circular dependency detection and visualization
 * - Multiple resolution strategies (strict, relaxed, auto-fix)
 * - Integration with ValKey for distributed state
 * - Load order calculation with proper service sequencing
 *
 * @package     gCore
 * @subpackage  Core\Utils
 * @version     0.1.0
 */
class DependencyResolver
{
    /** @var DependencyBundle The dependency bundle */
    private DependencyBundle $bundle;
    
    /** @var ?ValKeyStorage Optional ValKey storage for distributed state */
    private ?ValKeyStorage $storage = null;
    
    /** @var string Storage key prefix */
    private const STORAGE_KEY_PREFIX = 'dependency:resolver:';
    
    /**
     * Constructor
     */
    public function __construct(string $resolutionStrategy = 'strict', ?ValKeyStorage $storage = null)
    {
        $this->bundle = new DependencyBundle($resolutionStrategy);
        $this->storage = $storage;
    }
    
    /**
     * Load dependencies from YAML configuration
     */
    public function loadFromYaml(string $servicesConfig, string $dependenciesConfig): void
    {
        // First check if files exist
        if (!file_exists($servicesConfig)) {
            throw new ValidationException("Services configuration not found: {$servicesConfig}");
        }
        
        if (!file_exists($dependenciesConfig)) {
            throw new ValidationException("Dependencies configuration not found: {$dependenciesConfig}");
        }
        
        // Parse services configuration to get service capability points
        $services = yaml_parse_file($servicesConfig);
        if (!$services || !isset($services['services'])) {
            throw new ValidationException("Invalid services configuration");
        }
        
        $servicePoints = [];
        foreach ($services['services'] as $service) {
            if (!isset($service['id'])) {
                continue;
            }
            
            $point = [];
            if (isset($service['capabilities'])) {
                foreach ($service['capabilities'] as $capability) {
                    if (isset($capability['name']) && isset($capability['value'])) {
                        $point[$capability['name']] = (float) $capability['value'];
                    }
                }
            }
            
            $servicePoints[$service['id']] = $point;
        }
        
        // Parse dependencies configuration
        $dependencies = yaml_parse_file($dependenciesConfig);
        if (!$dependencies || !isset($dependencies['dependencies'])) {
            throw new ValidationException("Invalid dependencies configuration");
        }
        
        // Process dependencies and resolve to actual services
        foreach ($dependencies['dependencies'] as $dep) {
            $serviceId = $dep['service_id'];
            $resolvedDeps = [];
            
            // Process each requirement set
            foreach ($dep['dependencies'] as $reqSet) {
                if (!isset($reqSet['requirements'])) {
                    continue;
                }
                
                // Find best matching service for this requirement set
                $bestMatch = $this->findBestMatch($reqSet['requirements'], $servicePoints, $serviceId);
                if ($bestMatch) {
                    $resolvedDeps[] = $bestMatch;
                }
            }
            
            // Add to bundle
            $this->bundle->addDependencyFiber($serviceId, $resolvedDeps);
        }
        
        // Calculate dependency graph
        try {
            $this->bundle->calculateGraph();
        } catch (CircularDependencyException $e) {
            // If using ValKey, store the error for distributed visualization
            if ($this->storage) {
                $this->storeCircularDependencyError($e);
            }
            throw $e;
        }
    }
    
    /**
     * Find best matching service for a set of requirements
     */
    private function findBestMatch(array $requirements, array $servicePoints, string $currentServiceId): ?string
    {
        $bestMatch = null;
        $bestScore = -1;
        
        foreach ($servicePoints as $serviceId => $capabilities) {
            // Skip self-dependencies
            if ($serviceId === $currentServiceId) {
                continue;
            }
            
            $matches = true;
            $score = 0;
            
            // Check if service satisfies all requirements
            foreach ($requirements as $req) {
                if (!isset($req['name']) || !isset($req['min_value'])) {
                    continue;
                }
                
                $name = $req['name'];
                $minValue = (float) $req['min_value'];
                
                // Service must have the capability and meet minimum value
                if (!isset($capabilities[$name]) || $capabilities[$name] < $minValue) {
                    $matches = false;
                    break;
                }
                
                // Calculate score - higher is better
                $score += $capabilities[$name] / $minValue;
            }
            
            // Update best match if this service is better
            if ($matches && $score > $bestScore) {
                $bestMatch = $serviceId;
                $bestScore = $score;
            }
        }
        
        return $bestMatch;
    }
    
    /**
     * Store circular dependency error for distributed visibility
     */
    private function storeCircularDependencyError(CircularDependencyException $e): void
    {
        try {
            $errorData = [
                'message' => $e->getMessage(),
                'path' => $e->getPath(),
                'visual_path' => $e->getVisualPath(),
                'timestamp' => time()
            ];
            
            $this->storage->set(self::STORAGE_KEY_PREFIX . 'circular_error', json_encode($errorData));
        } catch (\Throwable $th) {
            // Silently fail - we don't want to throw inside an exception handler
        }
    }
    
    /**
     * Get calculated load order
     */
    public function getLoadOrder(): array
    {
        try {
            return $this->bundle->getLoadOrder();
        } catch (ValidationException $e) {
            // Try to calculate the graph first if not already done
            $this->bundle->calculateGraph();
            return $this->bundle->getLoadOrder();
        }
    }
    
    /**
     * Check if a service can be safely accessed without creating a circular dependency
     */
    public function canSafelyAccess(string $fromId, string $toId): bool
    {
        return $this->bundle->canSafelyAccess($fromId, $toId);
    }
    
    /**
     * Generate dependency graph visualization in DOT format (for Graphviz)
     */
    public function generateDependencyGraph(): string
    {
        $fibers = $this->bundle->getDependencyFibers();
        
        $dot = "digraph DependencyGraph {\n";
        $dot .= "  rankdir=LR;\n";
        $dot .= "  node [shape=box, style=filled, fillcolor=lightblue];\n";
        
        // Add all nodes
        foreach (array_keys($fibers) as $serviceId) {
            $dot .= "  \"$serviceId\";\n";
        }
        
        // Add all edges
        foreach ($fibers as $serviceId => $fiber) {
            foreach ($fiber['dependencies'] as $dependencyId) {
                $dot .= "  \"$dependencyId\" -> \"$serviceId\";\n";
            }
        }
        
        $dot .= "}\n";
        
        return $dot;
    }
    
    /**
     * Get dependency fibers
     */
    public function getDependencyFibers(): array
    {
        return $this->bundle->getDependencyFibers();
    }
    
    /**
     * Analyze for potential circular dependencies
     */
    public function analyzeCircularDependencies(): array
    {
        $fibers = $this->bundle->getDependencyFibers();
        $result = [
            'potential_cycles' => [],
            'service_stats' => [],
            'recommendations' => []
        ];
        
        // Get dependency depth for each service
        $depthMap = [];
        foreach ($fibers as $serviceId => $fiber) {
            $depthMap[$serviceId] = $this->calculateDependencyDepth($serviceId);
        }
        
        // Sort services by depth (descending)
        arsort($depthMap);
        
        // Services with the highest depths are more likely to be part of cycles
        $highDepthServices = array_slice($depthMap, 0, 5, true);
        
        foreach ($highDepthServices as $serviceId => $depth) {
            // Find services with mutual dependencies
            $mutualDeps = $this->findMutualDependencies($serviceId);
            
            if (!empty($mutualDeps)) {
                $result['potential_cycles'][] = [
                    'service' => $serviceId,
                    'depth' => $depth,
                    'mutual_dependencies' => $mutualDeps
                ];
                
                // Generate recommendations
                $result['recommendations'][] = "Service '$serviceId' has a high dependency depth ($depth) and mutual dependencies with: " . 
                                             implode(', ', $mutualDeps) . ". Consider refactoring using dependency inversion or mediator pattern.";
            }
        }
        
        // Calculate statistics
        $result['service_stats'] = [
            'total_services' => count($fibers),
            'avg_dependencies' => array_sum(array_map(function ($fiber) {
                return count($fiber['dependencies']);
            }, $fibers)) / max(1, count($fibers)),
            'max_dependency_depth' => max($depthMap ?: [0])
        ];
        
        return $result;
    }
    
    /**
     * Calculate dependency depth for a service
     */
    private function calculateDependencyDepth(string $serviceId, array $visited = []): int
    {
        if (in_array($serviceId, $visited)) {
            // Potential cycle detected
            return 100; // Arbitrary high value to indicate cycle
        }
        
        $fibers = $this->bundle->getDependencyFibers();
        if (!isset($fibers[$serviceId]) || empty($fibers[$serviceId]['dependencies'])) {
            return 0;
        }
        
        $newVisited = array_merge($visited, [$serviceId]);
        $maxDepth = 0;
        
        foreach ($fibers[$serviceId]['dependencies'] as $depId) {
            $depth = $this->calculateDependencyDepth($depId, $newVisited);
            $maxDepth = max($maxDepth, $depth);
        }
        
        return $maxDepth + 1;
    }
    
    /**
     * Find services with mutual dependencies
     */
    private function findMutualDependencies(string $serviceId): array
    {
        $fibers = $this->bundle->getDependencyFibers();
        if (!isset($fibers[$serviceId])) {
            return [];
        }
        
        $mutualDeps = [];
        $dependencies = $fibers[$serviceId]['dependencies'];
        
        foreach ($dependencies as $depId) {
            if (isset($fibers[$depId])) {
                $depDependencies = $fibers[$depId]['dependencies'];
                if (in_array($serviceId, $depDependencies)) {
                    $mutualDeps[] = $depId;
                }
            }
        }
        
        return $mutualDeps;
    }
}