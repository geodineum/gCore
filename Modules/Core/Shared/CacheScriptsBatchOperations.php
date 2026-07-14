<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Batch Cache Operations Scripts
 * 
 * Optimized scripts for batch operations:
 * - MGET (multi-get)
 * - MSET (multi-set)
 * - MDEL (multi-delete)
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */
class CacheScriptsBatchOperations {
    /**
     * MGET: Optimized batch get with RESP3 array returns
     */
    private const MGET = <<<'LUA'
    server.setresp(3)

    -- Input validation
    if #KEYS == 0 then
        return server.error_reply("At least one key required")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end
    
    local function batch_get(keys, site_id, group)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Validate all keys hash to same slot
        local base_slot = server.call('CLUSTER', 'KEYSLOT', keys[1])
        for i=2,#keys do
            if server.call('CLUSTER', 'KEYSLOT', keys[i]) ~= base_slot then
                return server.error_reply("Keys must hash to same slot")
            end
        end
        
        -- Build full keys
        local full_keys = {}
        for i, key in ipairs(keys) do
            local full_key = build_key(key, site_id, group, '')
            if type(full_key) ~= "string" then
                return server.error_reply("Invalid key construction: " .. key)
            end
            full_keys[i] = full_key
        end
        
        -- Perform batch get
        local values = server.call('MGET', unpack(full_keys))
        
        -- Track metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        local latency = end_time - start_time
        
        local hits = 0
        local misses = 0
        for _, v in ipairs(values) do
            if v then hits = hits + 1
            else misses = misses + 1 end
        end
        
        track_metric(site_id, 'batch_gets', 1, {
            keys = #keys,
            hits = hits,
            misses = misses,
            latency = latency
        })
        
        -- Return array directly for RESP3
        return values
    end

    return batch_get(KEYS, ARGV[1], ARGV[2])
    LUA;

    /**
     * MSET: Optimized batch set with RESP3 status
     */
    private const MSET = <<<'LUA'
    server.setresp(3)

    -- Input validation
    if #KEYS == 0 then
        return server.error_reply("At least one key required")
    end
    if #KEYS ~= #ARGV-3 then
        return server.error_reply("Key/value count mismatch")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end

    -- Extract values from ARGV (skip site_id, ttl, group)
    local values = {}
    for i=4,#ARGV do
        values[i-3] = ARGV[i]
    end

    local function batch_set(keys, values, site_id, ttl, group)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Validate all keys hash to same slot
        local base_slot = server.call('CLUSTER', 'KEYSLOT', keys[1])
        for i=2,#keys do
            if server.call('CLUSTER', 'KEYSLOT', keys[i]) ~= base_slot then
                return server.error_reply("Keys must hash to same slot")
            end
        end
        
        -- Build full keys and prepare args
        local full_keys = {}
        local set_args = {}
        for i, key in ipairs(keys) do
            local full_key = build_key(key, site_id, group, '')
            if type(full_key) ~= "string" then
                return server.error_reply("Invalid key construction: " .. key)
            end
            full_keys[i] = full_key
            set_args[i*2-1] = full_key
            set_args[i*2] = values[i]
        end
        
        -- Perform batch set
        local success = server.call('MSET', unpack(set_args))
        
        -- Apply TTL if specified
        if ttl and ttl > 0 then
            for _, key in ipairs(full_keys) do
                server.call('EXPIRE', key, ttl)
            end
        end
        
        -- Track metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        local latency = end_time - start_time
        
        track_metric(site_id, 'batch_sets', 1, {
            keys = #keys,
            ttl = ttl,
            latency = latency
        })
        
        -- Return status reply for RESP3
        return server.status_reply("OK")
    end

    return batch_set(KEYS, values, ARGV[1], tonumber(ARGV[2]), ARGV[3])
    LUA;

    /**
     * MDEL: Multi-key delete with RESP3 integer return
     */
    private const MDEL = <<<'LUA'
    server.setresp(3)

    -- Input validation
    if #KEYS == 0 then
        return server.error_reply("At least one key required")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end

    local function batch_delete(keys, site_id, group)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Validate all keys hash to same slot
        local base_slot = server.call('CLUSTER', 'KEYSLOT', keys[1])
        for i=2,#keys do
            if server.call('CLUSTER', 'KEYSLOT', keys[i]) ~= base_slot then
                return server.error_reply("Keys must hash to same slot")
            end
        end
        
        -- Build full keys
        local full_keys = {}
        for i, key in ipairs(keys) do
            local full_key = build_key(key, site_id, group, '')
            if type(full_key) ~= "string" then
                return server.error_reply("Invalid key construction: " .. key)
            end
            full_keys[i] = full_key
        end
        
        -- Perform batch deletion
        local deleted = 0
        for _, key in ipairs(full_keys) do
            deleted = deleted + server.call('DEL', key)
        end
        
        -- Track metrics
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        track_metric(site_id, 'batch_deletes', 1, {
            attempted = #keys,
            deleted = deleted,
            latency = end_time - start_time
        })
        
        -- Return integer for RESP3
        return deleted
    end

    return batch_delete(KEYS, ARGV[1], ARGV[2])
    LUA;

    /**
     * Registry of all batch operation scripts
     */
    public const SCRIPTS = [
        // Batch Operations
        'MGET' => [
            'script' => self::MGET,
            'keys' => -1,  // Variable number of keys
            'args' => [
                'site_id' => 'string',
                'group' => 'string|null'
            ]
        ],
        'MSET' => [
            'script' => self::MSET,
            'keys' => -1,  // Variable number of keys
            'args' => [
                'site_id' => 'string',
                'ttl' => 'int|null',
                'group' => 'string|null',
                'values' => 'array'  // Must match key count
            ]
        ],
        'MDEL' => [
            'script' => self::MDEL,
            'keys' => -1,  // Variable number of keys
            'args' => [
                'site_id' => 'string',
                'group' => 'string|null'
            ]
        ],
    ];
}