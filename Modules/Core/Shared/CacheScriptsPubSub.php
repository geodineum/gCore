<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publish/Subscribe Scripts
 * 
 * Scripts for publish/subscribe operations including:
 * - Message publishing with persistence
 * - Subscription management
 * - Unsubscription management
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */
class CacheScriptsPubSub {
    /**
     * PUBLISH: Message publish with RESP3 map returns
     */
    private const PUBLISH = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Channel required")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end
    if not ARGV[2] then
        return server.error_reply("Message required")
    end

    local function publish_message(channel, message, site_id, options)
        local start_time = server.call('TIME')[1]
        options = options or {}
        
        -- Generate message ID if not provided
        local msg_id = options.id or string.format(
            "%s-%s-%s",
            site_id,
            string.format("%x", start_time),
            string.format("%x", math.random(1000000))
        )
        
        -- Deduplication check with sliding window (last hour)
        local dedup_key = '{' .. site_id .. '}:msg:dedup'
        local cutoff = start_time - 3600
        
        -- Cleanup old message IDs first for memory efficiency
        server.call('ZREMRANGEBYSCORE', dedup_key, 0, cutoff)
        
        -- Check for duplicate
        if server.call('ZSCORE', dedup_key, msg_id) then
            track_metric(site_id, 'messages_deduplicated', 1)
            return { map = {
                success = false,
                reason = 'duplicate',
                receivers = 0
            }}
        end
        
        -- Record message metadata
        server.call('ZADD', dedup_key, start_time, msg_id)
        server.call('EXPIRE', dedup_key, 3600)
        
        -- Track in stream for replay capability
        local stream_key = '{' .. site_id .. '}:stream:' .. channel
        local stream_id = server.call('XADD', stream_key,
            'MAXLEN', '~', options.max_stream_len or 10000,
            '*',
            'id', msg_id,
            'message', message,
            'site', site_id,
            'timestamp', start_time,
            'options', cjson.encode(options)
        )
        
        -- Handle persistence if requested
        if options.persistent then
            local persist_key = '{' .. site_id .. '}:msg:store:' .. channel
            local msg_data = {
                id = msg_id,
                message = message,
                timestamp = start_time,
                stream_id = stream_id,
                options = options
            }
            
            server.call('HSET', persist_key, msg_id, cjson.encode(msg_data))
            
            if options.ttl then
                server.call('EXPIRE', persist_key, options.ttl)
            end
        end
        
        -- Publish message
        local receivers = server.call('PUBLISH', channel, message)
        
        -- Update metrics
        track_metric(site_id, 'messages_published', 1, {
            channel = channel,
            receivers = receivers,
            size = #message,
            latency = server.call('TIME')[1] - start_time
        })
        
        -- Return RESP3 map
        return { map = {
            success = true,
            stream_id = stream_id,
            receivers = receivers,
            msg_id = msg_id
        }}
    end

    -- Parse options
    local options = ARGV[3] and cjson.decode(ARGV[3]) or {}

    return publish_message(KEYS[1], ARGV[2], ARGV[1], options)
    LUA;

    /**
     * SUBSCRIBE: Subscription management with RESP3 status
     */
    private const SUBSCRIBE = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Channel required")
    end
    if not ARGV[1] then 
        return server.error_reply("Consumer ID required")
    end
    if not ARGV[2] then
        return server.error_reply("Site ID required") 
    end

    local function manage_subscription(channel, consumer_id, site_id)
        local consumers_key = '{' .. site_id .. '}:consumers:' .. channel
        local subscriptions_key = '{' .. site_id .. '}:subscriptions:' .. channel
        
        -- Register consumer
        server.call('SADD', consumers_key, consumer_id)
        
        -- Prepare metadata as RESP3 map
        local metadata = {
            timestamp = server.call('TIME')[1],
            node = consumer_id:match("^([^:]+)"),
            status = 'active'
        }
        
        -- Set metadata
        server.call('HSET', subscriptions_key, consumer_id, cjson.encode(metadata))
        
        -- Track metric
        track_metric(site_id, 'subscriptions', 1, {
            channel = channel,
            consumer = consumer_id
        })
        
        return server.status_reply("OK")
    end

    return manage_subscription(KEYS[1], ARGV[1], ARGV[2])
    LUA;

    /**
     * UNSUBSCRIBE: Unsubscription management with RESP3 boolean
     */
    private const UNSUBSCRIBE = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Channel required")
    end
    if not ARGV[1] then
        return server.error_reply("Consumer ID required")
    end
    if not ARGV[2] then
        return server.error_reply("Site ID required")
    end

    local function remove_subscription(channel, consumer_id, site_id)
        local consumers_key = '{' .. site_id .. '}:consumers:' .. channel
        local subscriptions_key = '{' .. site_id .. '}:subscriptions:' .. channel
        
        -- Remove consumer registration
        local removed = server.call('SREM', consumers_key, consumer_id)
        server.call('HDEL', subscriptions_key, consumer_id)
        
        -- Track unsubscribe event if consumer existed
        if removed > 0 then
            track_metric(site_id, 'unsubscribes', 1, {
                channel = channel,
                consumer = consumer_id
            })
        end
        
        return removed > 0 -- RESP3 boolean
    end

    return remove_subscription(KEYS[1], ARGV[1], ARGV[2])
    LUA;

    /**
     * Registry of all publish/subscribe scripts
     */
    public const SCRIPTS = [
        // PUBLISH / SUBSCRIBE
        'PUBLISH' => [
            'script' => self::PUBLISH,
            'keys' => 1,
            'args' => [
                'site_id' => 'string',
                'message' => 'string',
                'options' => 'json'
            ]
        ],
        'SUBSCRIBE' => [
            'script' => self::SUBSCRIBE,
            'keys' => 1,
            'args' => [
                'consumer_id' => 'string',
                'site_id' => 'string'
            ]
        ],
        'UNSUBSCRIBE' => [
            'script' => self::UNSUBSCRIBE,
            'keys' => 1,
            'args' => [
                'consumer_id' => 'string',
                'site_id' => 'string'
            ]
        ],
    ];
}