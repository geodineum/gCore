<?php
declare(strict_types=1);
/**
 * StateManager Interface
 *
 * Contract for advanced state management with transactions, observers,
 * history tracking, and middleware support.
 *
 * Extension implementations provide full ValKey-backed distributed state.
 * Default stubs provide graceful in-memory-only degradation.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

/**
 * Interface StateManagerInterface
 *
 * Defines the contract for state management operations.
 * Implementations may use ValKey/Redis for persistence (extension) or
 * provide in-memory stubs for graceful degradation (default).
 */
interface StateManagerInterface extends ModuleInterface, \ArrayAccess
{
    // =========================================================================
    // CORE STATE OPERATIONS
    // =========================================================================

    /**
     * Set state value with validation, middleware, and history tracking
     *
     * @param string $key State key
     * @param mixed $value State value
     * @param bool $skipValidation Skip validation (use with caution)
     * @return bool Success status (false if validation fails)
     */
    public function setState(string $key, $value, bool $skipValidation = false): bool;

    /**
     * Get state value
     *
     * @param string $key State key
     * @param mixed $default Default value if key not found
     * @return mixed State value or default
     */
    public function getState(string $key, $default = null);

    /**
     * Remove state value
     *
     * @param string $key State key
     */
    public function removeState(string $key): void;

    /**
     * Check if state key exists
     *
     * @param string $key State key
     * @return bool True if key exists
     */
    public function hasState(string $key): bool;

    // =========================================================================
    // ATOMIC OPERATIONS (Distributed)
    // =========================================================================

    /**
     * Atomically increment a counter
     *
     * @param string $key Counter key
     * @param int $delta Amount to increment (default 1)
     * @param int|null $ttl Optional TTL in seconds
     * @return int New value after increment
     */
    public function increment(string $key, int $delta = 1, ?int $ttl = null): int;

    /**
     * Atomically decrement a counter
     *
     * @param string $key Counter key
     * @param int $delta Amount to decrement (default 1)
     * @param int|null $ttl Optional TTL in seconds
     * @return int New value after decrement
     */
    public function decrement(string $key, int $delta = 1, ?int $ttl = null): int;

    /**
     * Compare-and-swap operation (atomic conditional update)
     *
     * Only sets the new value if current value matches expected.
     * Useful for optimistic locking patterns.
     *
     * @param string $key State key
     * @param mixed $expected Expected current value
     * @param mixed $new New value to set if current matches expected
     * @return bool True if swap succeeded, false if value didn't match
     */
    public function compareAndSwap(string $key, $expected, $new): bool;

    // =========================================================================
    // OBSERVER PATTERN
    // =========================================================================

    /**
     * Subscribe to state changes for a specific key
     *
     * @param string $key State key to observe
     * @param callable $callback Callback function(string $key, mixed $value)
     * @param string|null $observerId Optional unique observer ID
     * @return string Observer ID for unsubscription
     */
    public function subscribe(string $key, callable $callback, ?string $observerId = null): string;

    /**
     * Unsubscribe from state changes
     *
     * @param string $key State key
     * @param string $observerId Observer ID from subscribe()
     * @return bool Success status
     */
    public function unsubscribe(string $key, string $observerId): bool;

    /**
     * List all subscribers for a key
     *
     * @param string $key State key
     * @return array List of observer IDs
     */
    public function listSubscribers(string $key): array;

    /**
     * Publish state change via gNode pub/sub for distributed observers
     *
     * This notifies observers on other nodes about the state change.
     * Local observers are notified automatically via setState().
     *
     * @param string $key State key
     * @param mixed $value New value
     */
    public function publish(string $key, $value): void;

    // =========================================================================
    // TRANSACTION SUPPORT
    // =========================================================================

    /**
     * Begin a new transaction
     *
     * All state changes within the transaction can be committed or rolled back.
     *
     * @param int $timeout Transaction timeout in seconds
     * @return string Transaction ID
     * @throws \RuntimeException If already in a transaction
     */
    public function beginTransaction(int $timeout = 300): string;

    /**
     * Commit the current transaction
     *
     * @return bool Success status
     * @throws \RuntimeException If not in a transaction
     */
    public function commitTransaction(): bool;

    /**
     * Rollback the current transaction
     *
     * Restores state to pre-transaction snapshot.
     *
     * @return bool Success status
     * @throws \RuntimeException If not in a transaction
     */
    public function rollbackTransaction(): bool;

    /**
     * Get transaction status
     *
     * @param string|null $txId Transaction ID (current if null)
     * @return array|null Transaction status or null if not found
     */
    public function getTransactionStatus(?string $txId = null): ?array;

    // =========================================================================
    // HISTORY TRACKING
    // =========================================================================

    /**
     * Get state change history
     *
     * @param string|null $key Filter by state key (null for all)
     * @param int $limit Maximum entries to return
     * @return array History entries
     */
    public function getHistory(?string $key = null, int $limit = 50): array;

    /**
     * Get a specific history entry by index
     *
     * @param int $index History entry index (0 = most recent)
     * @return array|null History entry or null if not found
     */
    public function getHistoryEntry(int $index): ?array;

    /**
     * Clear state history
     *
     * @param string|null $key Clear only for specific key (null for all)
     * @return bool Success status
     */
    public function clearHistory(?string $key = null): bool;

    // =========================================================================
    // VALIDATORS
    // =========================================================================

    /**
     * Register a validator for a state key
     *
     * @param string $key State key to validate
     * @param callable $validator Function(mixed $value, string $key): bool|string
     * @param string|null $validatorId Optional validator ID
     * @return string Validator ID
     */
    public function registerValidator(string $key, callable $validator, ?string $validatorId = null): string;

    /**
     * Remove a validator
     *
     * @param string $key State key
     * @param string $validatorId Validator ID
     * @return bool Success status
     */
    public function removeValidator(string $key, string $validatorId): bool;

    // =========================================================================
    // MIDDLEWARE
    // =========================================================================

    /**
     * Add middleware to the state change pipeline
     *
     * @param callable $middleware Function(string $key, mixed $value, callable $next): mixed
     * @param int $priority Priority (lower = earlier execution)
     * @return string Middleware ID
     */
    public function addMiddleware(callable $middleware, int $priority = 100): string;

    /**
     * Remove middleware
     *
     * @param string $middlewareId Middleware ID
     * @return bool Success status
     */
    public function removeMiddleware(string $middlewareId): bool;

    // =========================================================================
    // PERSISTENCE
    // =========================================================================

    /**
     * Restore state from persistent storage
     */
    public function restoreState(): void;

    /**
     * Persist state to storage
     */
    public function persistState(): void;
}
