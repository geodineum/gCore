<?php
declare(strict_types=1);
namespace gCore\Modules\Managers\Base\CacheManager\Traits;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * StreamCapabilities Trait
 *
 * Adds ValKey/Redis Stream support to CacheManager for high-throughput
 * real-time data processing with backpressure handling.
 *
 * ARCHITECTURE: Uses gNode-Client -> ValKey Lua functions (GNODE_STREAM_*)
 * All stream operations route through gNodeClient->fcall() for proper
 * ACL enforcement and atomic operations.
 *
 * @package     gCore
 * @subpackage  Cache
 * @version     0.2.0
 */

trait StreamCapabilities {
    /** @var array Stream configuration */
    private $streamConfig = [
        'max_len' => 1000000,           // Maximum stream length
        'consumer_batch_size' => 100,    // Messages per batch
        'block_timeout' => 1000,         // Milliseconds to block
        'trim_threshold' => 0.8,         // Trim when at 80% capacity
        'consumer_group_prefix' => 'gcore_'
    ];

    /**
     * Initialize stream capabilities
     */
    private function initializeStreams(array $config = []): void {
        $this->streamConfig = array_merge($this->streamConfig, $config);
    }

    /**
     * Build a proper stream key with site prefix
     *
     * @param string $stream Stream name
     * @return string Full stream key
     */
    private function buildStreamKey(string $stream): string {
        // If stream already has site prefix, use as-is
        if (strpos($stream, $this->nodeMetadata['site_id'] ?? 'default') === 0) {
            return $stream;
        }

        // Otherwise, build key with site prefix
        $siteId = $this->nodeMetadata['site_id'] ?? 'default';
        return "{$siteId}:{$stream}";
    }

    /**
     * Add entry to stream with automatic backpressure and consumer notification
     *
     * Uses GNODE_STREAM_ADD_RESP3 Lua function for atomic operation with
     * automatic backpressure handling and metrics tracking.
     *
     * @param string $stream Stream key
     * @param array $data Entry data
     * @param array $options Stream options
     * @return string|false Entry ID or false on failure
     * @api
     */
    public function streamAdd(string $stream, array $data, array $options = []): string|false {
        if (!$this->initialized) {
            return false;
        }

        // Check for gNode-Client integration
        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_add', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return false;
        }

        try {
            // Prepare entry data with timestamp
            $entry = array_merge([
                'timestamp' => (string)microtime(true),
            ], $data);

            $streamKey = $this->buildStreamKey($stream);
            $siteId = $this->nodeMetadata['site_id'] ?? 'default';
            $maxLen = $options['max_len'] ?? $this->streamConfig['max_len'];

            // Use GNODE_STREAM_ADD_RESP3 for backpressure handling
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_ADD_RESP3',
                [$streamKey],
                [json_encode($entry), $siteId, (string)$maxLen]
            );

            // Parse RESP3 response
            if (is_array($result)) {
                if (isset($result['success']) && $result['success']) {
                    // Extract entry_id from RESP3 verbatim string structure
                    $entryId = $result['entry_id']['verbatim_string']['string']
                        ?? $result['entry_id']
                        ?? null;
                    return $entryId ?: false;
                }
                // Log error from response
                if (isset($result['error'])) {
                    $this->handleError('stream_add', new \Exception($result['error']));
                }
                return false;
            }

            // Handle simple string response (entry ID directly)
            if (is_string($result) && preg_match('/^\d+-\d+$/', $result)) {
                return $result;
            }

            return false;

        } catch (\Exception $e) {
            $this->handleError('stream_add', $e);
            return false;
        }
    }

    /**
     * Ensure consumer group exists for stream
     *
     * Uses GNODE_STREAM_ENSURE_CONSUMER_GROUPS Lua function which is idempotent
     * and creates stream if it doesn't exist (MKSTREAM).
     *
     * @param string $stream Stream key
     * @param string $group Group name
     * @param string $start Start position ('0' or '$')
     * @return bool Success status
     */
    public function streamCreateGroup(string $stream, string $group, string $start = '$'): bool {
        if (!$this->initialized) {
            return false;
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_create_group', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return false;
        }

        try {
            $streamKey = $this->buildStreamKey($stream);
            $fullGroupName = $this->streamConfig['consumer_group_prefix'] . $group;

            // Use GNODE_STREAM_ENSURE_CONSUMER_GROUPS for idempotent group creation
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_ENSURE_CONSUMER_GROUPS',
                [$streamKey],
                [json_encode([$fullGroupName]), $start]
            );

            // Check result
            if (is_array($result)) {
                // Success if group was ensured or already existed
                return !empty($result['ensured']) || !empty($result['already_existed']);
            }

            return (bool)$result;

        } catch (\Exception $e) {
            $this->handleError('stream_create_group', $e);
            return false;
        }
    }

    /**
     * Read from stream with optional blocking
     *
     * Uses GNODE_STREAM_READ Lua function for basic XRANGE operation.
     *
     * @param string $stream Stream key
     * @param string $start Start ID (default: '-' for beginning)
     * @param string $end End ID (default: '+' for end)
     * @param int $count Max entries to read
     * @param int $block Milliseconds to block (not used in basic read)
     * @return array Entries
     */
    public function streamRead(string $stream, string $start = '-', string $end = '+', int $count = 100, int $block = 0): array {
        if (!$this->initialized) {
            return [];
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_read', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return [];
        }

        try {
            $streamKey = $this->buildStreamKey($stream);

            // Use GNODE_STREAM_READ Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_READ',
                [$streamKey],
                [$start, $end, (string)$count]
            );

            if (empty($result)) {
                return [];
            }

            // Parse JSON result from Lua function
            $messages = is_string($result) ? json_decode($result, true) : $result;

            if (!is_array($messages)) {
                return [];
            }

            // Format results: [[id, [field1, value1, field2, value2...]], ...]
            $entries = [];
            foreach ($messages as $msg) {
                if (!is_array($msg) || count($msg) < 2) {
                    continue;
                }

                $id = $msg[0];
                $fields = $msg[1];

                // Convert flat array to associative array
                $data = [];
                if (is_array($fields)) {
                    for ($i = 0; $i < count($fields) - 1; $i += 2) {
                        $data[$fields[$i]] = $fields[$i + 1];
                    }
                }

                // Parse nested 'data' JSON if present
                if (isset($data['data'])) {
                    $nested = json_decode($data['data'], true);
                    if (is_array($nested)) {
                        $data = array_merge($data, $nested);
                    }
                }

                $entries[$id] = $data;
            }

            return $entries;

        } catch (\Exception $e) {
            $this->handleError('stream_read', $e);
            return [];
        }
    }

    /**
     * Read from stream using consumer group
     *
     * Uses GNODE_STREAM_GROUP_READ Lua function for consumer group operations.
     *
     * @param string $stream Stream key
     * @param string $group Consumer group name
     * @param string $consumer Consumer name
     * @param int $count Max entries to read
     * @param int $block Milliseconds to block
     * @param string $id Start ID ('>' for new messages, '0' for pending)
     * @return array Entries
     * @api
     */
    public function streamReadGroup(
        string $stream,
        string $group,
        string $consumer,
        int $count = 100,
        int $block = 0,
        string $id = '>'
    ): array {
        if (!$this->initialized) {
            return [];
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_read_group', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return [];
        }

        try {
            $streamKey = $this->buildStreamKey($stream);
            $fullGroupName = $this->streamConfig['consumer_group_prefix'] . $group;
            $siteId = $this->nodeMetadata['site_id'] ?? 'default';

            // Use GNODE_STREAM_GROUP_READ Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_GROUP_READ',
                [$streamKey],
                [
                    $fullGroupName,
                    $consumer,
                    (string)$count,
                    (string)$block,
                    $id,
                    $siteId
                ]
            );

            if (empty($result)) {
                return [];
            }

            // Parse result - format varies based on Lua function response
            $messages = is_string($result) ? json_decode($result, true) : $result;

            if (!is_array($messages)) {
                return [];
            }

            // Format entries consistently
            $entries = [];
            foreach ($messages as $key => $value) {
                if (is_array($value)) {
                    // Handle nested stream -> message structure
                    foreach ($value as $msgId => $fields) {
                        $data = is_array($fields) ? $fields : [];
                        if (isset($data['data'])) {
                            $nested = json_decode($data['data'], true);
                            if (is_array($nested)) {
                                $data = array_merge($data, $nested);
                            }
                        }
                        $entries[$msgId] = $data;
                    }
                } else {
                    $entries[$key] = $value;
                }
            }

            return $entries;

        } catch (\Exception $e) {
            $this->handleError('stream_read_group', $e);
            return [];
        }
    }

    /**
     * Start consuming from a stream in non-blocking mode
     *
     * This is a simple poll-based consumer. For production use,
     * consider using the gNode daemon's consumer capabilities.
     *
     * @param string $stream Stream key
     * @param string $group Consumer group
     * @param string $consumer Consumer name
     * @param callable $callback Processing callback
     * @param array $options Consumption options
     */
    public function streamConsume(
        string $stream,
        string $group,
        string $consumer,
        callable $callback,
        array $options = []
    ): void {
        if (!$this->initialized || !$this->useGNode || $this->gNodeClient === null) {
            return;
        }

        $options = array_merge([
            'batch_size' => $this->streamConfig['consumer_batch_size'],
            'noack' => false,
            'iterations' => 1  // Single iteration for non-blocking
        ], $options);

        // Ensure consumer group exists
        if (!$this->streamCreateGroup($stream, $group)) {
            $this->handleError('stream_consume', new \Exception("Failed to create consumer group"));
            return;
        }

        try {
            // Read and process messages
            $entries = $this->streamReadGroup(
                $stream,
                $group,
                $consumer,
                $options['batch_size'],
                0,  // Non-blocking
                '>'
            );

            if (empty($entries)) {
                return;
            }

            foreach ($entries as $id => $data) {
                try {
                    $result = $callback($data, $id, []);

                    // Auto-acknowledge if requested
                    if ($options['noack'] || $result === true) {
                        $this->streamAck($stream, $group, $id);
                    }
                } catch (\Exception $e) {
                    $this->handleError('stream_process', $e);
                    // Entry remains pending for retry
                }
            }

        } catch (\Exception $e) {
            $this->handleError('stream_consume', $e);
        }
    }

    /**
     * Acknowledge processed stream entry
     *
     * Uses GNODE_STREAM_ACK Lua function with distributed locking.
     *
     * @param string $stream Stream key
     * @param string $group Consumer group
     * @param string $id Message ID (or array of IDs)
     * @return bool Success status
     * @api
     */
    public function streamAck(string $stream, string $group, string|array $id): bool {
        if (!$this->initialized) {
            return false;
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_ack', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return false;
        }

        try {
            $streamKey = $this->buildStreamKey($stream);
            $fullGroupName = $this->streamConfig['consumer_group_prefix'] . $group;

            // Normalize IDs to array
            $ids = is_array($id) ? $id : [$id];
            $threadId = 'gcore-' . getmypid();

            // Use GNODE_STREAM_ACK Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_ACK',
                [$streamKey],
                [$fullGroupName, json_encode($ids), $threadId]
            );

            // Parse RESP3 response
            if (is_string($result) && strpos($result, 'RESP3_FORMAT') !== false) {
                preg_match('/RESP3_FORMAT = (\d+)/', $result, $matches);
                return isset($matches[1]) && (int)$matches[1] > 0;
            }

            return (bool)$result;

        } catch (\Exception $e) {
            $this->handleError('stream_ack', $e);
            return false;
        }
    }

    /**
     * Get stream information
     *
     * Uses GNODE_STREAM_INFO Lua function.
     *
     * @param string $stream Stream key
     * @return array Stream details
     */
    public function streamInfo(string $stream): array {
        if (!$this->initialized) {
            return [];
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_info', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return [];
        }

        try {
            $streamKey = $this->buildStreamKey($stream);

            // Use GNODE_STREAM_INFO Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_INFO',
                [$streamKey],
                []
            );

            if (is_string($result)) {
                $decoded = json_decode($result, true);
                return is_array($decoded) ? $decoded : [];
            }

            return is_array($result) ? $result : [];

        } catch (\Exception $e) {
            $this->handleError('stream_info', $e);
            return [];
        }
    }

    /**
     * Get pending entries for consumer group
     *
     * Uses GNODE_STREAM_PENDING Lua function.
     *
     * @param string $stream Stream key
     * @param string $group Consumer group
     * @return array Pending entries info
     */
    public function streamPending(string $stream, string $group): array {
        if (!$this->initialized) {
            return [];
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_pending', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return [];
        }

        try {
            $streamKey = $this->buildStreamKey($stream);
            $fullGroupName = $this->streamConfig['consumer_group_prefix'] . $group;

            // Use GNODE_STREAM_PENDING Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_PENDING',
                [$streamKey],
                [$fullGroupName]
            );

            if (is_string($result)) {
                $decoded = json_decode($result, true);
                return is_array($decoded) ? $decoded : [];
            }

            return is_array($result) ? $result : [];

        } catch (\Exception $e) {
            $this->handleError('stream_pending', $e);
            return [];
        }
    }

    /**
     * Claim pending entries for consumer
     *
     * Uses GNODE_STREAM_CLAIM Lua function.
     *
     * @param string $stream Stream key
     * @param string $group Consumer group
     * @param string $consumer Consumer name
     * @param int $min_idle_time Minimum idle time in milliseconds
     * @param array $ids IDs to claim
     * @return array Claimed entries
     * @api
     */
    public function streamClaim(string $stream, string $group, string $consumer, int $min_idle_time, array $ids): array {
        if (!$this->initialized || empty($ids)) {
            return [];
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_claim', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return [];
        }

        try {
            $streamKey = $this->buildStreamKey($stream);
            $fullGroupName = $this->streamConfig['consumer_group_prefix'] . $group;

            // Use GNODE_STREAM_CLAIM Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_CLAIM',
                [$streamKey],
                [
                    $fullGroupName,
                    $consumer,
                    (string)$min_idle_time,
                    json_encode($ids)
                ]
            );

            if (is_string($result)) {
                $decoded = json_decode($result, true);
                return is_array($decoded) ? $decoded : [];
            }

            return is_array($result) ? $result : [];

        } catch (\Exception $e) {
            $this->handleError('stream_claim', $e);
            return [];
        }
    }

    /**
     * Delete stream entries
     *
     * Uses GNODE_STREAM_DEL Lua function.
     *
     * @param string $stream Stream key
     * @param array $ids IDs to delete
     * @return int Number of entries deleted
     */
    public function streamDelete(string $stream, array $ids): int {
        if (!$this->initialized || empty($ids)) {
            return 0;
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_delete', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return 0;
        }

        try {
            $streamKey = $this->buildStreamKey($stream);

            // Use GNODE_STREAM_DEL Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_DEL',
                [$streamKey],
                [json_encode($ids)]
            );

            return (int)$result;

        } catch (\Exception $e) {
            $this->handleError('stream_delete', $e);
            return 0;
        }
    }

    /**
     * Trim stream to specific length
     *
     * Uses GNODE_STREAM_TRIM Lua function.
     *
     * @param string $stream Stream key
     * @param int $maxlen Maximum length
     * @return int Number of entries removed
     * @api
     */
    public function streamTrim(string $stream, int $maxlen): int {
        if (!$this->initialized) {
            return 0;
        }

        if (!$this->useGNode || $this->gNodeClient === null) {
            $this->handleError('stream_trim', new \Exception(
                'Stream operations require gNode integration (useGNode=true)'
            ));
            return 0;
        }

        try {
            $streamKey = $this->buildStreamKey($stream);

            // Use GNODE_STREAM_TRIM Lua function
            $result = $this->gNodeClient->fcall(
                'GNODE_STREAM_TRIM',
                [$streamKey],
                [(string)$maxlen]
            );

            return (int)$result;

        } catch (\Exception $e) {
            $this->handleError('stream_trim', $e);
            return 0;
        }
    }
}
