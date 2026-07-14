<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stream Operations Scripts
 * 
 * Scripts for stream operations including:
 * - Stream entry addition
 * - Consumer group creation
 * - Reading from streams
 * - Message acknowledgment
 * - Stream information and management
 * - Pending message handling
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */
class CacheScriptsStreamOperations {
    /**
    * STREAM_ADD: Stream entry addition with backpressure handling
    * Returns RESP3 map with entry status
    */
    private const STREAM_ADD = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Entry data required")
    end
    if not ARGV[2] then
        return server.error_reply("Site ID required")
    end
    
    -- Estimate entry size
    local entry_size = 0
    if ARGV[1] then
        entry_size = #ARGV[1]
    end
    
    if entry_size > {maxEntrySize} then
        return server.error_reply("Entry size exceeds limit")
    end
    
    local function add_stream_entry(stream, entry_data, max_len, site_id)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                success = false,
                timestamp = { double = start_time },
                metrics = { map = {} }
            }
        }
        
        -- Check length and apply backpressure
        local length = server.call('XLEN', stream)
        if length >= max_len then
            -- Trim stream to 50% of max length with metrics
            local trim_target = math.floor(max_len * 0.5)
            server.call('XTRIM', stream, 'MAXLEN', '~', trim_target)
            
            response.map.metrics.trimmed = {
                map = {
                    original_length = length,
                    new_target = trim_target
                }
            }
            track_metric(site_id, 'stream_trimmed', 1)
        end

        -- Safely parse entry data
        local ok, entry = pcall(cjson.decode, entry_data)
        if not ok then
            response.map.error = "Invalid entry data format"
            return response
        end
        
        -- Add entry to stream
        local id = server.call('XADD', stream, 'MAXLEN', '~', max_len, '*', unpack(entry))
        
        if id then
            response.map.success = true
            response.map.entry_id = { verbatim_string = { format = "txt", string = id } }
            response.map.metrics.produced = 1
            track_metric(site_id, 'stream_produced', 1)
        end
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return add_stream_entry(
        KEYS[1],                    -- stream
        ARGV[1],                    -- entry_data
        tonumber(ARGV[3] or 10000), -- max_len with default
        ARGV[2]                     -- site_id
    )
    LUA;

    /**
    * STREAM_CREATE_GROUP: Consumer group creation and initialization
    * Returns RESP3 map with group status
    */
    private const STREAM_CREATE_GROUP = <<<'LUA'
    server.setresp(3)

    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Group required")
    end
    if not ARGV[2] then
        return server.error_reply("Site ID required")
    end

    local function create_consumer_group(stream, group, start, site_id)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                group = group,
                success = false,
                timestamp = { double = start_time }
            }
        }
        
        -- Ensure stream exists
        if server.call('EXISTS', stream) == 0 then
            -- Create stream with initialization message
            local init_id = server.call('XADD', stream, '*', 'init', 'true')
            response.map.stream_created = true
            response.map.init_id = { verbatim_string = { format = "txt", string = init_id } }
        end
        
        -- Create group with error handling
        local ok, result = pcall(function()
            return server.call('XGROUP', 'CREATE', stream, group, start, 'MKSTREAM')
        end)
        
        if ok and result then
            response.map.success = true
            track_metric(site_id, 'consumer_groups_created', 1)
        else
            response.map.error = type(result) == 'string' and result or "Group creation failed"
        end
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return create_consumer_group(
        KEYS[1],          -- stream
        ARGV[1],          -- group
        ARGV[3] or '0',   -- start position with default
        ARGV[2]           -- site_id
    )
    LUA;

    /**
    * STREAM_READ_GROUP: Non-blocking stream message reading
    * Returns RESP3 map with messages and stats
    */
    private const STREAM_READ_GROUP = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Group required")
    end
    if not ARGV[2] then
        return server.error_reply("Consumer required")
    end
    if not ARGV[3] then
        return server.error_reply("Site ID required")
    end

    local function read_group_messages(stream, group, consumer, count, site_id)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                group = group,
                consumer = consumer,
                messages = { set = {} },
                timestamp = { double = start_time },
                metrics = { map = {
                    total = 0,
                    latency = { double = 0 }
                }}
            }
        }
        
        -- Read messages with error handling
        local ok, entries = pcall(function()
            return server.call('XREADGROUP',
                'GROUP', group, consumer,
                'COUNT', count,
                'STREAMS', stream, '>'
            )
        end)
        
        if ok and entries then
            -- Process entries into RESP3 format
            if #entries > 0 and #entries[1][2] > 0 then
                response.map.messages = {
                    map = {}
                }
                
                for _, msg in ipairs(entries[1][2]) do
                    local id = msg[1]
                    local data = {}
                    
                    -- Convert message data to map
                    for i = 1, #msg[2], 2 do
                        data[msg[2][i]] = msg[2][i + 1]
                    end
                    
                    -- Add to messages map
                    response.map.messages.map[id] = {
                        map = data
                    }
                end
                
                response.map.metrics.total = #entries[1][2]
                track_metric(site_id, 'messages_read', response.map.metrics.total)
            end
        else
            response.map.error = type(entries) == 'string' and entries or "Read operation failed"
        end
        
        -- Add timing information
        response.map.metrics.latency.double = server.call('TIME')[1] - start_time
        
        return response
    end

    return read_group_messages(
        KEYS[1],                    -- stream
        ARGV[1],                    -- group
        ARGV[2],                    -- consumer
        tonumber(ARGV[4] or 100),   -- count with default
        ARGV[3]                     -- site_id
    )
    LUA;

    /**
    * STREAM_ACK: Message acknowledgment tracking
    * Returns RESP3 map with acknowledgment status
    */
    private const STREAM_ACK = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Group required")
    end
    if not ARGV[2] then
        return server.error_reply("Message ID required")
    end
    if not ARGV[3] then
        return server.error_reply("Site ID required")
    end

    local function acknowledge_message(stream, group, id, site_id)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                group = group,
                message_id = id,
                success = false,
                timestamp = { double = start_time }
            }
        }
        
        -- Acknowledge message with error handling
        local ok, result = pcall(function()
            return server.call('XACK', stream, group, id)
        end)
        
        if ok and result > 0 then
            response.map.success = true
            track_metric(site_id, 'messages_acknowledged', 1)
        else
            response.map.error = type(result) == 'string' and result or "Acknowledgment failed"
        end
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return acknowledge_message(KEYS[1], ARGV[1], ARGV[2], ARGV[3])
    LUA;

    /**
    * STREAM_INFO: stream information gathering
    * Returns RESP3 map with detailed stream stats
    */
    private const STREAM_INFO = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end

    local function get_stream_info(stream, site_id)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                timestamp = { double = start_time },
                info = { map = {} },
                groups = { map = {} },
                metrics = { map = {} }
            }
        }
        
        -- Gather stream information with error handling
        local ok, info = pcall(function()
            return server.call('XINFO', 'STREAM', stream)
        end)
        
        if ok and info then
            -- Convert info to RESP3 map format
            response.map.info = {
                map = {
                    length = info.length or 0,
                    radix_tree_keys = info['radix-tree-keys'] or 0,
                    radix_tree_nodes = info['radix-tree-nodes'] or 0,
                    last_generated_id = { verbatim_string = { format = "txt", string = info['last-generated-id'] or '' } },
                    entries_added = { double = info['entries-added'] or 0 }
                }
            }
            
            -- Add first/last entries if present
            if info['first-entry'] then
                response.map.info.map.first_entry = {
                    map = {
                        id = info['first-entry'][1],
                        data = info['first-entry'][2]
                    }
                }
            end
            
            if info['last-entry'] then
                response.map.info.map.last_entry = {
                    map = {
                        id = info['last-entry'][1],
                        data = info['last-entry'][2]
                    }
                }
            end
        else
            response.map.info.error = type(info) == 'string' and info or "Info retrieval failed"
        end
        
        -- Get group information
        local ok, groups = pcall(function()
            return server.call('XINFO', 'GROUPS', stream)
        end)
        
        if ok and groups then
            for _, group in ipairs(groups) do
                response.map.groups.map[group.name] = {
                    map = {
                        consumers = group.consumers,
                        pending = group.pending,
                        last_delivered = { verbatim_string = { format = "txt", string = group['last-delivered-id'] } }
                    }
                }
            end
        end
        
        -- Get stream metrics
        response.map.metrics = {
            map = {
                produced = tonumber(server.call('HGET', '{' .. site_id .. '}:metrics', 'stream_produced')) or 0,
                consumed = tonumber(server.call('HGET', '{' .. site_id .. '}:metrics', 'messages_read')) or 0,
                acknowledged = tonumber(server.call('HGET', '{' .. site_id .. '}:metrics', 'messages_acknowledged')) or 0,
                trimmed = tonumber(server.call('HGET', '{' .. site_id .. '}:metrics', 'stream_trimmed')) or 0
            }
        }
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return get_stream_info(KEYS[1], ARGV[1])
    LUA;

    /**
    * STREAM_TRIM: Manual stream trimming with metrics
    * Returns RESP3 map with trim results
    */
    private const STREAM_TRIM = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end

    local function trim_stream(stream, max_len, site_id)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                max_length = max_len,
                trimmed = 0,
                timestamp = { double = start_time }
            }
        }
        
        -- Trim stream with error handling
        local ok, trimmed = pcall(function()
            return server.call('XTRIM', stream, 'MAXLEN', '~', max_len)
        end)
        
        if ok then
            response.map.trimmed = trimmed
            if trimmed > 0 then
                track_metric(site_id, 'stream_trimmed', 1, {
                    entries = trimmed,
                    max_len = max_len,
                    latency = { double = server.call('TIME')[1] - start_time }
                })
            end
        else
            response.map.error = type(trimmed) == 'string' and trimmed or "Trim operation failed"
        end
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return trim_stream(
        KEYS[1],
        tonumber(ARGV[2] or 10000), -- max_len with default
        ARGV[1]                     -- site_id
    )
    LUA;

    /**
    * STREAM_PENDING: Retrieve pending messages for consumer group
    * Returns RESP3 map with pending message details
    */
    private const STREAM_PENDING = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Group required")
    end
    if not ARGV[2] then
        return server.error_reply("Site ID required")
    end

    local function get_pending_messages(stream, group, site_id, start, end, count, consumer)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                group = group,
                consumer = consumer,
                pending = { set = {} },
                timestamp = { double = start_time },
                metrics = { map = {
                    count = 0,
                    total_pending = 0
                }}
            }
        }
        
        -- Get pending entries with error handling
        local ok, pending = pcall(function()
            if consumer then
                -- Get pending for specific consumer
                return server.call('XPENDING', stream, group, start, end, count, consumer)
            else
                -- Get pending for whole group
                return server.call('XPENDING', stream, group, start, end, count)
            end
        end)
        
        if ok and pending then
            -- Convert pending entries to RESP3 map structure
            response.map.pending = {
                map = {}
            }
            
            for _, entry in ipairs(pending) do
                local message_id = entry[1]
                response.map.pending.map[message_id] = {
                    map = {
                        consumer = entry[2],
                        idle_time = { double = entry[3] / 1000.0 }, -- Convert to seconds
                        delivery_count = entry[4]
                    }
                }
            end
            
            response.map.metrics.count = #pending
            
            -- Get total pending count
            local total = server.call('XPENDING', stream, group)
            response.map.metrics.total_pending = total.count or 0
            
            -- Track metrics
            track_metric(site_id, 'pending_messages_checked', 1, {
                stream = stream,
                group = group,
                consumer = consumer or 'all',
                count = response.map.metrics.count,
                latency = { double = server.call('TIME')[1] - start_time }
            })
        else
            response.map.error = type(pending) == 'string' and pending or "Pending message retrieval failed"
        end
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return get_pending_messages(
        KEYS[1],                    -- stream
        ARGV[1],                    -- group
        ARGV[2],                    -- site_id
        ARGV[3] or '-',            -- start (default all)
        ARGV[4] or '+',            -- end (default all)
        tonumber(ARGV[5] or 100),  -- count (default 100)
        ARGV[6]                     -- consumer (optional)
    )
    LUA;

    /**
    * STREAM_CLAIM: Claim pending messages for consumer
    * Returns RESP3 map with claim results
    */
    private const STREAM_CLAIM = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Group required")
    end
    if not ARGV[2] then
        return server.error_reply("Consumer required")
    end
    if not ARGV[3] then
        return server.error_reply("Min idle time required")
    end
    if not ARGV[4] then
        return server.error_reply("Site ID required")
    end

    local min_idle_time = tonumber(ARGV[3])
    if not min_idle_time or min_idle_time < 0 then
        return server.error_reply("Invalid min idle time")
    end

    local function claim_pending_messages(stream, group, consumer, min_idle_time, site_id, start, end, count)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                group = group,
                consumer = consumer,
                claimed = { set = {} },
                timestamp = { double = start_time },
                metrics = { map = {
                    total_claimed = 0,
                    idle_threshold = { double = min_idle_time / 1000.0 } -- Convert to seconds
                }}
            }
        }
        
        -- Get pending messages with error handling
        local ok, pending = pcall(function()
            return server.call('XPENDING', stream, group, start, end, count)
        end)
        
        if not ok or not pending then
            response.map.error = type(pending) == 'string' and pending or "Failed to get pending messages"
            return response
        end
        
        -- Process eligible messages
        local claim_count = 0
        response.map.claimed = {
            map = {}
        }
        
        for _, entry in ipairs(pending) do
            local message_id = entry[1]
            local current_consumer = entry[2]
            local idle_time = entry[3]
            
            -- Check eligibility
            if current_consumer ~= consumer and idle_time >= min_idle_time then
                -- Claim message with error handling
                local claim_ok, result = pcall(function()
                    return server.call('XCLAIM', stream, group, consumer, min_idle_time, message_id)
                end)
                
                if claim_ok and result and #result > 0 then
                    -- Convert claimed message to RESP3 format
                    response.map.claimed.map[message_id] = {
                        map = {
                            previous_consumer = current_consumer,
                            idle_time = { double = idle_time / 1000.0 },
                            claimed_at = { double = start_time }
                        }
                    }
                    claim_count = claim_count + 1
                end
            end
        end
        
        -- Update metrics
        response.map.metrics.total_claimed = claim_count
        
        -- Track metrics
        track_metric(site_id, 'messages_claimed', claim_count, {
            stream = stream,
            group = group,
            consumer = consumer,
            min_idle_time = min_idle_time,
            latency = { double = server.call('TIME')[1] - start_time }
        })
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return claim_pending_messages(
        KEYS[1],                    -- stream
        ARGV[1],                    -- group
        ARGV[2],                    -- consumer
        min_idle_time,              -- min_idle_time
        ARGV[4],                    -- site_id
        ARGV[5] or '-',            -- start (default all)
        ARGV[6] or '+',            -- end (default all)
        tonumber(ARGV[7] or 100)   -- count (default 100)
    )
    LUA;

    /**
    * STREAM_DEL: Delete messages from stream
    * Returns RESP3 map with deletion results
    */
    private const STREAM_DEL = <<<'LUA'
    server.setresp(3)
    
    -- Input validation
    if not KEYS[1] then
        return server.error_reply("Stream required")
    end
    if not ARGV[1] then
        return server.error_reply("Site ID required")
    end
    if not ARGV[2] then
        return server.error_reply("Message IDs required")
    end

    -- Parse message IDs safely
    local ok, message_ids = pcall(cjson.decode, ARGV[2])
    if not ok or type(message_ids) ~= 'table' then
        return server.error_reply("Invalid message IDs format")
    end
    if #message_ids == 0 then
        return server.error_reply("Empty message IDs list")
    end

    local function delete_messages(stream, message_ids, site_id)
        local start_time = server.call('TIME')[1]
        
        -- Initialize RESP3 response structure
        local response = {
            map = {
                stream = stream,
                deleted = { set = {} },
                timestamp = { double = start_time },
                metrics = { map = {
                    total_deleted = 0,
                    attempted = #message_ids
                }}
            }
        }
        
        -- Delete messages with error handling
        local ok, deleted = pcall(function()
            return server.call('XDEL', stream, unpack(message_ids))
        end)
        
        if ok then
            response.map.metrics.total_deleted = deleted
            
            -- Track successfully deleted messages
            for i, id in ipairs(message_ids) do
                if i <= deleted then
                    response.map.deleted.set[id] = true
                end
            end
            
            -- Track metrics
            track_metric(site_id, 'messages_deleted', deleted, {
                stream = stream,
                attempted = #message_ids,
                latency = { double = server.call('TIME')[1] - start_time }
            })
        else
            response.map.error = type(deleted) == 'string' and deleted or "Message deletion failed"
        end
        
        -- Add timing information
        response.map.duration = { double = server.call('TIME')[1] - start_time }
        
        return response
    end

    return delete_messages(KEYS[1], message_ids, ARGV[1])
    LUA;
    
    /**
     * Registry of all stream operation scripts
     */
    public const SCRIPTS = [
        // Stream Operations
        'STREAM_ADD' => [
            'script' => self::STREAM_ADD,
            'keys' => 1,
            'args' => [
                'entry_data' => 'json',
                'site_id' => 'string',
                'max_len' => 'int'
            ]
        ],
        'STREAM_CREATE_GROUP' => [
            'script' => self::STREAM_CREATE_GROUP,
            'keys' => 1,
            'args' => [
                'group' => 'string',
                'site_id' => 'string',
                'start' => 'string'  // Can be '0' or '$'
            ]
        ],
        'STREAM_READ_GROUP' => [
            'script' => self::STREAM_READ_GROUP,
            'keys' => 1,
            'args' => [
                'group' => 'string',
                'consumer' => 'string',
                'site_id' => 'string',
                'count' => 'int'
            ]
        ],
        'STREAM_ACK' => [
            'script' => self::STREAM_ACK,
            'keys' => 1,
            'args' => [
                'group' => 'string',
                'id' => 'string',      // Stream message ID
                'site_id' => 'string'
            ]
        ],
        'STREAM_INFO' => [
            'script' => self::STREAM_INFO,
            'keys' => 1,
            'args' => [
                'site_id' => 'string'
            ]
        ],
        'STREAM_TRIM' => [
            'script' => self::STREAM_TRIM,
            'keys' => 1,
            'args' => [
                'site_id' => 'string',
                'max_len' => 'int'
            ]
        ],
        'STREAM_PENDING' => [
            'script' => self::STREAM_PENDING,
            'keys' => 1,
            'args' => [
                'group' => 'string',
                'site_id' => 'string',
                'start' => 'string|null',
                'end' => 'string|null', 
                'count' => 'int|null',
                'consumer' => 'string|null'
            ]
        ],
        'STREAM_CLAIM' => [
            'script' => self::STREAM_CLAIM,
            'keys' => 1,
            'args' => [
                'group' => 'string',
                'consumer' => 'string',
                'min_idle_time' => 'int',
                'site_id' => 'string',
                'start' => 'string|null',
                'end' => 'string|null',
                'count' => 'int|null'
            ]
        ],
        'STREAM_DEL' => [
            'script' => self::STREAM_DEL,
            'keys' => 1,
            'args' => [
                'site_id' => 'string',
                'message_ids' => 'json'
            ]
        ]
    ];
}