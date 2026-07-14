<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core Cache Operations Scripts
 * 
 * Basic cache operation scripts for fundamental operations:
 * - get, set, delete
 * - increment, decrement
 * - exists, ttl
 * - get and set atomically
 */
class CacheScriptsCoreOperations extends CacheScriptsBase {
    /**
     * Core operation scripts
     */
    const SCRIPTS = [
        'GET' => [
            'script' => "
                local key = KEYS[1]
                return redis.call('GET', key)
            ",
            'keys' => 1
        ],
        
        'SET_WITH_TTL' => [
            'script' => "
                local key = KEYS[1]
                local value = ARGV[1]
                local ttl = tonumber(ARGV[2])
                
                redis.call('SET', key, value)
                if ttl > 0 then
                    redis.call('EXPIRE', key, ttl)
                end
                return true
            ",
            'keys' => 1
        ],
        
        'DELETE' => [
            'script' => "
                local key = KEYS[1]
                return redis.call('DEL', key)
            ",
            'keys' => 1
        ],
        
        'EXISTS' => [
            'script' => "
                local key = KEYS[1]
                return redis.call('EXISTS', key)
            ",
            'keys' => 1
        ],
        
        'TTL' => [
            'script' => "
                local key = KEYS[1]
                return redis.call('TTL', key)
            ",
            'keys' => 1
        ],
        
        'INCREMENT' => [
            'script' => "
                local key = KEYS[1]
                local increment = tonumber(ARGV[1])
                local ttl = tonumber(ARGV[2])
                
                local value = redis.call('INCRBY', key, increment)
                if ttl > 0 then
                    redis.call('EXPIRE', key, ttl)
                end
                return value
            ",
            'keys' => 1
        ],
        
        'DECREMENT' => [
            'script' => "
                local key = KEYS[1]
                local decrement = tonumber(ARGV[1])
                local ttl = tonumber(ARGV[2])
                
                local value = redis.call('DECRBY', key, decrement)
                if ttl > 0 then
                    redis.call('EXPIRE', key, ttl)
                end
                return value
            ",
            'keys' => 1
        ],
        
        'GET_SET' => [
            'script' => "
                local key = KEYS[1]
                local value = ARGV[1]
                local ttl = tonumber(ARGV[2])
                
                local old = redis.call('GET', key)
                redis.call('SET', key, value)
                if ttl > 0 then
                    redis.call('EXPIRE', key, ttl)
                end
                return old
            ",
            'keys' => 1
        ],
        
        'SET_IF_NOT_EXISTS' => [
            'script' => "
                local key = KEYS[1]
                local value = ARGV[1]
                local ttl = tonumber(ARGV[2])
                
                local result = redis.call('SETNX', key, value)
                if result == 1 and ttl > 0 then
                    redis.call('EXPIRE', key, ttl)
                end
                return result
            ",
            'keys' => 1
        ]
    ];
    
    /**
     * Register scripts with Redis
     * 
     * @param \Redis $redis Redis connection
     * @return array Script SHAs
     */
    public static function registerScripts(\Redis $redis): array {
        $shas = [];
        
        foreach (self::SCRIPTS as $name => $script) {
            try {
                $shas[$name] = $redis->script('LOAD', $script['script']);
            } catch (\Exception $e) {
                // Handle script loading error
                error_log("Failed to load script {$name}: " . $e->getMessage());
            }
        }
        
        return $shas;
    }
}