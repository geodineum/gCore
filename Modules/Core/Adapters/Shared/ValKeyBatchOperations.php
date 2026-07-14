<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Adapters\Shared;

use gCore\Modules\Core\Exceptions\StorageException;

/**
 * ValKey Batch Operations Trait
 * 
 * Provides batch processing, pipelines, and transactions for ValKey operations
 * Optimizes data processing through batching, pipelining and transaction support
 */
trait ValKeyBatchOperations {
    /**
     * Execute commands in a pipeline for improved performance
     * 
     * @param array $operations Array of [command, [args]] operations
     * @return array Results in the same order as operations
     * @throws StorageException On execution failure
     */
    public function pipeline(array $operations): array {
        if (!$this->initialized || $this->isCircuitOpen()) {
            throw new StorageException("Connection not available");
        }
        
        if (empty($operations)) {
            return [];
        }
        
        // Validate batch size
        if (count($operations) > $this->limits['max_batch_size']) {
            throw new StorageException("Pipeline size exceeds limit ({$this->limits['max_batch_size']})");
        }
        
        try {
            // Start pipeline mode
            $pipeline = $this->connection->pipeline();
            
            // Safety check for permitted commands
            $safeCommands = ['GET', 'SET', 'DEL', 'EXISTS', 'EXPIRE', 'TTL', 'INCR', 'DECR', 'HINCRBY', 'HGET', 'HSET', 'HDEL', 'INFO', 'PING'];
            
            // Add all operations to the pipeline
            foreach ($operations as $op) {
                [$command, $args] = $op;
                $command = strtoupper($command);
                
                // Security check
                if (!in_array($command, $safeCommands)) {
                    throw new StorageException("Command not allowed in pipeline: {$command}");
                }
                
                // Add operation to pipeline
                if (method_exists($pipeline, strtolower($command))) {
                    $pipeline->{strtolower($command)}(...$args);
                } else {
                    $pipeline->rawCommand($command, ...$args);
                }
            }
            
            // Execute pipeline and return results
            return $pipeline->exec();
            
        } catch (\Exception $e) {
            $this->recordOperationFailure('pipeline', $e);
            throw new StorageException("Pipeline execution failed: {$e->getMessage()}");
        }
    }
    
    /**
     * Execute commands in a transaction (atomic)
     * 
     * @param array $operations Array of [command, [args]] operations
     * @return array|bool Results in the same order as operations, or false if transaction failed
     * @throws StorageException On execution failure
     */
    public function transaction(array $operations): array {
        if (!$this->initialized || $this->isCircuitOpen()) {
            throw new StorageException("Connection not available");
        }
        
        if (empty($operations)) {
            return [];
        }
        
        // Validate batch size
        if (count($operations) > $this->limits['max_batch_size']) {
            throw new StorageException("Transaction size exceeds limit ({$this->limits['max_batch_size']})");
        }
        
        try {
            // Start transaction mode
            $transaction = $this->connection->multi();
            
            // Safety check for permitted commands
            $safeCommands = ['GET', 'SET', 'DEL', 'EXISTS', 'EXPIRE', 'TTL', 'INCR', 'DECR', 'HINCRBY', 'HGET', 'HSET', 'HDEL'];
            
            // Add all operations to the transaction
            foreach ($operations as $op) {
                [$command, $args] = $op;
                $command = strtoupper($command);
                
                // Security check
                if (!in_array($command, $safeCommands)) {
                    throw new StorageException("Command not allowed in transaction: {$command}");
                }
                
                // Add operation to transaction
                if (method_exists($transaction, strtolower($command))) {
                    $transaction->{strtolower($command)}(...$args);
                } else {
                    $transaction->rawCommand($command, ...$args);
                }
            }
            
            // Execute transaction and return results
            return $transaction->exec();
            
        } catch (\Exception $e) {
            $this->recordOperationFailure('transaction', $e);
            throw new StorageException("Transaction execution failed: {$e->getMessage()}");
        }
    }
    
    /**
     * Execute multiple operations in an optimal batch
     * Automatically chooses between pipeline, transaction, or Lua script
     * 
     * @param array $operations Array of operations to perform
     * @param bool $atomic Whether operations need to be atomic
     * @return array Results of operations
     * @throws StorageException On execution failure
     */
    public function batch(array $operations, bool $atomic = false): array {
        if (empty($operations)) {
            return [];
        }
        
        // Validate batch size
        if (count($operations) > $this->limits['max_batch_size']) {
            throw new StorageException("Batch size exceeds limit ({$this->limits['max_batch_size']})");
        }
        
        // For multi-key operations with atomicity required, use Lua script
        if ($atomic && count($operations) > 1) {
            return $this->batchWithLuaScript($operations);
        }
        
        // For non-atomic operations, use pipeline
        if (!$atomic) {
            return $this->pipeline($operations);
        }
        
        // Default to transaction for atomic operations
        return $this->transaction($operations);
    }
    
    /**
     * Execute batch operations with a Lua script for atomicity
     * 
     * @param array $operations Array of operations
     * @return array Results of operations
     * @throws StorageException On execution failure
     */
    private function batchWithLuaScript(array $operations): array {
        try {
            // Format operations for the script
            $scriptArgs = ['BATCH_EXEC'];
            $keys = [];
            $args = [$this->siteId, json_encode($operations)];
            
            return json_decode($this->runScript('BATCH_EXEC', $keys, $args), true) ?: [];
            
        } catch (\Exception $e) {
            $this->recordOperationFailure('batch', $e);
            throw new StorageException("Batch execution failed: {$e->getMessage()}");
        }
    }
    
    /**
     * Set multiple values in a single operation
     * 
     * @param array $data Key-value pairs to set
     * @param int|null $ttl TTL in seconds (optional)
     * @param string|null $group Group name (optional)
     * @return int Number of values set
     * @throws StorageException On execution failure
     */
    public function mset(array $data, ?int $ttl = null, ?string $group = null): int {
        if (empty($data)) {
            return 0;
        }
        
        // Build operations array for batch processing
        $operations = [];
        foreach ($data as $key => $value) {
            $operations[] = ['SET', [$this->buildKey($key), $value, $ttl ?: 0, $group ?: '']];
        }
        
        try {
            // Use pipeline for better performance
            $results = $this->pipeline($operations);
            return count(array_filter($results));
        } catch (\Exception $e) {
            throw new StorageException("Multi-set operation failed: {$e->getMessage()}");
        }
    }
    
    /**
     * Get multiple values in a single operation
     * 
     * @param array $keys Keys to get
     * @param string|null $group Group name (optional)
     * @return array Key-value pairs of found items
     * @throws StorageException On execution failure
     */
    public function mget(array $keys, ?string $group = null): array {
        if (empty($keys)) {
            return [];
        }
        
        // Build operations array for batch processing
        $operations = [];
        foreach ($keys as $key) {
            $operations[] = ['GET', [$this->buildKey($key)]];
        }
        
        try {
            // Use pipeline for better performance
            $results = $this->pipeline($operations);
            
            // Combine results with original keys
            $combined = [];
            foreach ($keys as $i => $key) {
                if (isset($results[$i]) && $results[$i] !== false) {
                    $combined[$key] = $results[$i];
                }
            }
            
            return $combined;
        } catch (\Exception $e) {
            throw new StorageException("Multi-get operation failed: {$e->getMessage()}");
        }
    }
    
    /**
     * Delete multiple keys in a single operation
     * 
     * @param array $keys Keys to delete
     * @param string|null $group Group name (optional)
     * @return int Number of keys deleted
     * @throws StorageException On execution failure
     */
    public function mdel(array $keys, ?string $group = null): int {
        if (empty($keys)) {
            return 0;
        }
        
        // Build operations array for batch processing
        $operations = [];
        foreach ($keys as $key) {
            $operations[] = ['DEL', [$this->buildKey($key)]];
        }
        
        try {
            // Use pipeline for better performance
            $results = $this->pipeline($operations);
            return array_sum($results);
        } catch (\Exception $e) {
            throw new StorageException("Multi-delete operation failed: {$e->getMessage()}");
        }
    }
}