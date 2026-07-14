<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hash Operations Scripts
 * 
 * Scripts for hash-specific operations such as HINCRBY, HGETALL, HEXISTS, and HSET.
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */
class CacheScriptsHashOperations {
    /**
    * HINCRBY: Increment hash field with metric tracking
    */
    private const HINCRBY = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Key required")
    end
    if not ARGV[1] then
        return server.error_reply("Field required")
    end
    if not ARGV[2] then
        return server.error_reply("Increment required")
    end
    if not ARGV[3] then
        return server.error_reply("Site ID required")
    end

    local function increment_with_metrics(key, field, increment, site_id)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Execute increment
        local new_value = server.call('HINCRBY', key, field, increment)
        
        -- Track operation metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        local latency = end_time - start_time
        
        track_metric(site_id, 'hash_increments', 1, {
            key = key,
            field = field,
            increment = increment,
            latency = latency
        })
        
        -- Return as RESP3 double for precision
        return { double = new_value }
    end

    return increment_with_metrics(KEYS[1], ARGV[1], tonumber(ARGV[2]), ARGV[3])
    LUA;

    /**
    * HGETALL: Get all hash fields with metric tracking
    */
    private const HGETALL = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Key required")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end

    local function get_all_with_metrics(key, site_id)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Get all fields
        local result = server.call('HGETALL', key)
        
        -- Convert to a RESP3 map
        local hash = { map = {} }
        for i = 1, #result, 2 do
            hash.map[result[i]] = result[i + 1]
        end
        
        -- Track operation metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        local latency = end_time - start_time
        track_metric(site_id, 'hash_retrievals', 1, {
            key = key,
            fields_count = #result / 2,
            latency = latency
        })
        
        return hash
    end

    return get_all_with_metrics(KEYS[1], ARGV[1])
    LUA;

    /**
    * HEXISTS: Check hash field existence with metric tracking
    */
    private const HEXISTS = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Key required")
    end
    if not ARGV[1] then
        return server.error_reply("Field required")
    end
    if not ARGV[2] then
        return server.error_reply("Site ID required")
    end


    local function exists_with_metrics(key, field, site_id)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Check existence
        local exists = server.call('HEXISTS', key, field)
        
        -- Track operation metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        local latency = end_time - start_time
        
        track_metric(site_id, 'hash_exists_checks', 1, {
            key = key,
            field = field,
            exists = exists == 1,
            latency = latency
        })
        
        -- Return as RESP3 boolean
        return exists == 1
    end

    return exists_with_metrics(KEYS[1], ARGV[1], ARGV[2])
    LUA;

    /**
    * HSET: Set hash field with metric tracking
    */
    private const HSET = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Key required")
    end
    if not ARGV[1] then
        return server.error_reply("Field required")
    end
    if not ARGV[2] then
        return server.error_reply("Value required")
    end
    if not ARGV[3] then
        return server.error_reply("Site ID required")
    end


    local function set_with_metrics(key, field, value, site_id)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Set field
        local result = server.call('HSET', key, field, value)
        
        -- Track operation metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        local latency = end_time - start_time
        
        track_metric(site_id, 'hash_sets', 1, {
            key = key,
            field = field,
            new_field = result == 1,
            latency = latency
        })
        
        -- Return as RESP3 boolean indicating if field was new
        return result == 1
    end

    return set_with_metrics(KEYS[1], ARGV[1], ARGV[2], ARGV[3])
    LUA;

    /**
    * LPUSH: Push to list with metric tracking
    */
    private const LPUSH = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Key required")
    end
    if not ARGV[1] then 
        return server.error_reply("Value required")
    end
    if not ARGV[2] then
        return server.error_reply("Site ID required")
    end
    
    local function push_with_metrics(key, value, site_id)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Push value
        local list_length = server.call('LPUSH', key, value)
        
        -- Track operation metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        local latency = end_time - start_time
        
        track_metric(site_id, 'list_pushes', 1, {
            key = key,
            list_size = list_length,
            latency = latency
        })
        
        -- Return length as a simple integer
        return list_length
    end

    return push_with_metrics(KEYS[1], ARGV[1], ARGV[2])
    LUA;

    /**
     * Registry of all hash and list operation scripts
     */
    public const SCRIPTS = [
        'HINCRBY' => [
            'script' => self::HINCRBY,
            'keys' => 1,
            'args' => [
                'field' => 'string',
                'increment' => 'number',
                'site_id' => 'string'  
            ]
        ],
        'HGETALL' => [
            'script' => self::HGETALL,
            'keys' => 1,
            'args' => [
                'site_id' => 'string'
            ]
        ],
        'HEXISTS' => [
            'script' => self::HEXISTS,
            'keys' => 1,
            'args' => [
                'field' => 'string',
                'site_id' => 'string'
            ]
        ],
        'HSET' => [
            'script' => self::HSET,
            'keys' => 1,
            'args' => [
                'field' => 'string',
                'value' => 'string',
                'site_id' => 'string'
            ]
        ],
        'LPUSH' => [
            'script' => self::LPUSH,
            'keys' => 1,
            'args' => [
                'value' => 'string',
                'site_id' => 'string'
            ]
        ],
    ];
}