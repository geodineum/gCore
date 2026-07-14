<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Monitoring Scripts
 * 
 * Scripts for monitoring operations including:
 * - Metric tracking
 * - Performance monitoring
 * - System health checks
 * - Maintenance operations
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */
class CacheScriptsMonitoring {
    /**
    * TRACK_METRIC: Metric tracking with RESP3 status
    */
    private const TRACK_METRIC = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not ARGV[1] or ARGV[1] == '' then
        return server.error_reply("Site ID required")
    end
    if not ARGV[2] then
        return server.error_reply("Metric type required")
    end

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
        
        return server.status_reply("OK")
    end

    return track_metric(ARGV[1], ARGV[2], tonumber(ARGV[3]) or 1, ARGV[4])
    LUA;

    /**
    * METRICS_AGGREGATE: Advanced metrics aggregation with RESP3 map
    */
    private const METRICS_AGGREGATE = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end

    local function aggregate_metrics(site_id, window)
        local now = server.call('TIME')[1]
        local start_time = now - window
        
        -- Build metrics keys in site's slot
        local base = '{' .. site_id .. '}:metrics'
        local keys = {
            operations = base .. ':ops',
            latency = base .. ':latency',
            errors = base .. ':errors',
            storage = base .. ':storage'
        }
        
        -- Aggregate operation metrics
        local ops = server.call('HGETALL', keys.operations)
        local op_metrics = {}
        for i = 1, #ops, 2 do
            op_metrics[ops[i]] = tonumber(ops[i + 1])
        end
        
        -- Calculate latency percentiles
        local latencies = server.call('ZRANGEBYSCORE', keys.latency, start_time, now, 'WITHSCORES')
        local latency_values = {}
        for i = 2, #latencies, 2 do
            table.insert(latency_values, tonumber(latencies[i]))
        end
        table.sort(latency_values)
        
        -- Build RESP3 map for response
        local response = { 
            map = {
                site_id = site_id,
                timestamp = now,
                window = window,
                operations = op_metrics,
                latency = {},
                errors = {},
                storage = {}
            }
        }
        
        -- Add latency percentiles if available
        if #latency_values > 0 then
            response.map.latency = {
                p50 = latency_values[math.ceil(#latency_values * 0.5)],
                p90 = latency_values[math.ceil(#latency_values * 0.9)],
                p95 = latency_values[math.ceil(#latency_values * 0.95)],
                p99 = latency_values[math.ceil(#latency_values * 0.99)]
            }
        end
        
        -- Add errors
        local errors = server.call('HGETALL', keys.errors)
        for i = 1, #errors, 2 do
            response.map.errors[errors[i]] = tonumber(errors[i + 1])
        end
        
        -- Add storage metrics
        response.map.storage = {
            keys = tonumber(server.call('HGET', keys.storage, 'keys') or 0),
            bytes = tonumber(server.call('HGET', keys.storage, 'bytes') or 0)
        }
        
        -- Track aggregation metrics
        track_metric(site_id, 'metrics_aggregated', 1, {
            window = window,
            latency_samples = #latency_values,
            error_types = #errors / 2
        })
        
        -- Store aggregated metrics with TTL
        local agg_key = string.format('{%s}:metrics:agg:%s', site_id, now)
        server.call('SET', agg_key, cjson.encode(response.map))
        server.call('EXPIRE', agg_key, 86400) -- Keep for 24 hours
        
        return response
    end

    return aggregate_metrics(
        ARGV[1],                     -- Site ID
        tonumber(ARGV[2] or 300)     -- Window in seconds (default 5 minutes)
    )
    LUA;

    /**
    * CLEANUP: System cleanup operations with RESP3 map
    */
    private const CLEANUP = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end

    local function perform_cleanup(site_id, batch_size)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 map for cleanup results
        local cleaned = { 
            map = {
                keys = 0,
                locks = 0,
                transactions = 0,
                metrics = 0,
                latency = 0,
                duration = 0
            }
        }
        
        -- Build base key in site's slot
        local base = '{' .. site_id .. '}'
        
        -- Cleanup expired locks
        local lock_pattern = base .. ':lock:*'
        local cursor = '0'
        repeat
            local result = server.call('SCAN', cursor, 'MATCH', lock_pattern, 'COUNT', batch_size)
            cursor = result[1]
            local keys = result[2]
            
            for _, key in ipairs(keys) do
                if server.call('TTL', key) <= 0 then
                    server.call('DEL', key)
                    cleaned.map.locks = cleaned.map.locks + 1
                end
            end
        until cursor == '0'
        
        -- Cleanup stale transactions
        local tx_pattern = base .. ':tx:*'
        cursor = '0'
        repeat
            local result = server.call('SCAN', cursor, 'MATCH', tx_pattern, 'COUNT', batch_size)
            cursor = result[1]
            local keys = result[2]
            
            for _, key in ipairs(keys) do
                if server.call('TTL', key) <= 0 then
                    server.call('DEL', key)
                    cleaned.map.transactions = cleaned.map.transactions + 1
                end
            end
        until cursor == '0'
        
        -- Trim metrics data
        local metrics_base = base .. ':metrics'
        -- Keep last 24 hours of detailed metrics
        local removed = server.call('ZREMRANGEBYSCORE', metrics_base .. ':latency', 0, start_time - 86400)
        cleaned.map.latency = removed
        cleaned.map.metrics = server.call('ZCARD', metrics_base .. ':latency')
        
        -- Calculate cleanup duration
        cleaned.map.duration = server.call('TIME')[1] - start_time
        
        -- Track cleanup metrics
        track_metric(site_id, 'cleanup_executed', 1, {
            duration = cleaned.map.duration,
            locks_cleaned = cleaned.map.locks,
            transactions_cleaned = cleaned.map.transactions,
            metrics_trimmed = cleaned.map.latency
        })
        
        return cleaned
    end

    return perform_cleanup(
        ARGV[1],                     -- Site ID
        {maxBatchSize}               -- Batch size (default 1000)
    )
    LUA;

    /**
     * Health check implementation
     * Provides system status
     */
    private const HEALTH_CHECK = <<<'LUA'
    server.setresp(3)

    -- Input validation
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end
    
    -- Estimate payload size (a heuristic to prevent large responses)
    local estimated_size = 0
    if ARGV[2] then
        estimated_size = #ARGV[2]
    end
    
    if estimated_size > {maxMetricSize} then
        return server.error_reply("Metrics payload too large")
    end
    
    -- Metric tracking helper function
    local function track_metric(site_id, metric_type, value, extra)
        -- Site-specific metrics with proper RESP3 map returns
        local site_metrics = '{' .. site_id .. '}:metrics'
        server.call('HINCRBY', site_metrics, metric_type, value)
        
        -- Store additional metric data if provided
        if extra then
            local details_key = site_metrics .. ':details:' .. metric_type
            server.call('LPUSH', details_key, cjson.encode(extra))
            server.call('LTRIM', details_key, 0, 999)  -- Keep last 1000 entries
        end
        
        -- Global metrics with RESP3 boolean returns
        if server.call('GET', 'global_metrics_enabled') == '1' then
            server.call('HINCRBY', '{global}:metrics', metric_type, value)
        end
    end

    local function check_health(site_id)
        local start_time = server.call('TIME')[1]
        
        -- Build base key in site's slot
        local base = '{' .. site_id .. '}'
        
        -- Initialize health response as RESP3 map
        local health = {
            map = {
                status = 'healthy',
                timestamp = { double = start_time }, -- RESP3 double type
                checks = { map = {} },  -- Nested RESP3 map
                metrics = { map = {} }  -- Nested RESP3 map
            }
        }
        
        -- Check basic connectivity with RESP3 map
        health.map.checks.connectivity = {
            map = {
                status = 'ok',
                latency = { double = 0 }  -- RESP3 double type
            }
        }
        
        -- Check storage metrics
        local storage = server.call('HGETALL', base .. ':metrics:storage')
        health.map.metrics.storage = {
            map = {
                keys = tonumber(server.call('HGET', base .. ':metrics:storage', 'keys') or 0),
                bytes = tonumber(server.call('HGET', base .. ':metrics:storage', 'bytes') or 0)
            }
        }
        
        -- Check recent errors
        local recent_errors = server.call('ZCOUNT', base .. ':errors', start_time - 300, start_time)
        health.map.checks.errors = {
            map = {
                status = recent_errors > 100 and 'warning' or 'ok',
                count = recent_errors
            }
        }
        
        -- Check lock status with RESP3 map structure
        local active_locks = server.call('HLEN', base .. ':locks:active')
        health.map.checks.locks = {
            map = {
                status = active_locks > 1000 and 'warning' or 'ok',
                count = active_locks
            }
        }
        
        -- Check transaction status
        local active_tx = server.call('HLEN', base .. ':transactions:active')
        health.map.checks.transactions = {
            map = {
                status = active_tx > 100 and 'warning' or 'ok',
                count = active_tx
            }
        }
        
        -- Get operation rates as RESP3 map
        local ops = server.call('HGETALL', base .. ':metrics:ops')
        health.map.metrics.operations = { map = {} }
        for i = 1, #ops, 2 do
            health.map.metrics.operations.map[ops[i]] = tonumber(ops[i + 1])
        end
        
        -- Calculate operation rate as RESP3 double
        local window_ops = server.call('ZCOUNT', base .. ':metrics:ops:history', start_time - 60, start_time)
        health.map.metrics.operation_rate = { double = window_ops / 60.0 }
        
        -- Get recent latencies
        local latencies = server.call('ZRANGEBYSCORE', base .. ':metrics:latency', start_time - 60, start_time, 'WITHSCORES')
        if #latencies > 0 then
            local values = {}
            for i = 2, #latencies, 2 do
                table.insert(values, tonumber(latencies[i]))
            end
            table.sort(values)
            
            -- Structure latency metrics as RESP3 map with doubles
            health.map.metrics.latency = {
                map = {
                    p50 = { double = values[math.ceil(#values * 0.5)] or 0 },
                    p95 = { double = values[math.ceil(#values * 0.95)] or 0 },
                    p99 = { double = values[math.ceil(#values * 0.99)] or 0 }
                }
            }
            
            -- Set status based on latency
            if health.map.metrics.latency.map.p95.double > 100 then
                health.map.checks.latency = {
                    map = {
                        status = 'warning',
                        threshold = { double = 100 }
                    }
                }
            else
                health.map.checks.latency = {
                    map = {
                        status = 'ok',
                        threshold = { double = 100 }
                    }
                }
            end
        end
        
        -- Check circuit breakers with RESP3 map
        local breakers = server.call('HGETALL', base .. ':circuit_breakers')
        health.map.checks.circuit_breakers = {
            map = {
                status = 'ok',
                open = 0
            }
        }
        
        for i = 1, #breakers, 2 do
            local state = breakers[i + 1]
            if state == 'open' then
                health.map.checks.circuit_breakers.map.open = health.map.checks.circuit_breakers.map.open + 1
            end
        end
        
        if health.map.checks.circuit_breakers.map.open > 0 then
            health.map.checks.circuit_breakers.map.status = 'warning'
        end
        
        -- Set overall status based on checks
        for _, check in pairs(health.map.checks) do
            if check.map.status == 'warning' then
                health.map.status = 'degraded'
                break
            end
        end
        
        -- Update health check metrics
        track_metric(site_id, 'health_check', 1, {
            status = health.map.status,
            duration = server.call('TIME')[1] - start_time
        })
        
        -- Return RESP3 encoded response
        return health
    end

    return check_health(ARGV[1])
    LUA;

    /**
    * TRACK_ERROR: Error tracking with site isolation
    */
    private const TRACK_ERROR = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end
    if not ARGV[2] then
        return server.error_reply("Error type required")
    end
    if not ARGV[3] then
        return server.error_reply("Error message required")
    end

    local function track_error_with_context(site_id, error_type, message, context)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Build error tracking keys in site's slot
        local base = '{' .. site_id .. '}:errors'
        local keys = {
            recent = base .. ':recent',
            counts = base .. ':counts',
            details = base .. ':details'
        }
        
        -- Create error entry with RESP3 map structure
        local error_id = site_id .. ':' .. start_time .. ':' .. string.format("%x", math.random(1000000))
        local error_data = {
            map = {
                id = error_id,
                type = error_type,
                message = message,
                context = context,
                timestamp = { double = start_time }  -- RESP3 double type
            }
        }
        
        -- Track recent errors with automatic cleanup
        server.call('ZADD', keys.recent, start_time, error_id)
        server.call('ZREMRANGEBYRANK', keys.recent, 0, -1001)  -- Keep last 1000
        
        -- Store error details with RESP3 encoding
        server.call('HSET', keys.details, error_id, cjson.encode(error_data))
        server.call('EXPIRE', keys.details, 86400)  -- Keep for 24 hours
        
        -- Update error counts
        server.call('HINCRBY', keys.counts, error_type, 1)
        
        -- Track in metrics
        track_metric(site_id, 'errors', 1, {
            type = error_type,
            latency = server.call('TIME')[1] * 1000000 + server.call('TIME')[2] - start_time
        })
        
        -- Return error ID as RESP3 string
        return { verbatim_string = {
            format = "txt",
            string = error_id
        }}
    end

    -- Parse context if provided
    local context = ARGV[4] and cjson.decode(ARGV[4]) or {}

    return track_error_with_context(ARGV[1], ARGV[2], ARGV[3], context)
    LUA;

    /**
    * SCAN_CLUSTER: Cluster-safe pattern scanning
    */
    private const SCAN_CLUSTER = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end
    if not ARGV[2] then
        return server.error_reply("Pattern required")
    end

    local function scan_cluster(site_id, pattern, cursor, count)
        local start_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        
        -- Ensure pattern has proper site isolation
        if not pattern:match('^{' .. site_id .. '}') then
            pattern = '{' .. site_id .. '}:' .. pattern
        end
        
        -- Perform scan with count limit
        local result = server.call('SCAN', cursor, 'MATCH', pattern, 'COUNT', count)
        
        -- Structure response as RESP3 map
        local response = {
            map = {
                cursor = result[1],
                keys = { set = {} }  -- RESP3 set type for unique keys
            }
        }
        
        -- Convert keys to set
        for _, key in ipairs(result[2]) do
            response.map.keys.set[key] = true
        end
        
        -- Track scan metrics with RESP3 doubles for precise timing
        local end_time = server.call('TIME')[1] * 1000000 + server.call('TIME')[2]
        track_metric(site_id, 'scans', 1, {
            keys_found = #result[2],
            cursor = result[1],
            latency = { double = (end_time - start_time) / 1000000.0 }
        })
        
        return response
    end

    return scan_cluster(
        ARGV[1],                    -- Site ID
        ARGV[2],                    -- Pattern
        tonumber(ARGV[3] or 0),     -- Cursor
        tonumber(ARGV[4] or 10)     -- Count
    )
    LUA;

    /**
     * Registry of all monitoring scripts
     */
    public const SCRIPTS = [
        // Metrics and Monitoring
        'TRACK_METRIC' => [
            'script' => self::TRACK_METRIC,
            'keys' => 0,
            'args' => [
                'site_id' => 'string',
                'metric_type' => 'string',
                'value' => 'int',
                'extra' => 'array|null'
            ]
        ],
        'METRICS_AGGREGATE' => [
            'script' => self::METRICS_AGGREGATE,
            'keys' => 0,
            'args' => [
                'site_id' => 'string',
                'window' => 'int'
            ]
        ],
        'CLEANUP' => [
            'script' => self::CLEANUP,
            'keys' => 0,
            'args' => [
                'site_id' => 'string'
            ]
        ],
        'HEALTH_CHECK' => [
            'script' => self::HEALTH_CHECK,
            'keys' => 0,
            'args' => [
                'site_id' => 'string'
            ]
        ],
        'TRACK_ERROR' => [
            'script' => self::TRACK_ERROR,
            'keys' => 0,
            'args' => [
                'site_id' => 'string',
                'error_type' => 'string',
                'error_message' => 'string',
                'context' => 'json'
            ]
        ],
        'SCAN_CLUSTER' => [
            'script' => self::SCAN_CLUSTER,
            'keys' => 0,
            'args' => [
                'site_id' => 'string',
                'pattern' => 'string',
                'cursor' => 'int|null',
                'count' => 'int|null'
            ]
        ],
    ];
}