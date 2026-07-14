<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Adapters\Shared;

use gCore\Modules\Core\Interfaces\Shared\StorageInterface;
use gCore\Modules\Core\Exceptions\StorageException;
use gCore\Modules\Core\Exceptions\RateLimitException;

/**
 * ValKey Storage Implementation
 * 
 * Provides storage capabilities using ValKey (Redis).
 */
class ValKeyStorage implements StorageInterface {
    /**
     * ValKey connection
     */
    private $connection = null;
    
    /**
     * Configuration
     */
    private $config = [];
    
    /**
     * Initialization state
     */
    private $initialized = false;
    
    /**
     * Constructor
     * 
     * @param array $config Configuration options
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'host' => null,
            'port' => null,
            'timeout' => 2.0,
            'prefix' => '',
            'user' => null,
            'auth' => null,
            'database' => 0
        ], $config);
        
        $this->initialize();
    }
    
    /**
     * Initialize connection
     * 
     * @return void
     * @throws StorageException If connection fails
     */
    /**
     * Load configuration from environment variables if available
     *
     * @return void
     */
    private function loadEnvConfig(): void {
        // Check for .env file in project root
        $envFile = dirname(__DIR__, 4) . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Process valid lines with format KEY=VALUE
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Remove quotes if they exist
                    if (preg_match('/^"(.+)"$/', $value, $matches) || preg_match("/^'(.+)'$/", $value, $matches)) {
                        $value = $matches[1];
                    }
                    
                    // Set environment variable if not already set
                    if (!getenv($key)) {
                        putenv("$key=$value");
                    }
                }
            }
        }
        
        // Load configuration from environment variables
        $envVars = [
            'VALKEY_HOST' => 'host',
            'VALKEY_PORT' => 'port',
            'VALKEY_TIMEOUT' => 'timeout',
            'VALKEY_USER' => 'user',
            'VALKEY_AUTH' => 'auth',
            'VALKEY_DATABASE' => 'database',
            'VALKEY_PREFIX' => 'prefix',
            'VALKEY_TLS_ENABLED' => ['tls', 'enabled'],
            'VALKEY_TLS_VERIFY_PEER' => ['tls', 'verify_peer'],
            'VALKEY_TLS_CA_FILE' => ['tls', 'ca_file'],
            'VALKEY_TLS_CERT_FILE' => ['tls', 'cert_file'],
            'VALKEY_TLS_KEY_FILE' => ['tls', 'key_file'],
        ];
        
        foreach ($envVars as $envVar => $configKey) {
            $value = getenv($envVar);
            if ($value !== false) {
                if (is_array($configKey)) {
                    // Handle nested configuration
                    $primary = $configKey[0];
                    $secondary = $configKey[1];
                    
                    if (!isset($this->config[$primary])) {
                        $this->config[$primary] = [];
                    }
                    
                    // Convert certain string values to proper types
                    if (strtolower($value) === 'true') {
                        $value = true;
                    } elseif (strtolower($value) === 'false') {
                        $value = false;
                    } elseif (strtolower($value) === 'null') {
                        $value = null;
                    } elseif (is_numeric($value)) {
                        $value = (strpos($value, '.') !== false) ? (float)$value : (int)$value;
                    }
                    
                    $this->config[$primary][$secondary] = $value;
                } else {
                    // Handle direct configuration
                    
                    // Convert certain string values to proper types
                    if (strtolower($value) === 'true') {
                        $value = true;
                    } elseif (strtolower($value) === 'false') {
                        $value = false;
                    } elseif (strtolower($value) === 'null') {
                        $value = null;
                    } elseif (is_numeric($value)) {
                        $value = (strpos($value, '.') !== false) ? (float)$value : (int)$value;
                    }
                    
                    $this->config[$configKey] = $value;
                }
            }
        }
    }
    
    /**
     * Initialize connection
     * 
     * @return void
     * @throws StorageException If connection fails
     */
    private function initialize(): void {
        try {
            // Load configuration from environment variables
            $this->loadEnvConfig();

            // Validate that host and port are configured
            if (empty($this->config['host']) || empty($this->config['port'])) {
                throw new StorageException(
                    'ValKeyStorage requires host and port. Set VALKEY_HOST/VALKEY_PORT env vars '
                    . 'or pass host/port in config.',
                    1001
                );
            }

            // Check for PHP Redis extension
            if (!extension_loaded('redis')) {
                throw new StorageException(
                    'PHP Redis extension is not loaded - ValKey integration requires this extension', 
                    1003
                );
            }
            
            $this->connection = new \Redis();
            
            // Enable connection debugging
            $isDebug = defined('WP_DEBUG') && WP_DEBUG || 
                       getenv('GCORE_DEBUG') === 'true' || 
                       ($this->config['debug'] ?? false);
            
            if ($isDebug) {
                error_log("ValKey: Connecting to {$this->config['host']}:{$this->config['port']} with timeout {$this->config['timeout']}");
            }
            
            // Prepare TLS context if needed
            $context = null;
            if (!empty($this->config['tls']) && ($this->config['tls']['enabled'] ?? false)) {
                $tlsConfig = $this->config['tls'];
                
                $contextOptions = [
                    'verify_peer' => $tlsConfig['verify_peer'] ?? true,
                    'verify_peer_name' => $tlsConfig['verify_peer_name'] ?? true,
                ];
                
                // Add certificates if provided
                if (!empty($tlsConfig['ca_file'])) {
                    $contextOptions['cafile'] = $tlsConfig['ca_file'];
                }
                if (!empty($tlsConfig['cert_file'])) {
                    $contextOptions['local_cert'] = $tlsConfig['cert_file'];
                }
                if (!empty($tlsConfig['key_file'])) {
                    $contextOptions['local_pk'] = $tlsConfig['key_file'];
                }
                
                $context = stream_context_create(['ssl' => $contextOptions]);
                
                if ($isDebug) {
                    error_log("ValKey: TLS enabled with verification " . 
                        ($contextOptions['verify_peer'] ? 'enabled' : 'disabled'));
                }
            }
            
            // Connect with or without TLS context
            if ($context) {
                $connected = $this->connection->connect(
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['timeout'],
                    null, // reserved
                    0, // retry_interval
                    $context
                );
            } else {
                $connected = $this->connection->connect(
                    $this->config['host'],
                    $this->config['port'],
                    $this->config['timeout']
                );
            }
            
            if (!$connected) {
                throw new StorageException(
                    "Failed to connect to ValKey server at {$this->config['host']}:{$this->config['port']}",
                    1001
                );
            }
            
            if ($isDebug) {
                error_log("ValKey: Connected successfully.");
            }
            
            // Authenticate if credentials provided
            if (!empty($this->config['auth'])) {
                // Support both ACL (username+password) and requirepass (password only) authentication
                if (!empty($this->config['user'])) {
                    // ACL mode: AUTH username password (ValKey 7.2+)
                    // phpredis supports: auth(['username', 'password']) or auth($username, $password)
                    $authenticated = $this->connection->auth([$this->config['user'], $this->config['auth']]);
                    if (!$authenticated) {
                        throw new StorageException(
                            "ValKey ACL authentication failed for user: {$this->config['user']}",
                            1002
                        );
                    }
                    if ($isDebug) {
                        error_log("ValKey: ACL authenticated successfully as user '{$this->config['user']}'.");
                    }
                } else {
                    // Legacy requirepass mode: AUTH password (backward compatible)
                    $authenticated = $this->connection->auth($this->config['auth']);
                    if (!$authenticated) {
                        throw new StorageException('Failed to authenticate with ValKey server', 1002);
                    }
                    if ($isDebug) {
                        error_log("ValKey: Authenticated successfully (legacy mode).");
                    }
                }
            }
            
            // Set prefix if provided
            if (!empty($this->config['prefix'])) {
                $this->connection->setOption(\Redis::OPT_PREFIX, $this->config['prefix']);
                if ($isDebug) {
                    error_log("ValKey: Set prefix to {$this->config['prefix']}");
                }
            }
            
            // Select database if provided
            if (isset($this->config['database']) && is_numeric($this->config['database'])) {
                $selected = $this->connection->select($this->config['database']);
                if (!$selected) {
                    throw new StorageException(
                        "Failed to select database {$this->config['database']}",
                        1004
                    );
                }
                if ($isDebug) {
                    error_log("ValKey: Selected database {$this->config['database']}");
                }
            }
            
            // Test connection with ping
            $ping = $this->connection->ping();
            if (!($ping === '+PONG' || $ping === true || $ping === 1)) {
                throw new StorageException(
                    "ValKey server connection test failed - unexpected ping response: " . var_export($ping, true),
                    1001
                );
            }
            
            if ($isDebug) {
                error_log("ValKey: Ping successful, connection verified.");
            }
            
            $this->initialized = true;
            
            if ($isDebug) {
                error_log("ValKey: Storage initialized successfully.");
            }
            
        } catch (StorageException $e) {
            // Re-throw storage exceptions with their original error code
            throw $e;
        } catch (\Exception $e) {
            $isDebug = defined('WP_DEBUG') && WP_DEBUG || 
                     getenv('GCORE_DEBUG') === 'true' || 
                     ($this->config['debug'] ?? false);
                     
            if ($isDebug) {
                error_log("ValKey: ERROR - " . $e->getMessage());
                error_log("ValKey: Stack trace - " . $e->getTraceAsString());
            }
            
            // Determine the error code based on the exception message
            $errorCode = 1000; // General error
            
            if (strpos($e->getMessage(), 'connection') !== false) {
                $errorCode = 1001; // Connection error
            } elseif (strpos($e->getMessage(), 'auth') !== false) {
                $errorCode = 1002; // Authentication error
            } elseif (strpos($e->getMessage(), 'timeout') !== false) {
                $errorCode = 1006; // Network timeout
            } elseif (strpos($e->getMessage(), 'memory') !== false) {
                $errorCode = 1007; // Out of memory
            }
            
            throw new StorageException(
                'Failed to initialize ValKey storage: ' . $e->getMessage(),
                $errorCode,
                $e
            );
        }
    }
    
    /**
     * Check if connection is active
     * 
     * @return bool Connection status
     */
    public function isConnected(): bool {
        try {
            if (!$this->initialized || !$this->connection) {
                return false;
            }
            
            // Enable debug mode based on configuration
            $isDebug = defined('WP_DEBUG') && WP_DEBUG || 
                       getenv('GCORE_DEBUG') === 'true' || 
                       ($this->config['debug'] ?? false);
            
            // Use a short timeout to prevent hanging on connection issues
            $oldTimeout = $this->connection->getTimeout();
            $this->connection->setOption(\Redis::OPT_READ_TIMEOUT, 0.5);
            
            $ping = $this->connection->ping();
            
            // Restore original timeout
            $this->connection->setOption(\Redis::OPT_READ_TIMEOUT, $oldTimeout);
            
            if ($isDebug) {
                error_log("ValKey isConnected: Ping result: " . var_export($ping, true));
            }
            
            // Different Redis/ValKey versions may return different values for a successful ping
            return $ping === '+PONG' || $ping === true || $ping === 1 || $ping === 'PONG';
        } catch (\Exception $e) {
            // Enable debug mode based on configuration
            $isDebug = defined('WP_DEBUG') && WP_DEBUG || 
                       getenv('GCORE_DEBUG') === 'true' || 
                       ($this->config['debug'] ?? false);
                       
            if ($isDebug) {
                error_log("ValKey isConnected error: " . $e->getMessage());
            }
            
            return false;
        }
    }
    
    /**
     * Set a value in storage
     * 
     * @param string $key Storage key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds (0 for no expiration)
     * @return bool Success status
     */
    public function set(string $key, $value, int $ttl = 0): bool {
        // Enable debug mode based on configuration
        $isDebug = defined('WP_DEBUG') && WP_DEBUG || 
                   getenv('GCORE_DEBUG') === 'true' || 
                   ($this->config['debug'] ?? false);
        
        if ($isDebug) {
            error_log("ValKey set: Setting key '{$key}' with TTL {$ttl}");
        }
        
        if (!$this->isConnected()) {
            if ($isDebug) {
                error_log("ValKey set: Not connected to server");
            }
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            $result = false;
            if ($ttl > 0) {
                if ($isDebug) {
                    error_log("ValKey set: Using setex with TTL {$ttl}");
                }
                $result = $this->connection->setex($key, $ttl, $value);
            } else {
                if ($isDebug) {
                    error_log("ValKey set: Using regular set");
                }
                $result = $this->connection->set($key, $value);
            }
            
            if ($isDebug) {
                error_log("ValKey set: Result: " . var_export($result, true));
            }
            return (bool)$result;
        } catch (\Exception $e) {
            if ($isDebug) {
                error_log("ValKey set error: " . $e->getMessage());
            }
            throw new StorageException(
                "Failed to set key '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Get a value from storage
     * 
     * @param string $key Storage key
     * @return mixed Stored value or null if not found
     */
    public function get(string $key) {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            $value = $this->connection->get($key);
            
            if ($value === false) {
                return null;
            }
            
            return $value;
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to get key '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Delete a value from storage
     * 
     * @param string $key Storage key
     * @return bool Success status
     */
    public function delete(string $key): bool {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->del($key) > 0;
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to delete key '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Check if a key exists in storage
     * 
     * @param string $key Storage key
     * @return bool True if key exists
     */
    public function exists(string $key): bool {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->exists($key) > 0;
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to check if key '{$key}' exists: " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Get all keys matching a pattern
     * 
     * @param string $pattern Key pattern (e.g. "user:*")
     * @return array Array of matching keys
     */
    public function keys(string $pattern): array {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }

        // Cluster-safe pattern enumeration via SCAN cursor.
        // Caller-supplied $pattern MUST be hash-tagged for multi-key sweeps
        // in cluster mode (e.g., "{site_id}:*"). Bare patterns will only
        // sweep the connected shard — caller's responsibility to hash-tag.
        // SCAN is non-blocking + cluster-aware; replaces blocking KEYS.
        try {
            $this->connection->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $iter = null;
            $keys = [];
            while ($batch = $this->connection->scan($iter, $pattern, 500)) {
                $keys = array_merge($keys, $batch);
            }
            return $keys;
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to scan keys matching pattern '{$pattern}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Increment a value
     * 
     * @param string $key Storage key
     * @param int $by Amount to increment by
     * @return int New value
     */
    public function incrBy(string $key, int $by = 1): int {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->incrBy($key, $by);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to increment key '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Decrement a value
     * 
     * @param string $key Storage key
     * @param int $by Amount to decrement by
     * @return int New value
     */
    public function decrBy(string $key, int $by = 1): int {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->decrBy($key, $by);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to decrement key '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Add to sorted set
     * 
     * @param string $key Sorted set key
     * @param float $score Score
     * @param string $member Member
     * @return int Number of elements added
     */
    public function zAdd(string $key, float $score, string $member): int {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->zAdd($key, $score, $member);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to add to sorted set '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Get range from sorted set
     * 
     * @param string $key Sorted set key
     * @param int $start Start index
     * @param int $end End index
     * @param bool $withScores Include scores
     * @return array Members
     */
    public function zRange(string $key, int $start, int $end, bool $withScores = false): array {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->zRange($key, $start, $end, $withScores);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to get range from sorted set '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Get reverse range from sorted set
     * 
     * @param string $key Sorted set key
     * @param int $start Start index
     * @param int $end End index
     * @param bool $withScores Include scores
     * @return array Members
     */
    public function zRevRange(string $key, int $start, int $end, bool $withScores = false): array {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->zRevRange($key, $start, $end, $withScores);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to get reverse range from sorted set '{$key}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Execute command
     * 
     * @param string $command Command
     * @param mixed ...$args Command arguments
     * @return mixed Command result
     */
    public function command(string $command, ...$args) {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            return $this->connection->rawCommand($command, ...$args);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to execute command '{$command}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Execute multiple commands in a pipeline
     * 
     * @param array $commands Commands to execute
     * @return array Command results
     */
    public function pipeline(array $commands): array {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }
        
        try {
            $pipeline = $this->connection->pipeline();
            
            foreach ($commands as $command) {
                $method = $command[0];
                $args = array_slice($command, 1);
                
                call_user_func_array([$pipeline, $method], $args);
            }
            
            return $pipeline->exec();
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to execute pipeline: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Publish message to a ValKey pub/sub channel
     *
     * @param string $channel Channel name
     * @param string $message Message to publish
     * @return int Number of subscribers that received the message
     */
    public function publish(string $channel, string $message): int {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server');
        }

        try {
            return (int) $this->connection->publish($channel, $message);
        } catch (\Exception $e) {
            throw new StorageException(
                "Failed to publish to channel '{$channel}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Script SHA cache to avoid reloading scripts
     */
    private static $scriptShaCache = [];
    
    /**
     * Call ValKey/Redis method
     * 
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed Method result
     * @throws StorageException If method call fails
     */
    public function __call(string $method, array $args) {
        if (!$this->isConnected()) {
            throw new StorageException('Not connected to ValKey server', 1001);
        }
        
        // Enable debug mode based on configuration
        $isDebug = defined('WP_DEBUG') && WP_DEBUG || 
                   getenv('GCORE_DEBUG') === 'true' || 
                   ($this->config['debug'] ?? false);
        
        try {
            // Handle special case for script execution
            if ($method === 'evalScript') {
                if ($isDebug) {
                    error_log("ValKey: Executing Lua script");
                }
                
                // Check if we have required arguments
                if (count($args) < 1) {
                    throw new StorageException(
                        "evalScript requires at least the script as the first argument",
                        1005
                    );
                }
                
                $script = $args[0];
                $numKeys = 0;
                $allArgs = [];
                
                // Generate a hash for the script content for caching
                $scriptHash = md5($script);
                
                // Handle different argument formats
                if (count($args) >= 3) {
                    // Format: script, keys array, args array
                    $keys = $args[1] ?? [];
                    $scriptArgs = $args[2] ?? [];
                    
                    // Determine number of keys and build argument array
                    if (is_array($keys)) {
                        $numKeys = count($keys);
                        $allArgs = array_merge($keys, is_array($scriptArgs) ? $scriptArgs : [$scriptArgs]);
                    } else {
                        $numKeys = 1;
                        $allArgs = array_merge([$keys], is_array($scriptArgs) ? $scriptArgs : [$scriptArgs]);
                    }
                } else if (count($args) == 2) {
                    // Format: script, numKeys
                    $numKeys = intval($args[1]);
                }
                
                // For better debugging
                if ($isDebug) {
                    $safeArgs = [];
                    foreach ($allArgs as $arg) {
                        // Truncate long arguments for logging
                        if (is_string($arg) && strlen($arg) > 100) {
                            $safeArgs[] = substr($arg, 0, 50) . '...' . substr($arg, -50);
                        } else {
                            $safeArgs[] = $arg;
                        }
                    }
                    
                    error_log("ValKey: Script execution with " . count($allArgs) . " arguments, " . 
                         $numKeys . " keys");
                    error_log("ValKey: Arguments: " . json_encode($safeArgs));
                }
                
                // Try to execute with cached SHA first
                $sha = self::$scriptShaCache[$scriptHash] ?? null;
                $retried = false;
                
                while (true) {
                    try {
                        // If we don't have a cached SHA or we've already retried, load the script
                        if ($sha === null || $retried) {
                            // Load the script to get the SHA
                            $sha = $this->connection->script('LOAD', $script);
                            
                            // Cache the SHA for future use
                            self::$scriptShaCache[$scriptHash] = $sha;
                            
                            if ($isDebug) {
                                error_log("ValKey: Script loaded with SHA: {$sha}");
                            }
                        }
                        
                        // Execute the script with proper arguments
                        if (count($allArgs) > 0) {
                            $result = $this->connection->evalSha($sha, $allArgs, $numKeys);
                        } else {
                            $result = $this->connection->evalSha($sha, [], 0);
                        }
                        
                        if ($isDebug) {
                            error_log("ValKey: Script execution complete");
                        }
                        
                        return $result;
                    } catch (\Exception $e) {
                        // If script doesn't exist in server cache and we haven't retried yet
                        if (strpos($e->getMessage(), 'NOSCRIPT') !== false && !$retried) {
                            $retried = true;
                            if ($isDebug) {
                                error_log("ValKey: Script not found in server, reloading...");
                            }
                            continue; // Try again with a fresh load
                        }
                        
                        // Otherwise, throw the error
                        throw $e;
                    }
                }
            }
            
            // Default behavior for standard Redis methods
            return call_user_func_array([$this->connection, $method], $args);
        } catch (\RedisException $e) {
            // Handle Redis-specific exceptions
            if ($isDebug) {
                error_log("ValKey: RedisException in __call: " . $e->getMessage());
            }
            
            // Determine error code based on message
            $errorCode = 1000;
            if (strpos($e->getMessage(), 'connection') !== false) {
                $errorCode = 1001;
            } elseif (strpos($e->getMessage(), 'NOSCRIPT') !== false) {
                $errorCode = 1005;
            } elseif (strpos($e->getMessage(), 'OOM') !== false) {
                $errorCode = 1007;
            } elseif (strpos($e->getMessage(), 'timeout') !== false) {
                $errorCode = 1006;
            }
            
            throw new StorageException(
                "ValKey operation failed: " . $e->getMessage(),
                $errorCode,
                $e
            );
        } catch (\Exception $e) {
            if ($isDebug) {
                error_log("ValKey: Error in __call: " . $e->getMessage());
            }
            
            throw new StorageException(
                "Failed to call method '{$method}': " . $e->getMessage(),
                1000, // General error code
                $e
            );
        }
    }
}