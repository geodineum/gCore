<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Multi-Site Cache Operation Scripts
 * 
 * Provides a centralized registry of ValKey Lua scripts for atomic operations
 * and cluster-safe data management.
 * 
 * This class acts as a facade for the modular CacheScripts system, ensuring
 * backward compatibility while providing access to all specialized script functionality.
 */
class CacheScripts extends CacheScriptsBase {
    /**
     * Core operation scripts (Legacy - maintained for backward compatibility)
     * These will be integrated with the modular scripts system
     */
    const SCRIPTS = [
        'SET_WITH_TTL' => [
            'script' => "
                redis.call('SET', KEYS[1], ARGV[1])
                if tonumber(ARGV[2]) > 0 then
                    redis.call('EXPIRE', KEYS[1], ARGV[2])
                end
                return 1
            ",
            'keys' => 1,
            'args' => 2
        ],
        'GET_SET' => [
            'script' => "
                local value = redis.call('GET', KEYS[1])
                redis.call('SET', KEYS[1], ARGV[1])
                if tonumber(ARGV[2]) > 0 then
                    redis.call('EXPIRE', KEYS[1], ARGV[2])
                end
                return value
            ",
            'keys' => 1,
            'args' => 2
        ],
        'INCREMENT' => [
            'script' => "
                local current = redis.call('INCRBY', KEYS[1], ARGV[1])
                if tonumber(ARGV[2]) > 0 then
                    redis.call('EXPIRE', KEYS[1], ARGV[2])
                end
                return current
            ",
            'keys' => 1,
            'args' => 2
        ],
        'HEALTH_CHECK' => [
            'script' => "
                local status = {}
                status.ping = redis.call('PING')
                status.set = pcall(function() redis.call('SET', KEYS[1], ARGV[1]) end)
                status.get = pcall(function() redis.call('GET', KEYS[1]) end)
                status.del = pcall(function() redis.call('DEL', KEYS[1]) end)
                return cjson.encode(status)
            ",
            'keys' => 1,
            'args' => 1
        ]
    ];
    
    /**
     * Stream operation scripts (Legacy - maintained for backward compatibility)
     * These will be integrated with the modular scripts system
     */
    const STREAM_SCRIPTS = [
        'STREAM_ADD' => [
            'script' => "
                local stream = KEYS[1]
                local id = redis.call('XADD', stream, ARGV[1], 'data', ARGV[2])
                return id
            ",
            'keys' => 1,
            'args' => 2
        ],
        'STREAM_CREATE_GROUP' => [
            'script' => "
                local stream = KEYS[1]
                local group = ARGV[1]
                local id = ARGV[2]
                
                -- Check if stream exists and create if it doesn't
                local type = redis.call('TYPE', stream).ok
                if type ~= 'stream' then
                    redis.call('XADD', stream, '*', 'init', '1')
                end
                
                -- Create consumer group
                local success, err = pcall(function()
                    redis.call('XGROUP', 'CREATE', stream, group, id, 'MKSTREAM')
                end)
                
                if not success and string.find(err, 'already exists') then
                    return 'OK:EXISTS'
                elseif not success then
                    return 'ERR:' .. err
                end
                
                return 'OK:CREATED'
            ",
            'keys' => 1,
            'args' => 2
        ]
    ];
    
    /**
     * Initialize scripts with configuration
     * Must be called before any script access
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }
        
        // Load legacy scripts into the compiled scripts array
        self::compileScriptsFromProvider(self::SCRIPTS);
        self::compileScriptsFromProvider(self::STREAM_SCRIPTS);
        
        // Continue with regular initialization to load all modular scripts
        parent::init();
    }
    
    /**
     * Get script with specified name
     * This method provides backward compatibility while also accessing
     * all scripts from the modular system.
     * 
     * @param string $name Script name
     * @return array Script data
     * @throws \RuntimeException if script not found
     */
    public static function getScript(string $name): array {
        // Ensure scripts are initialized
        if (!self::$initialized) {
            self::init();
        }
        
        try {
            // Try to get from the modular system
            return parent::getScript($name);
        } catch (\RuntimeException $e) {
            // If not found in modular system, try legacy constants
            if (isset(self::SCRIPTS[$name])) {
                return self::SCRIPTS[$name];
            } elseif (isset(self::STREAM_SCRIPTS[$name])) {
                return self::STREAM_SCRIPTS[$name];
            } else {
                throw new \RuntimeException("Unknown script: {$name}");
            }
        }
    }
    
    /**
     * Get all scripts including both legacy and modular
     * 
     * @return array All scripts
     */
    public static function getAllScripts(): array {
        // Ensure scripts are initialized
        if (!self::$initialized) {
            self::init();
        }
        
        return self::$compiledScripts;
    }
    
    /**
     * Get core operation scripts
     * 
     * @return array Core operation scripts
     */
    public static function getCoreScripts(): array {
        return CacheScriptsCoreOperations::SCRIPTS;
    }
    
    /**
     * Get batch operation scripts
     * 
     * @return array Batch operation scripts
     */
    public static function getBatchScripts(): array {
        return CacheScriptsBatchOperations::SCRIPTS;
    }
    
    /**
     * Get stream operation scripts
     * 
     * @return array Stream operation scripts
     */
    public static function getStreamScripts(): array {
        return CacheScriptsStreamOperations::SCRIPTS;
    }
    
    /**
     * Get pub/sub operation scripts
     *
     * @return array Pub/sub operation scripts
     */
    public static function getPubSubScripts(): array {
        return CacheScriptsPubSub::SCRIPTS;
    }

    /**
     * Get hash operation scripts
     *
     * @return array Hash operation scripts
     */
    public static function getHashScripts(): array {
        return CacheScriptsHashOperations::SCRIPTS;
    }

    /**
     * Get utility scripts
     *
     * @return array Utility scripts
     */
    public static function getUtilScripts(): array {
        return CacheScriptsUtils::SCRIPTS;
    }

    /**
     * Get monitoring scripts
     * 
     * @return array Monitoring scripts
     */
    public static function getMonitoringScripts(): array {
        return CacheScriptsMonitoring::SCRIPTS;
    }
}