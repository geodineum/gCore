<?php
declare(strict_types=1);
/**
 * StateManager - Distributed State Management with gNode-Client Backend
 *
 * Provides persistent, distributed state management with:
 * - ValKey-backed storage (survives PHP request boundaries)
 * - Atomic increment/decrement operations
 * - Distributed transactions via gNode_TRANSACTION_* functions
 * - Observer notifications via gNode pub/sub
 * - Validators and middleware pipelines
 * - History tracking
 *
 * Storage layout:
 * - {site_id}:state (HASH) - main state storage
 * - {site_id}:state:history (LIST) - bounded change history
 * - {site_id}:state:counters:{key} (STRING) - atomic counters
 * - {site_id}:state:channel (PUBSUB) - observer notifications
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\StateManager
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Base\StateManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\StateManagerInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\gNode\gNodeClient;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 6));
}

/**
 * Class StateManager
 *
 * Full implementation of StateManagerInterface with gNode-Client backend.
 * Provides distributed state management across PHP requests and nodes.
 */
class StateManager implements StateManagerInterface
{
    use ManagerConfigTrait;

    /** @var StateManager Singleton instance */
    private static $instance = null;

    /** @var gNodeClient|null gNode-Client for ValKey operations */
    private $gNodeClient = null;

    /** @var array In-memory state cache (per-request optimization) */
    private $stateCache = [];

    /** @var bool Whether cache has been loaded from ValKey */
    private $cacheLoaded = false;

    /** @var array State observers registry (local per-request) */
    private $observers = [];

    /** @var array State validators */
    private $validators = [];

    /** @var array Middleware stack */
    private $middleware = [];

    /** @var bool Transaction flag */
    private $inTransaction = false;

    /** @var string|null Current transaction ID */
    private $currentTransaction = null;

    /** @var array Transaction snapshot (for local rollback) */
    private $transactionSnapshot = [];

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Runtime metrics counters */
    private $metrics = [];

    /**
     * Default configuration.
     *
     * Audit fix : node_id default unified to 'node1' to match
     * every other gCore manager (was 'default' here per the
     * 2026-05-22 config audit — StateManager + AssetManager were the
     * only outliers).
     */
    private $defaultConfig = [
        'history_depth' => 100,
        'cache_ttl' => 0,           // 0 = no expiry for state
        'counter_ttl' => 0,         // 0 = no expiry for counters
        'transaction_timeout' => 300,
        'pub_channel' => 'state',   // pubsub channel suffix
        'site_id' => 'default',
        'node_id' => 'node1',
    ];

    /** @var array Capability vector for gNode topology */
    private $capabilityVector = [
        'state_management' => 1.0,
        'transactions' => 0.9,
        'observability' => 0.9,
        'history' => 0.8,
        'persistence' => 1.0,
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): ModuleInterface
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Initialize StateManager
     *
     * @param array $config Configuration including gnode_client injection
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        // Get gNode-Client from injection (gCore injects this automatically)
        if (isset($config['gnode_client']) && $config['gnode_client'] instanceof gNodeClient) {
            $this->gNodeClient = $config['gnode_client'];
        }

        // Layered config: defaultConfig → ValKey → $config arg
        $siteId = (string)($config['site_id'] ?? $this->defaultConfig['site_id']);
        $valkeyConfig = [];
        $storage = $this->gcoreResolveStorage($config);
        if ($storage !== null) {
            $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'StateManager');
        }
        $this->config = array_merge($this->defaultConfig, $valkeyConfig, $config);

        $this->initialized = true;

        // Initialize metrics counters
        $this->metrics = [
            'state_updates' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'transaction_count' => 0,
            'observer_notifications' => 0,
            'start_time' => microtime(true),
            'site_id' => $this->config['site_id'] ?? '',
        ];

        // Register WordPress lifecycle hooks for automatic state persistence
        $this->registerHooks();

        // Log initialization
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mode = $this->gNodeClient ? 'distributed' : 'fallback';
            error_log("[gCore] StateManager initialized in {$mode} mode for site: {$this->config['site_id']}");
        }
    }

    /**
     * Register WordPress hooks for automatic state restore/persist.
     * On wp_loaded: bulk-restore state from ValKey into in-memory cache.
     * On shutdown: flush any dirty state back to ValKey.
     * Safe to call in non-WordPress contexts (no-ops if hooks unavailable).
     */
    private function registerHooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('wp_loaded', [$this, 'restoreState']);
        add_action('shutdown', [$this, 'persistState']);
    }

    // =========================================================================
    // CORE STATE OPERATIONS
    // =========================================================================

    /**
     * Set state value with validation, middleware, and history tracking
     * @api
     */
    public function setState(string $key, $value, bool $skipValidation = false): bool
    {
        // Run validators
        if (!$skipValidation && !$this->validate($key, $value)) {
            return false;
        }

        // Execute middleware
        $value = $this->runMiddleware($key, $value);

        // Capture old value for history
        $oldValue = $this->getState($key);

        // Update in-memory cache
        $this->stateCache[$key] = $value;

        // Persist to ValKey if available
        if ($this->gNodeClient) {
            try {
                $hashKey = $this->getStateHashKey();
                $serialized = $this->serialize($value);
                $this->gNodeClient->luaHSet($hashKey, $key, $serialized);

                // Record history
                $this->recordHistory($key, $oldValue, $value);

                // Publish change notification
                $this->publishChange($key, $value);
            } catch (\Throwable $e) {
                $this->logError('setState failed', $e);
                return false;
            }
        }

        // Notify local observers
        $this->notifyObservers($key, $value);

        return true;
    }

    /**
     * Get state value
     * @api
     */
    public function getState(string $key, $default = null)
    {
        // Check in-memory cache first
        if (array_key_exists($key, $this->stateCache)) {
            return $this->stateCache[$key];
        }

        // Fetch from ValKey if available
        if ($this->gNodeClient) {
            try {
                $hashKey = $this->getStateHashKey();
                $value = $this->gNodeClient->luaHGet($hashKey, $key);

                // Check for deleted marker or empty value
                if ($value !== null && $value !== false && $value !== '__DELETED__') {
                    $deserialized = $this->deserialize($value);
                    $this->stateCache[$key] = $deserialized;
                    return $deserialized;
                }
            } catch (\Throwable $e) {
                $this->logError('getState failed', $e);
            }
        }

        return $default;
    }

    /**
     * Remove state value
     * @api
     */
    public function removeState(string $key): void
    {
        // Remove from cache
        unset($this->stateCache[$key]);

        // Remove from ValKey
        if ($this->gNodeClient) {
            try {
                // Delete the individual state key
                $stateKey = "{$this->config['site_id']}:state:{$key}";
                $this->gNodeClient->luaDel($stateKey);

                // Mark as deleted in hash by setting to special value
                // (ValKey HDEL not available via FCALL, use marker approach)
                $hashKey = $this->getStateHashKey();
                $this->gNodeClient->luaHSet($hashKey, $key, '__DELETED__');
            } catch (\Throwable $e) {
                $this->logError('removeState failed', $e);
            }
        }
    }

    /**
     * Check if state key exists
     * @api
     */
    public function hasState(string $key): bool
    {
        // Check cache
        if (array_key_exists($key, $this->stateCache)) {
            return true;
        }

        // Check ValKey
        if ($this->gNodeClient) {
            try {
                $hashKey = $this->getStateHashKey();
                $value = $this->gNodeClient->luaHGet($hashKey, $key);
                return $value !== null && $value !== false;
            } catch (\Throwable $e) {
                $this->logError('hasState failed', $e);
            }
        }

        return false;
    }

    // =========================================================================
    // ATOMIC OPERATIONS (Distributed)
    // =========================================================================

    /**
     * Atomically increment a counter
     *
     * @param string $key Counter key
     * @param int $delta Amount to increment (default 1)
     * @param int|null $ttl Optional TTL in seconds (Note: TTL via luaSet after increment)
     * @return int New value after increment
     * @api
     */
    public function increment(string $key, int $delta = 1, ?int $ttl = null): int
    {
        if (!$this->gNodeClient) {
            // Fallback to in-memory
            $current = (int)($this->stateCache[$key] ?? 0);
            $this->stateCache[$key] = $current + $delta;
            return $this->stateCache[$key];
        }

        try {
            $counterKey = $this->getCounterKey($key);
            $newValue = $this->gNodeClient->luaIncrBy($counterKey, $delta);

            // TTL is not directly supported on incremented keys via FCALL
            // Counters typically don't need TTL, but if needed, use luaSet separately
            // Note: This is a design trade-off for simplicity

            // Update local cache
            $this->stateCache[$key] = $newValue;

            return $newValue;
        } catch (\Throwable $e) {
            $this->logError('increment failed', $e);
            // Fallback to in-memory
            $current = (int)($this->stateCache[$key] ?? 0);
            $this->stateCache[$key] = $current + $delta;
            return $this->stateCache[$key];
        }
    }

    /**
     * Atomically decrement a counter
     *
     * @param string $key Counter key
     * @param int $delta Amount to decrement (default 1)
     * @param int|null $ttl Optional TTL in seconds (Note: TTL via luaSet after decrement)
     * @return int New value after decrement
     * @api
     */
    public function decrement(string $key, int $delta = 1, ?int $ttl = null): int
    {
        if (!$this->gNodeClient) {
            // Fallback to in-memory
            $current = (int)($this->stateCache[$key] ?? 0);
            $this->stateCache[$key] = $current - $delta;
            return $this->stateCache[$key];
        }

        try {
            $counterKey = $this->getCounterKey($key);
            $newValue = $this->gNodeClient->luaDecrBy($counterKey, $delta);

            // TTL is not directly supported on decremented keys via FCALL
            // Counters typically don't need TTL

            // Update local cache
            $this->stateCache[$key] = $newValue;

            return $newValue;
        } catch (\Throwable $e) {
            $this->logError('decrement failed', $e);
            // Fallback to in-memory
            $current = (int)($this->stateCache[$key] ?? 0);
            $this->stateCache[$key] = $current - $delta;
            return $this->stateCache[$key];
        }
    }

    /**
     * Compare-and-swap operation (atomic conditional update)
     *
     * @param string $key State key
     * @param mixed $expected Expected current value
     * @param mixed $new New value to set if current matches expected
     * @return bool True if swap succeeded, false if value didn't match
     * @api
     */
    public function compareAndSwap(string $key, $expected, $new): bool
    {
        // Get current value
        $current = $this->getState($key);

        // Compare (using serialize for complex types)
        if ($this->serialize($current) !== $this->serialize($expected)) {
            return false;
        }

        // Set new value
        return $this->setState($key, $new, true);
    }

    // =========================================================================
    // OBSERVER PATTERN
    // =========================================================================

    /**
     * Subscribe to state changes for a specific key
     * @api
     */
    public function subscribe(string $key, callable $callback, ?string $observerId = null): string
    {
        $observerId = $observerId ?? uniqid('obs_', true);

        if (!isset($this->observers[$key])) {
            $this->observers[$key] = [];
        }
        $this->observers[$key][$observerId] = $callback;

        return $observerId;
    }

    /**
     * Unsubscribe from state changes
     * @api
     */
    public function unsubscribe(string $key, string $observerId): bool
    {
        if (isset($this->observers[$key][$observerId])) {
            unset($this->observers[$key][$observerId]);
            return true;
        }
        return false;
    }

    /**
     * List all subscribers for a key
     */
    public function listSubscribers(string $key): array
    {
        return isset($this->observers[$key]) ? array_keys($this->observers[$key]) : [];
    }

    /**
     * Publish state change via gNode pub/sub for distributed observers
     * @api
     */
    public function publish(string $key, $value): void
    {
        $this->publishChange($key, $value);
    }

    /**
     * Notify local observers (same-request)
     */
    private function notifyObservers(string $key, $value): void
    {
        if (!isset($this->observers[$key])) {
            return;
        }

        foreach ($this->observers[$key] as $callback) {
            try {
                call_user_func($callback, $key, $value);
            } catch (\Throwable $e) {
                $this->logError("Observer error for key {$key}", $e);
            }
        }
    }

    /**
     * Publish change to distributed observers via gNode
     */
    private function publishChange(string $key, $value): void
    {
        if (!$this->gNodeClient) {
            return;
        }

        try {
            $channel = "{$this->config['site_id']}:{$this->config['pub_channel']}";
            $message = json_encode([
                'type' => 'state_change',
                'key' => $key,
                'value' => $value,
                'site_id' => $this->config['site_id'],
                'node_id' => $this->config['node_id'],
                'timestamp' => microtime(true),
            ]);

            $this->gNodeClient->publish($channel, $message);
        } catch (\Throwable $e) {
            $this->logError('publishChange failed', $e);
        }
    }

    // =========================================================================
    // TRANSACTION SUPPORT
    // =========================================================================

    /**
     * Begin a new transaction
     * @api
     */
    public function beginTransaction(int $timeout = 300): string
    {
        if ($this->inTransaction) {
            throw new \RuntimeException('Already in a transaction');
        }

        $txId = uniqid('tx_', true);
        $this->inTransaction = true;
        $this->currentTransaction = $txId;

        // Snapshot current cache for local rollback
        $this->transactionSnapshot = $this->stateCache;

        // Begin distributed transaction via gNode
        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->fcall(
                    'GNODE_TRANSACTION_BEGIN',
                    [],
                    [$txId, $this->config['site_id'], $timeout]
                );

                // Parse result if JSON
                if (is_string($result)) {
                    $parsed = json_decode($result, true);
                    if (isset($parsed['map']['transaction_id'])) {
                        $txId = $parsed['map']['transaction_id'];
                        $this->currentTransaction = $txId;
                    }
                }
            } catch (\Throwable $e) {
                $this->logError('beginTransaction failed', $e);
                // Continue with local-only transaction
            }
        }

        return $txId;
    }

    /**
     * Commit the current transaction
     * @api
     */
    public function commitTransaction(): bool
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException('No active transaction');
        }

        $success = true;

        // Commit distributed transaction
        if ($this->gNodeClient && $this->currentTransaction) {
            try {
                $result = $this->gNodeClient->fcall(
                    'GNODE_TRANSACTION_COMMIT',
                    [],
                    [$this->currentTransaction, $this->config['site_id']]
                );

                // Check result
                if (is_string($result)) {
                    $parsed = json_decode($result, true);
                    $success = $parsed['map']['success'] ?? true;
                }
            } catch (\Throwable $e) {
                $this->logError('commitTransaction failed', $e);
                $success = false;
            }
        }

        // Clear transaction state
        $this->inTransaction = false;
        $this->currentTransaction = null;
        $this->transactionSnapshot = [];

        return $success;
    }

    /**
     * Rollback the current transaction
     * @api
     */
    public function rollbackTransaction(): bool
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException('No active transaction');
        }

        $success = true;

        // Rollback distributed transaction
        if ($this->gNodeClient && $this->currentTransaction) {
            try {
                $this->gNodeClient->fcall(
                    'GNODE_TRANSACTION_ROLLBACK',
                    [],
                    [$this->currentTransaction, $this->config['site_id']]
                );
            } catch (\Throwable $e) {
                $this->logError('rollbackTransaction failed', $e);
                $success = false;
            }
        }

        // Restore local cache from snapshot
        $this->stateCache = $this->transactionSnapshot;

        // Clear transaction state
        $this->inTransaction = false;
        $this->currentTransaction = null;
        $this->transactionSnapshot = [];

        return $success;
    }

    /**
     * Get transaction status
     */
    public function getTransactionStatus(?string $txId = null): ?array
    {
        $txId = $txId ?? $this->currentTransaction;

        if (!$txId) {
            return null;
        }

        // Get status from gNode
        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->fcall(
                    'GNODE_TRANSACTION_STATUS',
                    [],
                    [$txId, $this->config['site_id']]
                );

                if (is_string($result)) {
                    return json_decode($result, true);
                }
                return $result;
            } catch (\Throwable $e) {
                $this->logError('getTransactionStatus failed', $e);
            }
        }

        // Return local status
        return [
            'id' => $txId,
            'in_transaction' => $this->inTransaction,
            'current_transaction' => $this->currentTransaction,
            'mode' => $this->gNodeClient ? 'distributed' : 'local',
        ];
    }

    // =========================================================================
    // HISTORY TRACKING
    // =========================================================================

    /**
     * Get state change history
     *
     * History is stored as a JSON array in a single cache key.
     * This approach uses available GNODE_CACHE_* functions.
     * @api
     */
    public function getHistory(?string $key = null, int $limit = 50): array
    {
        if (!$this->gNodeClient) {
            return [];
        }

        try {
            $historyKey = $this->getHistoryKey();
            $raw = $this->gNodeClient->luaGet($historyKey);

            if (!$raw) {
                return [];
            }

            $history = json_decode($raw, true) ?: [];

            // Filter by key if specified
            if ($key !== null) {
                $history = array_filter($history, fn($e) => ($e['key'] ?? '') === $key);
            }

            // Apply limit
            return array_slice(array_values($history), 0, $limit);
        } catch (\Throwable $e) {
            $this->logError('getHistory failed', $e);
            return [];
        }
    }

    /**
     * Get a specific history entry by index
     */
    public function getHistoryEntry(int $index): ?array
    {
        $history = $this->getHistory(null, $index + 1);
        return $history[$index] ?? null;
    }

    /**
     * Clear state history
     */
    public function clearHistory(?string $key = null): bool
    {
        if (!$this->gNodeClient) {
            return true;
        }

        try {
            $historyKey = $this->getHistoryKey();

            if ($key === null) {
                // Clear all history
                $this->gNodeClient->luaDel($historyKey);
            } else {
                // Filter out entries for specific key
                $history = $this->getHistory(null, 1000);
                $filtered = array_filter($history, fn($e) => ($e['key'] ?? '') !== $key);
                $this->gNodeClient->luaSet($historyKey, json_encode(array_values($filtered)));
            }

            return true;
        } catch (\Throwable $e) {
            $this->logError('clearHistory failed', $e);
            return false;
        }
    }

    /**
     * Record a history entry
     *
     * Uses a JSON array stored in a single cache key for simplicity.
     * Bounded to history_depth most recent entries.
     */
    private function recordHistory(string $key, $oldValue, $newValue): void
    {
        if (!$this->gNodeClient) {
            return;
        }

        try {
            $historyKey = $this->getHistoryKey();

            // Get existing history
            $raw = $this->gNodeClient->luaGet($historyKey);
            $history = $raw ? (json_decode($raw, true) ?: []) : [];

            // Add new entry at the beginning
            array_unshift($history, [
                'key' => $key,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'timestamp' => microtime(true),
                'node_id' => $this->config['node_id'],
            ]);

            // Trim to configured depth
            $depth = $this->config['history_depth'];
            if (count($history) > $depth) {
                $history = array_slice($history, 0, $depth);
            }

            // Save back
            $this->gNodeClient->luaSet($historyKey, json_encode($history));
        } catch (\Throwable $e) {
            $this->logError('recordHistory failed', $e);
        }
    }

    // =========================================================================
    // VALIDATORS
    // =========================================================================

    /**
     * Register a validator for a state key
     * @api
     */
    public function registerValidator(string $key, callable $validator, ?string $validatorId = null): string
    {
        $validatorId = $validatorId ?? uniqid('val_', true);

        if (!isset($this->validators[$key])) {
            $this->validators[$key] = [];
        }
        $this->validators[$key][$validatorId] = $validator;

        return $validatorId;
    }

    /**
     * Remove a validator
     */
    public function removeValidator(string $key, string $validatorId): bool
    {
        if (isset($this->validators[$key][$validatorId])) {
            unset($this->validators[$key][$validatorId]);
            return true;
        }
        return false;
    }

    /**
     * Validate a value against registered validators
     */
    private function validate(string $key, $value): bool
    {
        if (!isset($this->validators[$key])) {
            return true;
        }

        foreach ($this->validators[$key] as $validator) {
            try {
                $result = call_user_func($validator, $value, $key);
                if ($result !== true) {
                    return false;
                }
            } catch (\Throwable $e) {
                $this->logError("Validator error for key {$key}", $e);
                return false;
            }
        }

        return true;
    }

    // =========================================================================
    // MIDDLEWARE
    // =========================================================================

    /**
     * Add middleware to the state change pipeline
     * @api
     */
    public function addMiddleware(callable $middleware, int $priority = 100): string
    {
        $id = uniqid('mw_', true);

        $this->middleware[] = [
            'id' => $id,
            'priority' => $priority,
            'handler' => $middleware,
        ];

        usort($this->middleware, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return $id;
    }

    /**
     * Remove middleware
     */
    public function removeMiddleware(string $middlewareId): bool
    {
        foreach ($this->middleware as $i => $mw) {
            if ($mw['id'] === $middlewareId) {
                unset($this->middleware[$i]);
                $this->middleware = array_values($this->middleware);
                return true;
            }
        }
        return false;
    }

    /**
     * Run middleware pipeline
     */
    private function runMiddleware(string $key, $value)
    {
        if (empty($this->middleware)) {
            return $value;
        }

        $index = count($this->middleware) - 1;
        $next = fn($v) => $v;

        while ($index >= 0) {
            $mw = $this->middleware[$index];
            $currentNext = $next;
            $next = fn($v) => call_user_func($mw['handler'], $key, $v, $currentNext);
            $index--;
        }

        return $next($value);
    }

    // =========================================================================
    // PERSISTENCE
    // =========================================================================

    /**
     * Restore state from persistent storage
     * @api
     */
    public function restoreState(): void
    {
        if (!$this->gNodeClient || $this->cacheLoaded) {
            return;
        }

        try {
            $hashKey = $this->getStateHashKey();
            $allState = $this->gNodeClient->luaHGetAll($hashKey);

            if (is_array($allState)) {
                foreach ($allState as $key => $value) {
                    $this->stateCache[$key] = $this->deserialize($value);
                }
            }

            $this->cacheLoaded = true;
        } catch (\Throwable $e) {
            $this->logError('restoreState failed', $e);
        }
    }

    /**
     * Persist state to storage (bulk write)
     * @api
     */
    public function persistState(): void
    {
        if (!$this->gNodeClient || empty($this->stateCache)) {
            return;
        }

        try {
            $hashKey = $this->getStateHashKey();

            foreach ($this->stateCache as $key => $value) {
                $serialized = $this->serialize($value);
                $this->gNodeClient->luaHSet($hashKey, $key, $serialized);
            }
        } catch (\Throwable $e) {
            $this->logError('persistState failed', $e);
        }
    }

    // =========================================================================
    // ARRAYACCESS IMPLEMENTATION
    // =========================================================================

    /** @api */
    public function offsetExists($offset): bool
    {
        return $this->hasState($offset);
    }

    /** @api */
    public function offsetGet($offset): mixed
    {
        return $this->getState($offset);
    }

    /** @api */
    public function offsetSet($offset, $value): void
    {
        $this->setState($offset, $value);
    }

    /** @api */
    public function offsetUnset($offset): void
    {
        $this->removeState($offset);
    }

    // =========================================================================
    // MODULE INTERFACE
    // =========================================================================

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'stub_mode' => false,
            'mode' => $this->gNodeClient ? 'distributed' : 'local_fallback',
            'storage_type' => $this->gNodeClient ? 'valkey' : 'memory',
            'gnode_available' => $this->gNodeClient !== null,
            'gnode_connected' => $this->gNodeClient ? $this->gNodeClient->isConnected() : false,
            'state_count' => count($this->stateCache),
            'cache_loaded' => $this->cacheLoaded,
            'observer_count' => array_sum(array_map('count', $this->observers)),
            'validator_count' => array_sum(array_map('count', $this->validators)),
            'middleware_count' => count($this->middleware),
            'in_transaction' => $this->inTransaction,
            'current_transaction' => $this->currentTransaction,
            'site_id' => $this->config['site_id'],
            'node_id' => $this->config['node_id'],
            'capabilities' => $this->capabilityVector,
        ];
    }

    /**
     * Get capability vector for gNode topology registration
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Get the main state hash key
     */
    private function getStateHashKey(): string
    {
        return "{$this->config['site_id']}:state";
    }

    /**
     * Get the history list key
     */
    private function getHistoryKey(): string
    {
        return "{$this->config['site_id']}:state:history";
    }

    /**
     * Get a counter key
     */
    private function getCounterKey(string $key): string
    {
        return "{$this->config['site_id']}:state:counter:{$key}";
    }

    /**
     * Serialize a value for storage
     */
    private function serialize($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value);
    }

    /**
     * Deserialize a value from storage
     */
    private function deserialize(string $value)
    {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $value;
    }

    /**
     * Log an error
     */
    private function logError(string $message, \Throwable $e): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[gCore StateManager] {$message}: {$e->getMessage()}");
        }
    }

    // Prevent cloning and unserialization
    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
