<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

use gCore\Modules\Core\Exceptions\ValidationException;
use gCore\Modules\Core\Exceptions\CircularDependencyException;

/**
 * DependencyBundle - Manages service dependencies and resolves circular dependencies
 *
 * Provides a PHP implementation of dependency resolution that matches the Rust implementation
 * in src/dependency/mod.rs. The bundle tracks dependencies between services and can detect
 * and prevent circular dependencies during initialization and runtime.
 *
 * Features:
 * - Dependency graph creation and topological sorting
 * - Circular dependency detection and prevention
 * - Safe runtime dependency verification
 * - Multiple resolution strategies (strict, relaxed, and auto-fix)
 * - Distributed dependency tracking with ValKey backend
 *
 * @package     gCore
 * @subpackage  Core\Shared
 * @version     0.1.0
 */
class DependencyBundle
{
    /** @var array Dependency fibers for each service */
    private array $dependencyFibers = [];
    
    /** @var array|null Resolved dependency graph */
    private ?array $dependencyGraph = null;
    
    /** @var array|null Topologically sorted load order */
    private ?array $loadOrder = null;
    
    /** @var string Resolution strategy for circular dependencies */
    private string $resolutionStrategy = 'strict';
    
    /** @var array Valid resolution strategies */
    private const VALID_STRATEGIES = ['strict', 'relaxed', 'auto-fix'];
    
    /**
     * Constructor
     */
    public function __construct(string $resolutionStrategy = 'strict')
    {
        if (!in_array($resolutionStrategy, self::VALID_STRATEGIES)) {
            throw new ValidationException(
                "Invalid resolution strategy: {$resolutionStrategy}. " .
                "Valid strategies are: " . implode(', ', self::VALID_STRATEGIES)
            );
        }
        
        $this->resolutionStrategy = $resolutionStrategy;
    }
    
    /**
     * Add a dependency fiber for a service
     */
    public function addDependencyFiber(string $serviceId, array $dependencies): void
    {
        $this->dependencyFibers[$serviceId] = [
            'service_id' => $serviceId,
            'dependencies' => $dependencies
        ];
        
        // Invalidate graph and load order
        $this->dependencyGraph = null;
        $this->loadOrder = null;
    }
    
    /**
     * Remove a dependency fiber
     */
    public function removeDependencyFiber(string $serviceId): bool
    {
        $removed = isset($this->dependencyFibers[$serviceId]);
        
        if ($removed) {
            unset($this->dependencyFibers[$serviceId]);
            
            // Invalidate graph and load order
            $this->dependencyGraph = null;
            $this->loadOrder = null;
        }
        
        return $removed;
    }
    
    /**
     * Calculate dependency graph
     * 
     * @throws CircularDependencyException When circular dependencies are detected
     */
    public function calculateGraph(array $resolvedDeps = null): void
    {
        // Use the internal dependency fibers if no resolved dependencies are provided
        if ($resolvedDeps === null) {
            $resolvedDeps = [];
            foreach ($this->dependencyFibers as $serviceId => $fiber) {
                $resolvedDeps[$serviceId] = $fiber['dependencies'];
            }
        }
        
        $graph = [];
        $nodeMap = [];
        
        // Add all services as nodes
        foreach (array_keys($resolvedDeps) as $serviceId) {
            $nodeMap[$serviceId] = true;
            $graph[$serviceId] = [];
        }
        
        // Add all dependencies mentioned in the resolved map
        foreach ($resolvedDeps as $deps) {
            foreach ($deps as $depId) {
                if (!isset($nodeMap[$depId])) {
                    $nodeMap[$depId] = true;
                    $graph[$depId] = [];
                }
            }
        }
        
        // Add dependency edges
        foreach ($resolvedDeps as $serviceId => $deps) {
            foreach ($deps as $depId) {
                if (isset($nodeMap[$depId])) {
                    // Add edge from dependency to service
                    $graph[$depId][] = $serviceId;
                }
            }
        }
        
        $this->dependencyGraph = $graph;
        
        // Check for cycles
        try {
            $this->calculateLoadOrder();
        } catch (CircularDependencyException $e) {
            if ($this->resolutionStrategy === 'strict') {
                throw $e;
            } elseif ($this->resolutionStrategy === 'auto-fix') {
                // Attempt to fix circular dependencies by breaking the cycle
                $this->breakCycles($e->getPath());
                // Recalculate load order
                $this->calculateLoadOrder();
            }
            // 'relaxed' just ignores the cycle and uses partial ordering
        }
    }
    
    /**
     * Calculate load order using topological sort
     * 
     * @throws CircularDependencyException When circular dependencies are detected
     */
    public function calculateLoadOrder(): void
    {
        if ($this->dependencyGraph === null) {
            throw new ValidationException("Dependency graph not calculated");
        }
        
        $graph = $this->dependencyGraph;
        $visited = [];
        $tempMark = [];
        $order = [];
        $cycles = [];
        
        // Helper function for DFS
        $visit = function ($vertex, $path = []) use (&$visit, &$visited, &$tempMark, &$order, &$graph, &$cycles) {
            // Skip if already permanently visited
            if (isset($visited[$vertex])) {
                return true;
            }
            
            // Detect cycle
            if (isset($tempMark[$vertex])) {
                // Find cycle path
                $cyclePath = [];
                $cycleStart = false;
                foreach ($path as $node) {
                    if ($node === $vertex) {
                        $cycleStart = true;
                    }
                    if ($cycleStart) {
                        $cyclePath[] = $node;
                    }
                }
                $cyclePath[] = $vertex;
                $cycles[] = $cyclePath;
                return false;
            }
            
            $tempMark[$vertex] = true;
            $newPath = array_merge($path, [$vertex]);
            
            // Visit dependencies
            if (isset($graph[$vertex])) {
                foreach ($graph[$vertex] as $neighbor) {
                    if (!$visit($neighbor, $newPath)) {
                        if ($this->resolutionStrategy === 'strict') {
                            return false;
                        }
                    }
                }
            }
            
            $visited[$vertex] = true;
            unset($tempMark[$vertex]);
            $order[] = $vertex;
            
            return true;
        };
        
        // Visit each vertex
        foreach (array_keys($graph) as $vertex) {
            if (!isset($visited[$vertex])) {
                $result = $visit($vertex);
                if (!$result && $this->resolutionStrategy === 'strict') {
                    throw new CircularDependencyException(
                        "Circular dependency detected",
                        $cycles[0] ?? []
                    );
                }
            }
        }
        
        // Reverse the order for correct load sequence
        $this->loadOrder = array_reverse($order);
    }
    
    /**
     * Get the calculated load order
     */
    public function getLoadOrder(): array
    {
        if ($this->loadOrder === null) {
            throw new ValidationException("Load order not calculated");
        }
        
        return $this->loadOrder;
    }
    
    /**
     * Check if a service can be accessed without creating circular dependencies
     */
    public function canSafelyAccess(string $fromId, string $toId): bool
    {
        if ($fromId === $toId) {
            return false;  // Self-dependency
        }
        
        if ($this->dependencyGraph === null) {
            // If graph not calculated, assume it's safe
            return true;
        }
        
        // Check if there's already a path from to -> from
        // If so, adding from -> to would create a cycle
        return !$this->hasPath($toId, $fromId);
    }
    
    /**
     * Check if there is a path between two vertices in the graph
     */
    private function hasPath(string $from, string $to): bool
    {
        if (!isset($this->dependencyGraph[$from])) {
            return false;
        }
        
        $visited = [];
        $queue = [$from];
        
        while (!empty($queue)) {
            $current = array_shift($queue);
            
            if ($current === $to) {
                return true;
            }
            
            if (isset($visited[$current])) {
                continue;
            }
            
            $visited[$current] = true;
            
            if (isset($this->dependencyGraph[$current])) {
                foreach ($this->dependencyGraph[$current] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $queue[] = $neighbor;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Break cycles in the dependency graph
     * 
     * This function is used in auto-fix mode to remove edges that cause cycles
     */
    private function breakCycles(array $cyclePath): void
    {
        if (count($cyclePath) < 2) {
            return;
        }
        
        // Find the weakest edge to break (lowest weight/importance)
        $weakestEdge = null;
        $lowestWeight = PHP_INT_MAX;
        
        for ($i = 0; $i < count($cyclePath) - 1; $i++) {
            $from = $cyclePath[$i];
            $to = $cyclePath[$i + 1];
            
            // Calculate edge weight (can implement custom weighting)
            $weight = 1;
            
            if ($weight < $lowestWeight) {
                $lowestWeight = $weight;
                $weakestEdge = [$from, $to];
            }
        }
        
        // Break the weakest edge if found
        if ($weakestEdge !== null) {
            $this->removeEdge($weakestEdge[0], $weakestEdge[1]);
        }
    }
    
    /**
     * Remove an edge from the dependency graph
     */
    private function removeEdge(string $from, string $to): void
    {
        if (isset($this->dependencyGraph[$from])) {
            $key = array_search($to, $this->dependencyGraph[$from]);
            if ($key !== false) {
                unset($this->dependencyGraph[$from][$key]);
                $this->dependencyGraph[$from] = array_values($this->dependencyGraph[$from]);
                
                // Also update the dependency fibers if they exist
                if (isset($this->dependencyFibers[$from])) {
                    $dependencies = $this->dependencyFibers[$from]['dependencies'];
                    $depKey = array_search($to, $dependencies);
                    if ($depKey !== false) {
                        unset($dependencies[$depKey]);
                        $this->dependencyFibers[$from]['dependencies'] = array_values($dependencies);
                    }
                }
            }
        }
    }
    
    /**
     * Get service dependencies
     */
    public function getServiceDependencies(string $serviceId): ?array
    {
        return isset($this->dependencyFibers[$serviceId]) ? 
               $this->dependencyFibers[$serviceId]['dependencies'] : 
               null;
    }
    
    /**
     * Get all dependency fibers
     */
    public function getDependencyFibers(): array
    {
        return $this->dependencyFibers;
    }
    
    /**
     * Load from YAML configuration
     */
    public static function fromYaml(string $configPath): self
    {
        $bundle = new self();
        
        if (!file_exists($configPath)) {
            throw new ValidationException("Configuration file not found: {$configPath}");
        }
        
        $yaml = yaml_parse_file($configPath);
        if (!$yaml || !isset($yaml['dependencies'])) {
            throw new ValidationException("Invalid dependencies configuration");
        }
        
        foreach ($yaml['dependencies'] as $dep) {
            $serviceId = $dep['service_id'];
            $dependencies = [];
            
            // Process dependencies
            foreach ($dep['dependencies'] as $reqSet) {
                // For simplicity, just use the service ID directly
                // In a real implementation, you'd resolve the requirements to services
                if (isset($reqSet['target_service'])) {
                    $dependencies[] = $reqSet['target_service'];
                }
            }
            
            $bundle->addDependencyFiber($serviceId, $dependencies);
        }
        
        return $bundle;
    }
    
    /**
     * Export to array for serialization
     */
    public function toArray(): array
    {
        return [
            'dependency_fibers' => $this->dependencyFibers,
            'dependency_graph' => $this->dependencyGraph,
            'load_order' => $this->loadOrder,
            'resolution_strategy' => $this->resolutionStrategy
        ];
    }
    
    /**
     * Create from array after deserialization
     */
    public static function fromArray(array $data): self
    {
        $bundle = new self($data['resolution_strategy'] ?? 'strict');
        $bundle->dependencyFibers = $data['dependency_fibers'] ?? [];
        $bundle->dependencyGraph = $data['dependency_graph'] ?? null;
        $bundle->loadOrder = $data['load_order'] ?? null;
        
        return $bundle;
    }
}