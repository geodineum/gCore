--[[
  gCore ValKey/Redis Common Utility Functions
  
  Provides shared functionality across all Lua scripts including:
  - Key building with proper namespace isolation
  - Metric tracking and telemetry
  - Input validation
  - Error handling
  - Versioning and compatibility functions
  - Geometric topology integration
  
  This script is meant to be prepended to all scripts at registration time
  to reduce duplication and improve maintainability.
  
  Version 2.0.0 (Sprint 23)
  - Enhanced JSON serialization compatibility
  - Added geometric topology integration
  - Improved error handling and validation
  - Added metrics tracking
  - Optimized performance for large-scale deployments
]]--

-- Set RESP3 protocol for proper data types
if redis then
    redis.setresp(3)
elseif server then
    server.setresp(3)
end

--==============================
-- VERSION DETECTION
--==============================

-- Check if we're running on ValKey vs Redis
local is_valkey = false
local server_version = "unknown"

-- Get server info safely
local function detect_server()
    local call_func = redis and redis.call or server.call
    local ok, info = pcall(function()
        return call_func('INFO', 'SERVER')
    end)
    
    if ok and type(info) == "string" then
        -- Check if this is ValKey
        if info:find("valkey_version") then
            is_valkey = true
            server_version = info:match("valkey_version:([%d%.]+)")
        else
            -- Redis OSS likely
            server_version = info:match("redis_version:([%d%.]+)")
        end
    end
    
    return { 
        is_valkey = is_valkey,
        version = server_version
    }
end

-- Function to parse version string to numeric components
local function parse_version(version_str)
    local major, minor, patch = version_str:match("(%d+)%.(%d+)%.(%d+)")
    if major then
        return {
            major = tonumber(major) or 0,
            minor = tonumber(minor) or 0,
            patch = tonumber(patch) or 0,
            raw = version_str
        }
    end
    return { major = 0, minor = 0, patch = 0, raw = version_str }
end

-- Check if current version is at least the required version
local function version_at_least(required)
    local server_info = detect_server()
    local current = parse_version(server_info.version)
    local req = parse_version(required)
    
    if current.major > req.major then return true end
    if current.major < req.major then return false end
    if current.minor > req.minor then return true end
    if current.minor < req.minor then return false end
    return current.patch >= req.patch
end

-- Check if cluster mode is enabled
local function is_cluster_enabled()
    local call_func = redis and redis.call or server.call
    local ok, result = pcall(function()
        return call_func('CLUSTER', 'INFO')
    end)
    
    if ok and type(result) == "string" and result:find("cluster_state:") then
        return result:find("cluster_state:ok") ~= nil
    end
    return false
end

--==============================
-- KEY BUILDING
--==============================

-- Build a properly namespaced key with site isolation
-- site_id: Required site identifier for multi-tenant isolation
-- key: The logical key name
-- group: Optional group for further namespace isolation
-- prefix: Optional prefix to add before the key
function build_key(key, site_id, group, prefix)
    local call_func = redis and redis.call or server.call
    local error_func = redis and redis.error_reply or server.error_reply
    
    -- Validate arguments
    if not key or key == "" then
        return error_func("Key required")
    end
    
    if not site_id or site_id == "" then
        return error_func("Site ID required")
    end
    
    -- Enable key to be already prefixed with site ID slot for optimized calls
    if key:find("^{" .. site_id .. "}") then
        return key
    end
    
    prefix = prefix or ""
    
    -- Fast path for simple keys (most common case)
    if not group then
        return '{' .. site_id .. '}:' .. prefix .. key
    end
    
    -- Group path needs validation
    local group_key = '{' .. site_id .. '}:groups'
    local exists = call_func('EXISTS', group_key)
    if exists == 0 then
        -- Create groups key if it doesn't exist
        call_func('HSET', group_key, group, '{}')
    else
        -- Verify group exists
        if call_func('HEXISTS', group_key, group) == 0 then
            return error_func("Invalid group: " .. group)
        end
    end
    
    -- Return fully namespaced key with group
    return '{' .. site_id .. '}:' .. prefix .. group .. ':' .. key
end

--==============================
-- METRIC TRACKING
--==============================

-- Track metrics with proper namespacing and error handling
-- site_id: The site ID for metrics namespacing
-- metric_type: The type of metric (e.g., 'hits', 'misses', 'latency:get')
-- value: Value to increment/add (defaults to 1)
-- extra: Optional table of extra information to store
function track_metric(site_id, metric_type, value, extra)
    local call_func = redis and redis.call or server.call
    
    -- Guard against missing arguments
    if not site_id or site_id == "" then
        return false
    end
    
    if not metric_type then
        return false
    end
    
    -- Set defaults
    value = tonumber(value) or 1
    
    -- Safely execute metric tracking with pcall
    local ok, result = pcall(function()
        -- Site-specific metrics
        local site_metrics = '{' .. site_id .. '}:metrics'
        call_func('HINCRBY', site_metrics, metric_type, value)
        
        -- Store additional metric data if provided
        if extra then
            local details_key = site_metrics .. ':details:' .. metric_type
            local encoded = nil
            
            -- Safely encode extra data
            local encode_ok, encode_result = pcall(function()
                return cjson.encode(extra)
            end)
            
            if encode_ok then
                encoded = encode_result
                call_func('LPUSH', details_key, encoded)
                call_func('LTRIM', details_key, 0, 999)  -- Keep last 1000 entries
            end
        end
        
        -- Global metrics if enabled
        local global_metrics_enabled = call_func('GET', 'global_metrics_enabled')
        if global_metrics_enabled == '1' then
            call_func('HINCRBY', '{global}:metrics', metric_type, value)
        end
        
        -- Performance tracking for latency metrics
        if metric_type:match('^latency:') then
            local latency_key = '{' .. site_id .. '}:latency'
            local time = call_func('TIME')
            call_func('ZADD', latency_key, value, tostring(time[1]))
            call_func('ZREMRANGEBYRANK', latency_key, 0, -10001)  -- Keep last 10K samples
        end
        
        return true
    end)
    
    -- Return success/failure
    return ok
end

--==============================
-- VALIDATION FUNCTIONS
--==============================

-- Validate a batch of keys for security and performance constraints
-- keys: Array of keys to validate
-- max_batch_size: Maximum number of keys in a batch
-- max_key_length: Maximum length of each key
function validate_keys(keys, max_batch_size, max_key_length)
    -- Set defaults if not provided
    max_batch_size = max_batch_size or 1000
    max_key_length = max_key_length or 256
    
    -- Check batch size
    if #keys > max_batch_size then
        return { err = "Batch size exceeds limit of " .. max_batch_size }
    end
    
    -- Check key lengths
    for _, key in ipairs(keys) do
        if type(key) ~= "string" then
            return { err = "Keys must be strings" }
        end
        
        if #key > max_key_length then
            return { err = "Key length exceeds " .. max_key_length .. " bytes" }
        end
    end
    
    return true
end

-- Validate cluster slot consistency for multiple keys
-- keys: Array of keys to validate
function validate_slot_consistency(keys)
    local call_func = redis and redis.call or server.call
    
    -- Skip validation if no keys or cluster mode is not enabled
    if #keys == 0 or not is_cluster_enabled() then
        return true
    end
    
    -- Safely get the slot of the first key
    local ok, base_slot = pcall(function()
        return call_func('CLUSTER', 'KEYSLOT', keys[1])
    end)
    
    if not ok then
        return true -- Not in cluster mode or other issue, ignore
    end
    
    -- Check if all keys hash to the same slot
    for i=2, #keys do
        local key_ok, slot = pcall(function()
            return call_func('CLUSTER', 'KEYSLOT', keys[i])
        end)
        
        if key_ok and slot ~= base_slot then
            return { err = "Keys must hash to same slot for atomic operations" }
        end
    end
    
    return true
end

-- Validate permissions for security operations
-- permissions: Table of permission settings
function validate_permissions(permissions)
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
    
    -- Validate structure
    if type(permissions) ~= 'table' then
        return {
            valid = false,
            error = "Permissions must be a table"
        }
    end
    
    -- Validate each permission
    for perm_type, value in pairs(permissions) do
        if not valid_types[perm_type] then
            return {
                valid = false,
                error = "Invalid permission type: " .. perm_type
            }
        end
        if not valid_values[value] then
            return {
                valid = false,
                error = "Invalid permission value for " .. perm_type
            }
        end
    end
    
    -- Return success
    return {
        valid = true,
        permissions = permissions
    }
end

-- Check for circular dependencies in a graph-like structure
-- site_id: Site identifier for key namespacing
-- node: Current node to check
-- ancestors: Set of ancestor nodes in the current path
function detect_cycles(site_id, node, ancestors)
    local call_func = redis and redis.call or server.call
    
    -- Initialize ancestor tracking
    ancestors = ancestors or {}
    
    -- Check immediate cycle
    if ancestors[node] then
        return { has_cycle = true, path = ancestors }
    end
    
    -- Get parent with proper site isolation
    local parent_key = '{' .. site_id .. '}:groups:' .. node .. ':parent'
    local parent = call_func('GET', parent_key)
    
    -- No parent means no cycle possible
    if not parent then
        return { has_cycle = false }
    end
    
    -- Build new ancestors set with current node
    local new_ancestors = { [node] = true }
    for k, v in pairs(ancestors) do
        new_ancestors[k] = v
    end
    
    -- Recursive check
    return detect_cycles(site_id, parent, new_ancestors)
end

--==============================
-- TIME HELPERS
--==============================

-- Get current time (Unix timestamp with microseconds)
-- Returns: { seconds, microseconds, total_microseconds }
function get_time()
    local call_func = redis and redis.call or server.call
    local time = call_func('TIME')
    local seconds = tonumber(time[1])
    local microseconds = tonumber(time[2])
    return {
        seconds = seconds,
        microseconds = microseconds,
        total_microseconds = seconds * 1000000 + microseconds
    }
end

-- Calculate latency between time points in microseconds
-- start_time: Time point from get_time()
-- Returns: Latency in microseconds
function calculate_latency(start_time)
    local end_time = get_time()
    return end_time.total_microseconds - start_time.total_microseconds
end

--==============================
-- UTILITY FUNCTIONS
--==============================

-- Safely encode a value to JSON with error handling
-- value: The value to encode
-- Returns: encoded string or error
function safe_json_encode(value)
    local ok, result = pcall(function()
        return cjson.encode(value)
    end)
    
    if not ok then
        return nil, "JSON encoding failed: " .. tostring(result)
    end
    
    return result
end

-- Safely decode JSON with error handling
-- json_str: The JSON string to decode
-- Returns: decoded value or error
function safe_json_decode(json_str)
    local ok, result = pcall(function()
        return cjson.decode(json_str)
    end)
    
    if not ok then
        return nil, "JSON decoding failed: " .. tostring(result)
    end
    
    return result
end

-- Safe function execution with Lua sandbox
-- code: Lua code string to execute
-- context: Optional context table to pass to function
-- Returns: result and error if any
function safe_execute(code, context)
    -- Attempt to load the code
    local func, compile_err = loadstring and loadstring(code) or load(code)
    if not func then
        return nil, "Code compilation failed: " .. compile_err
    end
    
    -- Create a sandbox environment
    local env = {}
    -- Copy only safe globals to the sandbox
    for k, v in pairs(_G) do
        if k ~= "loadstring" and k ~= "load" and 
           k ~= "dofile" and k ~= "loadfile" and
           k ~= "os" and k ~= "io" and k ~= "debug" then
            env[k] = v
        end
    end
    
    -- Add context to environment if provided
    if context then
        for k, v in pairs(context) do
            env[k] = v
        end
    end
    
    -- Set environment for function
    if setfenv then -- For Lua 5.1
        setfenv(func, env)
    else -- For Lua 5.2+
        debug.setupvalue(func, 1, env)
    end
    
    -- Execute the function safely
    local success, result = pcall(func)
    if not success then
        return nil, "Execution failed: " .. result
    end
    
    return result
end

--==============================
-- GEOMETRIC TOPOLOGY FUNCTIONS
--==============================

-- Check if a topology key exists
-- topology_key: Key where topology data is stored
function topology_exists(topology_key)
    local call_func = redis and redis.call or server.call
    return call_func('EXISTS', topology_key) == 1
end

-- Get a capability dimension mapping from a topology
-- topology_key: Key where topology data is stored
function get_capability_dimensions(topology_key)
    local call_func = redis and redis.call or server.call
    local error_func = redis and redis.error_reply or server.error_reply
    
    -- Get topology data
    local topology_data = call_func('GET', topology_key)
    if not topology_data then
        return error_func("Topology not found")
    end
    
    -- Parse topology data
    local topology, err = safe_json_decode(topology_data)
    if not topology then
        return error_func("Invalid topology data: " .. (err or ""))
    end
    
    -- Return capability dimensions
    return topology.capability_dimensions or {}
end

-- Calculate the Manhattan distance between two points
-- point1: First point vector
-- point2: Second point vector
function manhattan_distance(point1, point2)
    local distance = 0
    for i=1, math.max(#point1, #point2) do
        local val1 = point1[i] or 0
        local val2 = point2[i] or 0
        distance = distance + math.abs(val1 - val2)
    end
    return distance
end

-- Convert a capability feature vector to a point
-- capabilities: Array of {name, value} pairs
-- dimensions: Map of capability names to dimensions
-- max_dim: Maximum dimension
function capabilities_to_point(capabilities, dimensions, max_dim)
    local point = {}
    
    -- Initialize point
    for i=1, max_dim do
        point[i] = 0
    end
    
    -- Set capability values
    for _, cap in ipairs(capabilities) do
        local dim = dimensions[cap.name]
        if dim and dim <= max_dim then
            point[dim] = cap.value
        end
    end
    
    return point
end