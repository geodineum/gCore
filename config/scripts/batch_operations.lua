--[[
  ValKey Batch Operations Script
  
  Provides atomic multi-operation execution for batch processing
  Handles multiple operations in a single Lua script for atomicity
  
  Arguments (JSON mode):
  KEYS: [] (No keys needed)
  ARGV[1]: Site ID for key namespace
  ARGV[2]: JSON-encoded array of operations (format: [["command", "arg1", "arg2", ...], ...])
  
  Arguments (Bulk mode):
  KEYS: [key1, key2, key3, ...]
  ARGV[1]: Site ID for key namespace
  ARGV[2]: Operation type (MGET, MSET, MDEL, etc.)
  ARGV[3+]: Operation-specific arguments
  
  Returns:
  JSON-encoded array of results, in same order as input operations (JSON mode)
  Array of results or count of successful operations (Bulk mode)
]]--

-- Helper function to build site-specific key
local function build_key(site_id, key)
    return '{' .. site_id .. '}:' .. key
end

-- Function to execute a batch of operations in JSON format
local function batch_exec(site_id, operations_json)
    local results = {}
    local operations = cjson.decode(operations_json)
    
    -- Process each operation
    for i, op in ipairs(operations) do
        local cmd = op[1]:upper()
        local args = {}
        
        -- Extract command arguments, starting from index 2
        for j = 2, #op do
            -- Add site ID namespace to keys
            if j == 2 and cmd ~= "EVAL" and cmd ~= "EVALSHA" then
                table.insert(args, build_key(site_id, op[j]))
            else
                table.insert(args, op[j])
            end
        end
        
        -- Execute the command based on type
        if cmd == "GET" then
            results[i] = redis.call("GET", args[1])
            
        elseif cmd == "SET" then
            -- Handle SET with TTL
            if #args >= 3 and tonumber(args[3]) > 0 then
                redis.call("SET", args[1], args[2])
                redis.call("EXPIRE", args[1], tonumber(args[3]))
                results[i] = true
            else
                results[i] = redis.call("SET", args[1], args[2])
            end
            
        elseif cmd == "DEL" then
            results[i] = redis.call("DEL", args[1])
            
        elseif cmd == "EXISTS" then
            results[i] = redis.call("EXISTS", args[1])
            
        elseif cmd == "EXPIRE" then
            results[i] = redis.call("EXPIRE", args[1], tonumber(args[2]))
            
        elseif cmd == "TTL" then
            results[i] = redis.call("TTL", args[1])
            
        elseif cmd == "INCR" then
            results[i] = redis.call("INCR", args[1])
            
        elseif cmd == "DECR" then
            results[i] = redis.call("DECR", args[1])
            
        elseif cmd == "HINCRBY" then
            results[i] = redis.call("HINCRBY", args[1], args[2], tonumber(args[3]))
            
        elseif cmd == "HGET" then
            results[i] = redis.call("HGET", args[1], args[2])
            
        elseif cmd == "HSET" then
            results[i] = redis.call("HSET", args[1], args[2], args[3])
            
        elseif cmd == "HDEL" then
            results[i] = redis.call("HDEL", args[1], args[2])
            
        else
            -- For unsupported commands, return an error
            results[i] = {err = "Unsupported command: " .. cmd}
        end
    end
    
    -- Convert results to JSON
    return cjson.encode(results)
end

-- Function to execute bulk operations (MGET, MSET, etc.)
local function bulk_exec(site_id, operation, keys, argv_offset)
    local results = {}
    local success_count = 0
    
    -- Process based on operation type
    if operation == "MGET" then
        -- Multi-GET operation
        for i=1, #keys do
            local val = redis.call('GET', build_key(site_id, keys[i]))
            if val ~= false then
                results[i] = val
                success_count = success_count + 1
            else
                results[i] = false
            end
        end
        return {success_count, results}

    elseif operation == "MSET" then
        -- Multi-SET operation
        -- Format: keys = keys, ARGV[argv_offset+] = values, ARGV[argv_offset+#keys] = TTLs
        local values_offset = argv_offset
        local ttl_offset = argv_offset + #keys
        
        for i=1, #keys do
            local key = build_key(site_id, keys[i])
            local value = ARGV[values_offset + i - 1]
            local ttl = tonumber(ARGV[ttl_offset + i - 1])
            
            -- Set the value
            redis.call('SET', key, value)
            
            -- Apply TTL if provided
            if ttl and ttl > 0 then
                redis.call('EXPIRE', key, ttl)
            end
            
            success_count = success_count + 1
        end
        return success_count

    elseif operation == "MDEL" then
        -- Multi-DEL operation
        for i=1, #keys do
            local deleted = redis.call('DEL', build_key(site_id, keys[i]))
            success_count = success_count + deleted
        end
        return success_count

    elseif operation == "MEXISTS" then
        -- Multi-EXISTS operation
        for i=1, #keys do
            local exists = redis.call('EXISTS', build_key(site_id, keys[i]))
            results[i] = exists == 1
            if exists == 1 then
                success_count = success_count + 1
            end
        end
        return {success_count, results}

    elseif operation == "MEXPIRE" then
        -- Multi-EXPIRE operation
        -- Format: keys = keys, ARGV[argv_offset+] = TTLs
        local ttl_offset = argv_offset
        
        for i=1, #keys do
            local key = build_key(site_id, keys[i])
            local ttl = tonumber(ARGV[ttl_offset + i - 1])
            
            -- Check if key exists before applying TTL
            if redis.call('EXISTS', key) == 1 then
                redis.call('EXPIRE', key, ttl)
                success_count = success_count + 1
            end
        end
        return success_count

    elseif operation == "MPERSIST" then
        -- Multi-PERSIST operation
        for i=1, #keys do
            local persisted = redis.call('PERSIST', build_key(site_id, keys[i]))
            success_count = success_count + persisted
        end
        return success_count

    elseif operation == "MINCR" then
        -- Multi-INCR operation
        -- Format: keys = keys, ARGV[argv_offset+] = increment values
        local incr_offset = argv_offset
        
        for i=1, #keys do
            local key = build_key(site_id, keys[i])
            local increment = tonumber(ARGV[incr_offset + i - 1] or "1")
            
            local new_value = redis.call('INCRBY', key, increment)
            results[i] = new_value
            success_count = success_count + 1
        end
        return {success_count, results}

    elseif operation == "MTTL" then
        -- Multi-TTL operation
        for i=1, #keys do
            local key = build_key(site_id, keys[i])
            local remaining = redis.call('TTL', key)
            results[i] = remaining
            if remaining > -2 then  -- -2 means key doesn't exist
                success_count = success_count + 1
            end
        end
        return {success_count, results}
        
    elseif operation == "MHGET" then
        -- Multi-HGET operation
        -- Format: keys = hash keys, ARGV[argv_offset+] = field names
        local field_offset = argv_offset
        
        for i=1, #keys do
            local key = build_key(site_id, keys[i])
            local field = ARGV[field_offset + i - 1]
            
            local value = redis.call('HGET', key, field)
            results[i] = value
            if value ~= false then
                success_count = success_count + 1
            end
        end
        return {success_count, results}
        
    elseif operation == "MHSET" then
        -- Multi-HSET operation
        -- Format: keys = hash keys, ARGV[argv_offset+] = field names, ARGV[argv_offset+#keys] = values
        local field_offset = argv_offset
        local value_offset = argv_offset + #keys
        
        for i=1, #keys do
            local key = build_key(site_id, keys[i])
            local field = ARGV[field_offset + i - 1]
            local value = ARGV[value_offset + i - 1]
            
            redis.call('HSET', key, field, value)
            success_count = success_count + 1
        end
        return success_count

    else
        -- Unknown operation type
        return redis.error_reply("Unknown batch operation: " .. operation)
    end
end

-- Process the batch operation
local site_id = ARGV[1]
local operation_type = ARGV[2]

-- Check operation mode (JSON or Bulk)
if operation_type == "JSON" then
    -- JSON mode - process JSON operations
    local operations_json = ARGV[3]
    return batch_exec(site_id, operations_json)
else
    -- Bulk mode - process bulk operation
    return bulk_exec(site_id, operation_type, KEYS, 3)
end