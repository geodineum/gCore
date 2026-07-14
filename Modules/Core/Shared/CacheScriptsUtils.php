<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Utility Scripts and Helper Functions
 * 
 * Common Lua script components that are reused across
 * various cache script implementations.
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */
class CacheScriptsUtils {
    /**
     * Core key building with site isolation
     * Ensures consistent key structure across all operations
     */
    public const BUILD_KEY = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] or KEYS[1] == '' then
        return server.error_reply("Key required")
    end
    if not ARGV[1] or ARGV[1] == '' then
        return server.error_reply("Site ID required")
    end

    local function build_key(key, site_id, group, prefix)
        -- Fast path for simple keys (most common case)
        if not group then
            return '{' .. site_id .. '}:' .. prefix .. key
        end
        
        -- Group path needs validation
        if not server.call('HEXISTS', '{' .. site_id .. '}:groups', group) then
            return server.error_reply("Invalid group: " .. group)
        end
        
        return '{' .. site_id .. '}:' .. prefix .. group .. ':' .. key
    end

    -- Return as RESP3 string
    return build_key(KEYS[1], ARGV[1], ARGV[2], ARGV[3])
    LUA;

    /**
     * Track metric function used across scripts
     */
    public const TRACK_METRIC = <<<'LUA'
    local function track_metric(site_id, metric_type, value, extra)
        -- Site-specific metrics
        local site_metrics = '{' .. site_id .. '}:metrics'
        server.call('HINCRBY', site_metrics, metric_type, value)
        
        -- Store additional metric data if provided
        if extra then
            local details_key = site_metrics .. ':details:' .. metric_type
            server.call('LPUSH', details_key, cjson.encode(extra))
            server.call('LTRIM', details_key, 0, 999)  -- Keep last 1000 entries
        end
        
        -- Global metrics if enabled
        if server.call('GET', 'global_metrics_enabled') == '1' then
            server.call('HINCRBY', '{global}:metrics', metric_type, value)
        end
        
        -- Performance tracking
        if metric_type:match('^latency:') then
            local latency_key = '{' .. site_id .. '}:latency'
            server.call('ZADD', latency_key, value, tostring(server.call('TIME')[1]))
            server.call('ZREMRANGEBYRANK', latency_key, 0, -10001)  -- Keep last 10K samples
        end
    end
    LUA;

    /**
     * Validate keys function used in batch operations
     */
    public const VALIDATE_KEYS = <<<'LUA'
    local function validate_keys(keys)
        if #keys > {maxBatchSize} then
            return { err = "Batch size exceeds limit of {maxBatchSize}" }
        end

        for _, key in ipairs(keys) do
            if #key > {maxKeyLength} then
                return { err = "Key length exceeds {maxKeyLength} bytes" }
            end
        end
        return true
    end
    LUA;

    /**
     * Permission validation helper
     */
    public const PERMISSION_VALIDATOR = <<<'LUA'
    local function validate_permissions(permissions)
        -- Valid permission types
        local valid_types = {
            read = true,
            write = true,
            delete = true,
            admin = true
        }
        
        -- Valid permission values
        local valid_values = {
            allow = true,
            deny = true,
            inherit = true
        }
        
        -- Validate structure with RESP3 map response
        if type(permissions) ~= 'table' then
            return {
                map = {
                    valid = false,
                    error = "Permissions must be a table"
                }
            }
        end
        
        -- Validate each permission
        for perm_type, value in pairs(permissions) do
            if not valid_types[perm_type] then
                return {
                    map = {
                        valid = false,
                        error = "Invalid permission type: " .. perm_type
                    }
                }
            end
            if not valid_values[value] then
                return {
                    map = {
                        valid = false,
                        error = "Invalid permission value for " .. perm_type
                    }
                }
            end
        end
        
        -- Return success as RESP3 map
        return {
            map = {
                valid = true,
                permissions = permissions
            }
        }
    end
    LUA;

    /**
     * Cycle detection helper for group inheritance
     */
    public const CYCLE_DETECTION = <<<'LUA'
    local function detect_cycles(site_id, group, ancestors)
        -- Initialize ancestor tracking
        ancestors = ancestors or {}
        
        -- Check immediate cycle
        if ancestors[group] then
            return true
        end
        
        -- Get parent group with proper site isolation
        local parent_key = '{' .. site_id .. '}:groups:' .. group .. ':parent'
        local parent = server.call('GET', parent_key)
        
        -- No parent means no cycle possible
        if not parent then
            return false
        end
        
        -- Build new ancestors set with current group
        local new_ancestors = { [group] = true }
        for k, v in pairs(ancestors) do
            new_ancestors[k] = v
        end
        
        -- Recursive check
        return detect_cycles(site_id, parent, new_ancestors)
    end
    LUA;

    /**
     * Prevent instantiation - this is a static utility class only
     */
    private function __construct() {}
}