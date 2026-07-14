<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Adapters\Shared;

use Exception;
use Redis;
use gCore\Modules\Core\Exceptions\StorageException;
use gCore\Modules\Core\Shared\SecretsManager;

/**
 * Connection pooling implementation for ValKey/Redis
 * 
 * Provides horizontal scaling capabilities with automatic connection management.
 * - Maintains a pool of connections for better performance
 * - Handles connection distribution across multiple nodes
 * - Implements automatic fallback mechanisms
 * - Optimizes connection reuse
 */
class ValKeyConnectionPool
{
    /**
     * @var array Array of Redis connections
     */
    private array $connections = [];
    
    /**
     * @var array Configuration for the connection pool
     */
    private array $config;
    
    /**
     * @var int Maximum number of connections in the pool
     */
    private int $maxPoolSize;
    
    /**
     * @var int Current number of active connections
     */
    private int $activeConnections = 0;
    
    /**
     * @var array Connection health status
     */
    private array $nodeHealth = [];
    
    /**
     * @var array Connection statistics
     */
    private array $stats = [
        'created' => 0,
        'reused' => 0,
        'failed' => 0,
        'closed' => 0
    ];
    
    /**
     * @var int Base retry interval in seconds
     */
    private int $baseRetryInterval = 5;
    
    /**
     * Constructor
     * 
     * @param array $config Connection pool configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->maxPoolSize = $config['max_pool_size'] ?? 10;
        $this->baseRetryInterval = $config['base_retry_interval'] ?? 5;
        
        // Initialize node health tracking
        if (isset($config['nodes']) && is_array($config['nodes'])) {
            foreach ($config['nodes'] as $index => $node) {
                $this->nodeHealth[$index] = [
                    'status' => 'unknown',
                    'failures' => 0,
                    'last_failure' => 0,
                    'last_success' => 0
                ];
            }
        } else {
            $this->nodeHealth[0] = [
                'status' => 'unknown',
                'failures' => 0,
                'last_failure' => 0,
                'last_success' => 0
            ];
        }
    }
    
    /**
     * Get a connection from the pool with retry capabilities
     * 
     * @param int $maxRetries Maximum number of retries before giving up
     * @param int $retryDelayMs Base delay between retries in milliseconds
     * @return Redis A Redis connection
     * @throws StorageException If no connection could be established after retries
     */
    public function getConnection(int $maxRetries = 2, int $retryDelayMs = 100): Redis
    {
        $attempt = 0;
        $lastException = null;
        
        do {
            try {
                // If this is a retry, add a backoff delay with jitter
                if ($attempt > 0) {
                    // Calculate delay with exponential backoff and jitter
                    $delay = $retryDelayMs * pow(2, $attempt - 1);
                    $jitter = mt_rand(0, (int)($delay * 0.3)); // Add 0-30% jitter
                    $delayWithJitter = $delay + $jitter;
                    
                    // Log the retry attempt if debug is enabled
                    if (getenv('GCORE_DEBUG') === 'true') {
                        error_log("ValKeyConnectionPool: Retry attempt {$attempt} after {$delayWithJitter}ms delay");
                    }
                    
                    // Sleep with microsecond precision
                    usleep($delayWithJitter * 1000);
                }
                
                // Try to reuse an existing connection first
                foreach ($this->connections as $index => $connection) {
                    // Skip failed nodes that haven't passed their retry interval
                    if ($this->isNodeFailed($index)) {
                        continue;
                    }
                    
                    try {
                        // Use a short timeout to prevent hanging on connection issues
                        $oldTimeout = $connection->getTimeout();
                        $connection->setOption(\Redis::OPT_READ_TIMEOUT, 0.5);
                        
                        // Check if connection is still alive
                        $ping = $connection->ping();
                        
                        // Restore original timeout
                        $connection->setOption(\Redis::OPT_READ_TIMEOUT, $oldTimeout);
                        
                        // Different Redis/ValKey versions may return different ping responses
                        if ($ping === '+PONG' || $ping === true || $ping === 1 || $ping === 'PONG') {
                            $this->stats['reused']++;
                            $this->markNodeHealthy($index);
                            return $connection;
                        }
                    } catch (Exception $e) {
                        // Failed connection, remove from pool
                        $this->markNodeUnhealthy($index, $e->getMessage());
                        unset($this->connections[$index]);
                        $this->activeConnections--;
                    }
                }
                
                // No working connection found, create a new one
                return $this->createNewConnection();
                
            } catch (StorageException $e) {
                $lastException = $e;
                $attempt++;
                
                // Log retry information if debug is enabled
                if (getenv('GCORE_DEBUG') === 'true') {
                    error_log("ValKeyConnectionPool: Connection attempt {$attempt} failed: " . $e->getMessage());
                }
            }
        } while ($attempt <= $maxRetries);
        
        // All retries failed
        throw new StorageException(
            "Failed to get ValKey connection after {$maxRetries} retries: " . 
            ($lastException ? $lastException->getMessage() : "Unknown error"),
            $lastException ? $lastException->getCode() : 1001,
            $lastException
        );
    }
    
    /**
     * Create a new connection
     * 
     * @return Redis A new Redis connection
     * @throws StorageException If the connection couldn't be established
     */
    private function createNewConnection(): Redis
    {
        // If we've reached the maximum pool size, close the oldest connection
        if ($this->activeConnections >= $this->maxPoolSize) {
            $this->closeOldestConnection();
        }
        
        // Try nodes in order of their health status
        $nodeIndices = $this->getNodesOrderedByHealth();
        $lastException = null;
        
        foreach ($nodeIndices as $index) {
            try {
                // Skip nodes that haven't passed their retry interval
                if ($this->isNodeFailed($index)) {
                    continue;
                }
                
                $node = $this->getNodeConfig($index);
                $connection = new Redis();
                
                // Configure TLS if needed
                $context = null;
                if (!empty($node['tls']) && $node['tls']['enabled']) {
                    // Try to get TLS settings from SecretsManager first
                    $tlsConfig = $node['tls'];
                    
                    try {
                        if (class_exists('\\gCore\\Modules\\Core\\Shared\\SecretsManager')) {
                            $caFile = SecretsManager::getSecret('cache.tls.ca_file', 'system');
                            $certFile = SecretsManager::getSecret('cache.tls.cert_file', 'system');
                            $keyFile = SecretsManager::getSecret('cache.tls.key_file', 'system');
                            
                            if ($caFile) $tlsConfig['ca_file'] = $caFile;
                            if ($certFile) $tlsConfig['cert_file'] = $certFile;
                            if ($keyFile) $tlsConfig['key_file'] = $keyFile;
                        }
                    } catch (\Exception $e) {
                        // Silently continue with original config
                    }
                    
                    // Try to get TLS settings from environment variables
                    if (!$tlsConfig['ca_file'] && getenv('VALKEY_TLS_CA_FILE')) {
                        $tlsConfig['ca_file'] = getenv('VALKEY_TLS_CA_FILE');
                    }
                    if (!$tlsConfig['cert_file'] && getenv('VALKEY_TLS_CERT_FILE')) {
                        $tlsConfig['cert_file'] = getenv('VALKEY_TLS_CERT_FILE');
                    }
                    if (!$tlsConfig['key_file'] && getenv('VALKEY_TLS_KEY_FILE')) {
                        $tlsConfig['key_file'] = getenv('VALKEY_TLS_KEY_FILE');
                    }
                    
                    $context = stream_context_create([
                        'ssl' => [
                            'verify_peer' => $tlsConfig['verify_peer'] ?? true,
                            'verify_peer_name' => $tlsConfig['verify_peer_name'] ?? true,
                        ]
                    ]);
                    
                    // Add certificates if provided
                    if (!empty($tlsConfig['ca_file'])) {
                        $context['ssl']['cafile'] = $tlsConfig['ca_file'];
                    }
                    if (!empty($tlsConfig['cert_file'])) {
                        $context['ssl']['local_cert'] = $tlsConfig['cert_file'];
                    }
                    if (!empty($tlsConfig['key_file'])) {
                        $context['ssl']['local_pk'] = $tlsConfig['key_file'];
                    }
                }
                
                // Basic connection options
                $connection->setOption(Redis::OPT_READ_TIMEOUT, $node['read_timeout'] ?? -1);
                $connection->setOption(Redis::OPT_RECONNECT, true);
                
                // Connect parameters
                $params = [
                    $node['host'],
                    $node['port'],
                    $node['timeout'] ?? 2.0
                ];
                
                // Add context if TLS is enabled
                if ($context) {
                    $params[] = null; // reserved
                    $params[] = 0; // retry_interval
                    $params[] = $context;
                }
                
                // Connect (persistent or regular)
                $connected = ($node['persistent'] ?? true) 
                    ? $connection->pconnect(...$params)
                    : $connection->connect(...$params);
                
                if (!$connected) {
                    throw new Exception("Connection failed");
                }
                
                // Try to get credentials from SecretsManager first, fallback to config
                $username = null;
                $password = null;
                
                try {
                    // Look for authentication in SecretsManager
                    if (class_exists('\\gCore\\Modules\\Core\\Shared\\SecretsManager')) {
                        $password = SecretsManager::getSecret('cache.pool.password', 'system');
                        
                        // For sentinel we might need a different password
                        if (!empty($node['sentinel']) && $node['sentinel']['enabled']) {
                            $password = SecretsManager::getSecret('cache.sentinel.password', 'system');
                        }
                        
                        // For cluster we might need a different password
                        if (!empty($node['cluster']) && $node['cluster']['enabled']) {
                            $password = SecretsManager::getSecret('cache.cluster.auth', 'system');
                        }
                    }
                } catch (\Exception $e) {
                    error_log("[gCore] ValKeyConnectionPool: SecretsManager lookup failed, falling back to node config values: " . $e->getMessage());
                }
                
                // Fallback to node config
                if ($password === null) {
                    $password = $node['auth'] ?? null;
                }
                
                $username = $node['username'] ?? null;
                
                // If still no password found, check environment variables
                if ($password === null) {
                    if (!empty($node['sentinel']) && $node['sentinel']['enabled'] && getenv('VALKEY_SENTINEL_PASSWORD')) {
                        $password = getenv('VALKEY_SENTINEL_PASSWORD');
                    } elseif (!empty($node['cluster']) && $node['cluster']['enabled'] && getenv('VALKEY_CLUSTER_AUTH')) {
                        $password = getenv('VALKEY_CLUSTER_AUTH');
                    } elseif (getenv('VALKEY_POOL_PASSWORD')) {
                        $password = getenv('VALKEY_POOL_PASSWORD');
                    } elseif (getenv('VALKEY_AUTH')) {
                        $password = getenv('VALKEY_AUTH');
                    } elseif (getenv('GCORE_CACHE_AUTH')) {
                        $password = getenv('GCORE_CACHE_AUTH');
                    }
                }
                
                // Handle authentication if needed
                if ($username && $password) {
                    if (!$connection->auth([$username, $password])) {
                        throw new Exception("Authentication failed");
                    }
                } elseif ($password) {
                    if (!$connection->auth($password)) {
                        throw new Exception("Authentication failed");
                    }
                }
                
                // Select database if needed
                if (isset($node['database']) && $node['database'] !== 0) {
                    if (!$connection->select($node['database'])) {
                        throw new Exception("Database selection failed");
                    }
                }
                
                // Successfully connected
                $this->connections[$index] = $connection;
                $this->activeConnections++;
                $this->stats['created']++;
                $this->markNodeHealthy($index);
                
                return $connection;
                
            } catch (Exception $e) {
                $lastException = $e;
                $this->markNodeUnhealthy($index, $e->getMessage());
                $this->stats['failed']++;
            }
        }
        
        // All nodes failed
        throw new StorageException(
            "Failed to connect to any ValKey node in the pool: " . 
            ($lastException ? $lastException->getMessage() : "Unknown error")
        );
    }
    
    /**
     * Close all connections in the pool
     * 
     * @param bool $graceful Whether to wait for in-flight operations to complete
     * @return void
     */
    public function closeAll(bool $graceful = true): void
    {
        $closeStart = microtime(true);
        $isDebug = getenv('GCORE_DEBUG') === 'true';
        
        if ($isDebug) {
            error_log(sprintf(
                "ValKeyConnectionPool: Closing all connections (count: %d, graceful: %s)",
                count($this->connections),
                $graceful ? 'true' : 'false'
            ));
        }
        
        foreach ($this->connections as $index => $connection) {
            try {
                // Try to execute QUIT command for graceful shutdown if requested
                if ($graceful) {
                    try {
                        // QUIT is more graceful than close()
                        $connection->quit();
                    } catch (Exception $e) {
                        // Fallback to close() if QUIT fails
                        $connection->close();
                    }
                } else {
                    // Force immediate close
                    $connection->close();
                }
                
                $this->stats['closed']++;
                
                if ($isDebug) {
                    error_log("ValKeyConnectionPool: Successfully closed connection {$index}");
                }
            } catch (Exception $e) {
                if ($isDebug) {
                    error_log("ValKeyConnectionPool: Error closing connection {$index}: " . $e->getMessage());
                }
                // Continue with other connections even if this one fails to close
            }
        }
        
        $this->connections = [];
        $this->activeConnections = 0;
        
        // Update metrics for shutdown time
        $closeTime = microtime(true) - $closeStart;
        if ($isDebug) {
            error_log(sprintf(
                "ValKeyConnectionPool: All connections closed in %.4f seconds",
                $closeTime
            ));
        }
    }
    
    /**
     * Register a shutdown function to ensure connections are properly closed
     * 
     * @return void
     */
    public function registerShutdownHandler(): void
    {
        // Use static function to avoid memory leaks with closures
        register_shutdown_function([self::class, 'staticShutdownHandler'], $this);
    }
    
    /**
     * Static shutdown handler to avoid memory leaks
     * 
     * @param ValKeyConnectionPool $pool The connection pool instance
     * @return void
     */
    public static function staticShutdownHandler(ValKeyConnectionPool $pool): void
    {
        $pool->closeAll(true);
    }
    
    /**
     * Close the oldest connection in the pool
     */
    private function closeOldestConnection(): void
    {
        if (empty($this->connections)) {
            return;
        }
        
        // Get the first connection (oldest)
        $index = array_key_first($this->connections);
        $connection = $this->connections[$index];
        
        try {
            $connection->close();
            $this->stats['closed']++;
        } catch (Exception $e) {
            // Ignore errors on close
        }
        
        unset($this->connections[$index]);
        $this->activeConnections--;
    }
    
    /**
     * Get connection pool statistics
     * 
     * @return array Pool statistics
     */
    public function getPoolStats(): array
    {
        return [
            'active_connections' => $this->activeConnections,
            'max_pool_size' => $this->maxPoolSize,
            'connections_created' => $this->stats['created'],
            'connections_reused' => $this->stats['reused'],
            'connections_failed' => $this->stats['failed'],
            'connections_closed' => $this->stats['closed'],
            'node_health' => $this->nodeHealth
        ];
    }
    
    /**
     * Get node configuration by index
     * 
     * @param int $index Node index
     * @return array Node configuration
     */
    private function getNodeConfig(int $index): array
    {
        // Use specific node if available
        if (isset($this->config['nodes'][$index])) {
            return $this->config['nodes'][$index];
        }
        
        // Fall back to default configuration
        $host = $this->config['host'] ?? null;
        $port = $this->config['port'] ?? null;
        if (empty($host) || empty($port)) {
            throw new \RuntimeException(
                'ValKeyConnectionPool requires host and port. Set VALKEY_HOST/VALKEY_PORT env vars or pass in config.'
            );
        }
        return [
            'host' => $host,
            'port' => $port,
            'timeout' => $this->config['timeout'] ?? 2.0,
            'read_timeout' => $this->config['read_timeout'] ?? -1,
            'auth' => $this->config['auth'] ?? null,
            'username' => $this->config['username'] ?? null,
            'database' => $this->config['database'] ?? 0,
            'persistent' => $this->config['persistent'] ?? true,
            'tls' => $this->config['tls'] ?? ['enabled' => false]
        ];
    }
    
    /**
     * Mark a node as healthy
     * 
     * @param int $index Node index
     */
    private function markNodeHealthy(int $index): void
    {
        if (!isset($this->nodeHealth[$index])) {
            $this->nodeHealth[$index] = [
                'status' => 'unknown',
                'failures' => 0,
                'last_failure' => 0,
                'last_success' => 0
            ];
        }
        
        $this->nodeHealth[$index]['status'] = 'healthy';
        $this->nodeHealth[$index]['last_success'] = time();
    }
    
    /**
     * Mark a node as unhealthy
     * 
     * @param int $index Node index
     * @param string $reason Reason for the failure
     */
    private function markNodeUnhealthy(int $index, string $reason): void
    {
        if (!isset($this->nodeHealth[$index])) {
            $this->nodeHealth[$index] = [
                'status' => 'unknown',
                'failures' => 0,
                'last_failure' => 0,
                'last_success' => 0
            ];
        }
        
        $this->nodeHealth[$index]['status'] = 'unhealthy';
        $this->nodeHealth[$index]['failures']++;
        $this->nodeHealth[$index]['last_failure'] = time();
        $this->nodeHealth[$index]['last_reason'] = $reason;
    }
    
    /**
     * Check if a node is failed and hasn't passed its retry interval
     * 
     * @param int $index Node index
     * @return bool True if the node is failed and shouldn't be tried yet
     */
    private function isNodeFailed(int $index): bool
    {
        if (!isset($this->nodeHealth[$index])) {
            return false;
        }
        
        if ($this->nodeHealth[$index]['status'] !== 'unhealthy') {
            return false;
        }
        
        // Calculate retry interval with exponential backoff
        $failures = $this->nodeHealth[$index]['failures'];
        $retryInterval = $this->baseRetryInterval * (2 ** min(5, $failures - 1));
        
        // Check if enough time has passed since the last failure
        $now = time();
        $timeSinceFailure = $now - $this->nodeHealth[$index]['last_failure'];
        
        return $timeSinceFailure < $retryInterval;
    }
    
    /**
     * Get node indices ordered by health status
     * 
     * @return array Ordered node indices
     */
    private function getNodesOrderedByHealth(): array
    {
        $healthy = [];
        $unknown = [];
        $unhealthy = [];
        
        foreach ($this->nodeHealth as $index => $health) {
            switch ($health['status']) {
                case 'healthy':
                    $healthy[] = $index;
                    break;
                case 'unknown':
                    $unknown[] = $index;
                    break;
                case 'unhealthy':
                    $unhealthy[] = $index;
                    break;
            }
        }
        
        // Return nodes ordered by health status: healthy first, then unknown, then unhealthy
        return array_merge($healthy, $unknown, $unhealthy);
    }
}