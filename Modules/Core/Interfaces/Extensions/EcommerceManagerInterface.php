<?php
declare(strict_types=1);
/**
 * EcommerceManager Interface
 *
 * Contract for ecommerce integration within gCore.
 * The manager wraps platform-specific adapters and adds gCore-level
 * capabilities: ValKey-backed cart state, product caching, checkout
 * security, and ecommerce analytics.
 *
 * Default tier (stub): auto-detects ecommerce platform, delegates
 * cart/product operations through adapter for basic integration.
 *
 * Extension tier: adds ValKey acceleration, intelligent cache
 * invalidation, checkout rate limiting, and conversion analytics.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.0.0
 * @since       3.1.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\EcommerceAdapterInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

/**
 * Interface EcommerceManagerInterface
 *
 * Defines the contract for ecommerce operations within gCore.
 * Implementations delegate platform-specific work to an
 * EcommerceAdapterInterface while adding gCore-level features.
 */
interface EcommerceManagerInterface extends ModuleInterface
{
    // =========================================================================
    // ADAPTER MANAGEMENT
    // =========================================================================

    /**
     * Get the active ecommerce adapter
     *
     * @return EcommerceAdapterInterface|null Active adapter, or null if none detected
     */
    public function getAdapter(): ?EcommerceAdapterInterface;

    /**
     * Check if an ecommerce adapter is available
     *
     * @return bool True if a platform adapter was detected and loaded
     */
    public function hasAdapter(): bool;

    /**
     * Get information about the detected ecommerce platform
     *
     * @return array Platform info ['name', 'version', 'adapter_version'] or empty if none
     */
    public function getPlatformInfo(): array;

    // =========================================================================
    // CART OPERATIONS
    // =========================================================================

    /**
     * Get current cart contents
     *
     * Stub: delegates to adapter. Full: ValKey-backed with sub-ms reads.
     *
     * @return array Normalized cart items
     */
    public function getCart(): array;

    /**
     * Add a product to the cart
     *
     * @param string $productId Product identifier
     * @param int $quantity Quantity to add
     * @param array $options Variation/option data
     * @return bool True on success
     */
    public function addToCart(string $productId, int $quantity = 1, array $options = []): bool;

    /**
     * Remove an item from the cart
     *
     * @param string $itemKey Cart item key
     * @return bool True on success
     */
    public function removeFromCart(string $itemKey): bool;

    /**
     * Update cart item quantity
     *
     * @param string $itemKey Cart item key
     * @param int $quantity New quantity
     * @return bool True on success
     */
    public function updateCartItem(string $itemKey, int $quantity): bool;

    /**
     * Empty the cart
     *
     * @return bool True on success
     */
    public function clearCart(): bool;

    /**
     * Get cart total amount
     *
     * @return float Cart total
     */
    public function getCartTotal(): float;

    /**
     * Get total number of items in cart
     *
     * @return int Item count
     */
    public function getCartItemCount(): int;

    // =========================================================================
    // PRODUCT OPERATIONS
    // =========================================================================

    /**
     * Get a single product by ID
     *
     * Stub: delegates to adapter. Full: ValKey-cached with TTL.
     *
     * @param string $productId Product identifier
     * @return array|null Normalized product data, or null if not found
     */
    public function getProduct(string $productId): ?array;

    /**
     * Get multiple products with optional filtering
     *
     * @param array $filters Normalized filters (category, search, on_sale, in_stock, type, ids)
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     * @return array Array of normalized products
     */
    public function getProducts(array $filters = [], int $limit = 10, int $offset = 0): array;

    /**
     * Invalidate cached product data
     *
     * Stub: no-op. Full: removes product from ValKey cache.
     *
     * @param string $productId Product identifier
     */
    public function invalidateProductCache(string $productId): void;

    /**
     * Pre-warm product cache for given product IDs
     *
     * Stub: no-op (returns 0). Full: loads products into ValKey cache.
     *
     * @param array $productIds Product IDs to cache (empty = popular products)
     * @return int Number of products cached
     */
    public function warmProductCache(array $productIds = []): int;

    // =========================================================================
    // INVENTORY
    // =========================================================================

    /**
     * Get stock quantity for a product
     *
     * @param string $productId Product identifier
     * @return int Stock quantity (-1 if not managed)
     */
    public function getStock(string $productId): int;

    /**
     * Check if a product is in stock
     *
     * @param string $productId Product identifier
     * @return bool True if in stock
     */
    public function isInStock(string $productId): bool;

    // =========================================================================
    // CHECKOUT SECURITY
    // =========================================================================

    /**
     * Validate checkout rate for an identifier (IP, session, etc.)
     *
     * Stub: always returns true. Full: SecurityManager-backed rate limiting.
     *
     * @param string $identifier Client identifier (IP address, session ID)
     * @return bool True if checkout is allowed (not rate-limited)
     */
    public function validateCheckoutRate(string $identifier): bool;

    /**
     * Flag a suspicious checkout attempt
     *
     * Stub: no-op. Full: logs to SecurityManager, increments fraud counter.
     *
     * @param string $identifier Client identifier
     * @param string $reason Reason for flagging
     */
    public function flagSuspiciousCheckout(string $identifier, string $reason): void;

    // =========================================================================
    // ECOMMERCE ANALYTICS
    // =========================================================================

    /**
     * Track a cart event (add, remove, update, clear)
     *
     * Stub: no-op. Full: AnalyticsManager integration.
     *
     * @param string $event Event name (add_to_cart, remove_from_cart, update_cart, clear_cart)
     * @param array $data Event data (product_id, quantity, etc.)
     */
    public function trackCartEvent(string $event, array $data = []): void;

    /**
     * Track a checkout step
     *
     * Stub: no-op. Full: funnel tracking via AnalyticsManager.
     *
     * @param string $step Step identifier (started, billing, shipping, payment, completed)
     * @param array $data Step data
     */
    public function trackCheckoutStep(string $step, array $data = []): void;

    /**
     * Get conversion funnel data for a date range
     *
     * Stub: returns empty array. Full: full funnel analysis.
     *
     * @param string $startDate Start date (YYYYMMDD)
     * @param string $endDate End date (YYYYMMDD)
     * @return array Funnel steps with counts and drop-off rates
     */
    public function getConversionFunnel(string $startDate, string $endDate): array;

    /**
     * Get cart abandonment rate for a date range
     *
     * Stub: returns -1.0. Full: calculated from tracked cart/checkout events.
     *
     * @param string $startDate Start date (YYYYMMDD)
     * @param string $endDate End date (YYYYMMDD)
     * @return float Abandonment rate (0.0-1.0), or -1.0 if unavailable
     */
    public function getCartAbandonmentRate(string $startDate, string $endDate): float;

    // =========================================================================
    // PAGE CONTEXT
    // =========================================================================

    /**
     * Check if current request is the cart page
     *
     * @return bool
     */
    public function isCartPage(): bool;

    /**
     * Check if current request is the checkout page
     *
     * @return bool
     */
    public function isCheckoutPage(): bool;

    /**
     * Check if current request is a product page
     *
     * @return bool
     */
    public function isProductPage(): bool;
}
