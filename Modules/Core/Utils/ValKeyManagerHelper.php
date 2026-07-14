<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Utils;

/**
 * ValKey Manager Helper
 * 
 * A helper class for manager initialization and ValKey operations. This class helps
 * ensure managers can operate independently without circular dependencies.
 */
class ValKeyManagerHelper {
    /**
     * Initialize ValKey connection for a manager
     * 
     * @param string $managerName Name of the manager
     * @param array $config Connection configuration
     * @return \Redis|null Redis connection or null on failure
     */
    public static function initializeValKey(string $managerName, array $config): ?\Redis {
        try {
            $redis = new \Redis();
            
            // Set connection options (in a version-compatible way)
            if (defined('Redis::OPT_READ_TIMEOUT')) {
                $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
            }
            
            // Use igbinary if available
            if (extension_loaded('igbinary') && defined('Redis::SERIALIZER_IGBINARY')) {
                $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY);
            }
            
            // Connect with retry logic
            $connected = false;
            $attempts = 0;
            $lastError = null;
            $maxAttempts = $config['retry_attempts'] ?? 3;
            $retryInterval = $config['retry_interval'] ?? 100; // ms
            
            while (!$connected && $attempts++ < $maxAttempts) {
                try {
                    $host = $config['host'] ?? null;
                    $port = $config['port'] ?? null;
                    if (empty($host) || empty($port)) {
                        throw new \RuntimeException(
                            'ValKeyManagerHelper requires host and port in config. '
                            . 'Set VALKEY_HOST/VALKEY_PORT env vars or pass host/port explicitly.'
                        );
                    }
                    $connected = $redis->connect(
                        $host,
                        $port,
                        $config['timeout'] ?? 2.0
                    );
                    
                    if ($connected && !empty($config['auth'])) {
                        $connected = $redis->auth($config['auth']);
                    }
                    
                    if ($connected) {
                        // Verify connection with PING
                        $connected = $redis->ping() === true;
                    }
                } catch (\Exception $e) {
                    $lastError = $e;
                    if ($attempts < $maxAttempts) {
                        usleep($retryInterval * 1000);
                    }
                }
            }
            
            if (!$connected) {
                SelfContainedErrorHandler::logError(
                    $managerName,
                    'valkey_connect',
                    $lastError ?? new \Exception('Connection failed after ' . $attempts . ' attempts'),
                    ['config' => array_diff_key($config, ['auth' => true])]
                );
                return null;
            }
            
            return $redis;
        } catch (\Exception $e) {
            SelfContainedErrorHandler::logError(
                $managerName,
                'valkey_init',
                $e,
                ['config' => array_diff_key($config, ['auth' => true])]
            );
            return null;
        }
    }
    
    /**
     * Load Lua scripts into ValKey
     * 
     * @param \Redis $redis Redis connection
     * @param array $scripts Scripts to load (name => script)
     * @param string $managerName Name of the manager
     * @return array Script SHAs or empty array on failure
     */
    public static function loadScripts(\Redis $redis, array $scripts, string $managerName): array {
        try {
            $scriptShas = [];
            
            // First verify if scripts are already loaded
            $existingShas = array_map(
                fn($script) => sha1($script),
                $scripts
            );
            
            $exists = $redis->script('exists', array_values($existingShas));
            $allLoaded = !in_array(0, $exists, true);
            
            if (!$allLoaded) {
                // Load all scripts one by one (more compatible approach)
                foreach ($scripts as $name => $script) {
                    $sha = $redis->script('load', $script);
                    if (!$sha) {
                        throw new \Exception("Failed to load script: {$name}");
                    }
                    $scriptShas[$name] = $sha;
                }
            } else {
                // Use existing SHAs
                $scriptShas = array_combine(
                    array_keys($scripts),
                    $existingShas
                );
            }
            
            return $scriptShas;
        } catch (\Exception $e) {
            SelfContainedErrorHandler::logError(
                $managerName,
                'script_loading',
                $e,
                []
            );
            return [];
        }
    }
    
    /**
     * Execute Lua script with error handling
     * 
     * @param \Redis $redis Redis connection
     * @param string $sha Script SHA
     * @param array $keys Redis keys
     * @param array $args Script arguments
     * @param int $numKeys Number of keys
     * @param string $managerName Manager name for error logging
     * @param string $operation Operation name for error logging
     * @return mixed Script result
     * @throws \Exception If script execution fails
     */
    public static function executeScript(
        \Redis $redis,
        string $sha,
        array $keys,
        array $args,
        int $numKeys,
        string $managerName,
        string $operation
    ) {
        try {
            return $redis->evalSha($sha, array_merge($keys, $args), $numKeys);
        } catch (\Exception $e) {
            // Handle NOSCRIPT error specifically
            if (strpos($e->getMessage(), 'NOSCRIPT') !== false) {
                throw new \Exception("Script not loaded: {$operation}", 0, $e);
            }
            
            throw new \Exception("Script execution failed: {$e->getMessage()}", 0, $e);
        }
    }
    
    /**
     * Check if service is healthy
     * 
     * @param \Redis $redis Redis connection
     * @param string $managerName Manager name
     * @return bool True if service is healthy
     */
    public static function checkHealth(\Redis $redis, string $managerName): bool {
        try {
            return $redis->ping() === true;
        } catch (\Exception $e) {
            SelfContainedErrorHandler::logError(
                $managerName,
                'health_check',
                $e,
                []
            );
            return false;
        }
    }
    
    /**
     * Safely serialize value
     * 
     * @param mixed $value Value to serialize
     * @return string Serialized value
     */
    public static function serialize($value): string {
        if (extension_loaded('igbinary')) {
            return igbinary_serialize($value);
        }
        return serialize($value);
    }
    
    /**
     * Safely unserialize value
     * 
     * @param string $value Serialized value
     * @param mixed $default Default value on failure
     * @return mixed Unserialized value or default
     */
    public static function unserialize(string $value, $default = null) {
        try {
            if (extension_loaded('igbinary')) {
                $result = igbinary_unserialize($value);
                return $result !== false ? $result : $default;
            }
            
            $result = unserialize($value, ['allowed_classes' => false]);
            return $result !== false ? $result : $default;
        } catch (\Exception $e) {
            error_log("[gCore] ValKeyManagerHelper::unserialize failed: " . $e->getMessage());
            return $default;
        }
    }
}