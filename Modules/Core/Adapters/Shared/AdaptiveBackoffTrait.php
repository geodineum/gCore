<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Adapters\Shared;

/**
 * Adaptive Backoff Trait
 * 
 * Provides intelligent reconnection strategies with adaptive backoff algorithms.
 * - Exponential backoff with jitter for distributed systems
 * - Circuit breaker pattern implementation
 * - Adaptive timeout adjustments
 * - Failure tracking with intelligent retry logic
 */
trait AdaptiveBackoffTrait
{
    /**
     * @var array Failure tracking for backoff calculations
     */
    protected array $backoffTracking = [];
    
    /**
     * @var array Circuit breaker status tracking
     */
    protected array $circuitBreakers = [];
    
    /**
     * @var int Base retry interval in milliseconds
     */
    protected int $baseRetryInterval = 100;
    
    /**
     * @var int Maximum retry interval in milliseconds
     */
    protected int $maxRetryInterval = 30000; // 30 seconds
    
    /**
     * @var int Circuit breaker threshold (number of consecutive failures)
     */
    protected int $circuitBreakerThreshold = 5;
    
    /**
     * @var int Half-open timeout in seconds
     */
    protected int $halfOpenTimeout = 30;
    
    /**
     * Initialize backoff settings from configuration
     *
     * @param array $config Configuration array
     * @return void
     */
    protected function initializeBackoffSettings(array $config): void
    {
        $this->baseRetryInterval = $config['base_retry_interval'] ?? 100;
        $this->maxRetryInterval = $config['max_retry_interval'] ?? 30000;
        $this->circuitBreakerThreshold = $config['circuit_breaker_threshold'] ?? 5;
        $this->halfOpenTimeout = $config['half_open_timeout'] ?? 30;
    }
    
    /**
     * Track a failure for a specific operation
     *
     * @param string $operationId Unique identifier for the operation
     * @return void
     */
    protected function trackFailure(string $operationId): void
    {
        if (!isset($this->backoffTracking[$operationId])) {
            $this->backoffTracking[$operationId] = [
                'failures' => 0,
                'last_failure' => 0,
                'backoff_multiplier' => 1,
                'jitter_factor' => 0
            ];
        }
        
        $this->backoffTracking[$operationId]['failures']++;
        $this->backoffTracking[$operationId]['last_failure'] = microtime(true);
        
        // Exponential backoff with a cap
        $this->backoffTracking[$operationId]['backoff_multiplier'] = min(
            64, // Cap at 64x
            $this->backoffTracking[$operationId]['backoff_multiplier'] * 2
        );
        
        // Add jitter to prevent thundering herd problem
        $this->backoffTracking[$operationId]['jitter_factor'] = mt_rand(80, 120) / 100;
        
        // Update circuit breaker
        $this->updateCircuitBreaker($operationId);
    }
    
    /**
     * Track a success for a specific operation
     *
     * @param string $operationId Unique identifier for the operation
     * @return void
     */
    protected function trackSuccess(string $operationId): void
    {
        if (!isset($this->backoffTracking[$operationId])) {
            return;
        }
        
        // Reset backoff on success
        $this->backoffTracking[$operationId]['failures'] = 0;
        $this->backoffTracking[$operationId]['backoff_multiplier'] = 1;
        $this->backoffTracking[$operationId]['jitter_factor'] = 0;
        
        // Reset circuit breaker
        if (isset($this->circuitBreakers[$operationId])) {
            $this->circuitBreakers[$operationId]['state'] = 'closed';
            $this->circuitBreakers[$operationId]['failures'] = 0;
        }
    }
    
    /**
     * Calculate the next retry delay with exponential backoff and jitter
     *
     * @param string $operationId Unique identifier for the operation
     * @return int Delay in milliseconds
     */
    protected function getRetryDelay(string $operationId): int
    {
        if (!isset($this->backoffTracking[$operationId])) {
            return $this->baseRetryInterval;
        }
        
        $tracking = $this->backoffTracking[$operationId];
        
        // Calculate base delay with exponential backoff
        $delay = $this->baseRetryInterval * $tracking['backoff_multiplier'];
        
        // Apply jitter to prevent synchronized retries in distributed systems
        if ($tracking['jitter_factor'] > 0) {
            $delay = (int)($delay * $tracking['jitter_factor']);
        }
        
        // Ensure we don't exceed the maximum delay
        return min($this->maxRetryInterval, $delay);
    }
    
    /**
     * Update the circuit breaker state
     *
     * @param string $operationId Unique identifier for the operation
     * @return void
     */
    protected function updateCircuitBreaker(string $operationId): void
    {
        if (!isset($this->circuitBreakers[$operationId])) {
            $this->circuitBreakers[$operationId] = [
                'state' => 'closed',
                'failures' => 0,
                'trip_time' => 0
            ];
        }
        
        $breaker = &$this->circuitBreakers[$operationId];
        
        // Increment failures
        $breaker['failures']++;
        
        // Trip the breaker if threshold is exceeded
        if ($breaker['state'] === 'closed' && $breaker['failures'] >= $this->circuitBreakerThreshold) {
            $breaker['state'] = 'open';
            $breaker['trip_time'] = time();
        }
    }
    
    /**
     * Check if an operation should be allowed based on circuit breaker state
     *
     * @param string $operationId Unique identifier for the operation
     * @return bool True if operation is allowed
     */
    protected function isOperationAllowed(string $operationId): bool
    {
        if (!isset($this->circuitBreakers[$operationId])) {
            return true;
        }
        
        $breaker = $this->circuitBreakers[$operationId];
        
        // Always allow if circuit is closed
        if ($breaker['state'] === 'closed') {
            return true;
        }
        
        // Check if it's time to try half-open state
        if ($breaker['state'] === 'open') {
            $timeElapsed = time() - $breaker['trip_time'];
            
            if ($timeElapsed >= $this->halfOpenTimeout) {
                // Transition to half-open state to allow a test operation
                $this->circuitBreakers[$operationId]['state'] = 'half-open';
                return true;
            }
            
            return false;
        }
        
        // In half-open state, only allow one test operation
        return $breaker['state'] === 'half-open';
    }
    
    /**
     * Execute a function with adaptive backoff retry logic
     *
     * @param callable $operation The operation to execute
     * @param string $operationId Unique identifier for the operation
     * @param int $maxRetries Maximum number of retries (0 for no retry)
     * @return mixed The result of the operation
     * @throws \Exception If all retries fail
     */
    protected function executeWithBackoff(callable $operation, string $operationId, int $maxRetries = 3)
    {
        $retries = 0;
        $lastException = null;
        
        do {
            // Check circuit breaker
            if (!$this->isOperationAllowed($operationId)) {
                throw new \Exception("Circuit breaker is open for operation: {$operationId}");
            }
            
            try {
                // Execute the operation
                $result = $operation();
                
                // Track success
                $this->trackSuccess($operationId);
                
                // Return the result
                return $result;
            } catch (\Exception $e) {
                // Track failure
                $this->trackFailure($operationId);
                
                // Store the exception
                $lastException = $e;
                
                // Should we retry?
                if ($retries < $maxRetries) {
                    // Calculate delay with backoff
                    $delay = $this->getRetryDelay($operationId);
                    
                    // Wait before retrying
                    usleep($delay * 1000); // Convert to microseconds
                    
                    $retries++;
                } else {
                    // Max retries exceeded
                    break;
                }
            }
        } while ($retries <= $maxRetries);
        
        // If we reach here, all retries failed
        throw $lastException;
    }
    
    /**
     * Reset all backoff tracking and circuit breakers
     *
     * @return void
     */
    protected function resetBackoffTracking(): void
    {
        $this->backoffTracking = [];
        $this->circuitBreakers = [];
    }
    
    /**
     * Get statistics about backoff and circuit breakers
     *
     * @return array Statistics
     */
    public function getBackoffStats(): array
    {
        return [
            'tracking' => $this->backoffTracking,
            'circuit_breakers' => $this->circuitBreakers,
            'settings' => [
                'base_retry_interval' => $this->baseRetryInterval,
                'max_retry_interval' => $this->maxRetryInterval,
                'circuit_breaker_threshold' => $this->circuitBreakerThreshold,
                'half_open_timeout' => $this->halfOpenTimeout
            ]
        ];
    }
}