<?php
declare(strict_types=1);
/**
 * Ecommerce Adapter Interface
 *
 * Platform-agnostic contract for ecommerce backend adapters.
 * Implement this interface to integrate any ecommerce platform
 * (WooCommerce, Shopify, custom storefront, etc.) with gCore's
 * EcommerceManager.
 *
 * The adapter handles all platform-specific operations and data
 * normalization. gCore ships a WooCommerceAdapter as reference
 * implementation; third-party adapters can be registered via config.
 *
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces
 * @version     1.0.0
 * @since       3.1.0
 * @license     Apache-2.0
 */

namespace gCore\Modules\Core\Interfaces;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Interface EcommerceAdapterInterface
 *
 * All ecommerce platform adapters must implement this contract.
 * Methods return normalized data structures regardless of the
 * underlying platform.
 *
 * Normalized product format:
 *   [
 *     'id'                => (string) Platform product ID,
 *     'name'              => (string) Product name,
 *     'slug'              => (string) URL slug,
 *     'type'              => (string) simple|variable|grouped|external,
 *     'sku'               => (string) SKU,
 *     'price'             => (float)  Current effective price,
 *     'regular_price'     => (float)  Regular price,
 *     'sale_price'        => (float)  Sale price (0.0 if not on sale),
 *     'on_sale'           => (bool)   Whether currently on sale,
 *     'stock_quantity'    => (int)    Stock level (-1 if unmanaged),
 *     'in_stock'          => (bool)   Stock availability,
 *     'description'       => (string) Full description,
 *     'short_description' => (string) Summary,
 *     'image'             => (string) Primary image URL,
 *     'categories'        => (array)  Category names,
 *     'url'               => (string) Permalink,
 *   ]
 *
 * Normalized cart item format:
 *   [
 *     'key'        => (string) Cart item key (for remove/update),
 *     'product_id' => (string) Product ID,
 *     'name'       => (string) Product name,
 *     'quantity'   => (int)    Quantity,
 *     'price'      => (float)  Unit price,
 *     'subtotal'   => (float)  Line total,
 *     'image'      => (string) Product image URL,
 *     'options'    => (array)  Variation/option data,
 *   ]
 *
 * Normalized order format:
 *   [
 *     'id'           => (string) Order ID,
 *     'status'       => (string) Order status (pending|processing|completed|cancelled|refunded|failed),
 *     'total'        => (float)  Order total,
 *     'currency'     => (string) Currency code (EUR, USD, etc.),
 *     'items'        => (array)  Normalized cart items,
 *     'customer_id'  => (string) Customer identifier,
 *     'date_created' => (string) ISO 8601 datetime,
 *     'billing'      => (array)  Billing address fields,
 *     'shipping'     => (array)  Shipping address fields,
 *   ]
 */
interface EcommerceAdapterInterface
{
    // =========================================================================
    // PLATFORM DETECTION
    // =========================================================================

    /**
     * Detect whether this adapter's platform is available
     *
     * Called without instantiation during adapter auto-detection.
     * Should check for platform classes, functions, or constants.
     *
     * @return bool True if the platform is active and ready
     */
    public static function detect(): bool;

    /**
     * Get platform information
     *
     * @return array ['name' => string, 'version' => string, 'adapter_version' => string]
     */
    public function getPlatformInfo(): array;

    // =========================================================================
    // CART OPERATIONS
    // =========================================================================

    /**
     * Get current cart contents as normalized items
     *
     * @return array Array of normalized cart items
     */
    public function getCart(): array;

    /**
     * Add a product to the cart
     *
     * @param string $productId Product identifier
     * @param int $quantity Quantity to add
     * @param array $options Variation/option data (platform-specific)
     * @return bool True on success
     */
    public function addToCart(string $productId, int $quantity = 1, array $options = []): bool;

    /**
     * Remove an item from the cart
     *
     * @param string $itemKey Cart item key (from getCart() response)
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
     * Get a single product by ID (normalized format)
     *
     * @param string $productId Product identifier
     * @return array|null Normalized product data, or null if not found
     */
    public function getProduct(string $productId): ?array;

    /**
     * Get multiple products with optional filtering
     *
     * @param array $filters Platform-normalized filters:
     *   'category'  => (string) Category slug
     *   'search'    => (string) Search term
     *   'on_sale'   => (bool)   Only sale items
     *   'in_stock'  => (bool)   Only in-stock items
     *   'type'      => (string) Product type
     *   'ids'       => (array)  Specific product IDs
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     * @return array Array of normalized products
     */
    public function getProducts(array $filters = [], int $limit = 10, int $offset = 0): array;

    /**
     * Get stock quantity for a product
     *
     * @param string $productId Product identifier
     * @return int Stock quantity (-1 if stock not managed)
     */
    public function getProductStock(string $productId): int;

    /**
     * Check if a product is in stock
     *
     * @param string $productId Product identifier
     * @return bool True if in stock or stock not managed
     */
    public function isProductInStock(string $productId): bool;

    // =========================================================================
    // PAGE CONTEXT
    // =========================================================================

    /**
     * Get the cart page URL
     *
     * @return string Cart page URL
     */
    public function getCartUrl(): string;

    /**
     * Get the checkout page URL
     *
     * @return string Checkout page URL
     */
    public function getCheckoutUrl(): string;

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

    // =========================================================================
    // ORDER OPERATIONS
    // =========================================================================

    /**
     * Get a single order by ID (normalized format)
     *
     * @param string $orderId Order identifier
     * @return array|null Normalized order data, or null if not found
     */
    public function getOrder(string $orderId): ?array;

    /**
     * Get recent orders for the current customer
     *
     * @param int $limit Maximum results
     * @return array Array of normalized orders
     */
    public function getRecentOrders(int $limit = 10): array;

    // =========================================================================
    // HOOK REGISTRATION
    // =========================================================================

    /**
     * Register platform-specific hooks that fire manager callbacks
     *
     * Called by EcommerceManager during initialization. The adapter
     * maps platform events to standardized callback names:
     *   'cart_updated'        — Cart contents changed
     *   'product_updated'     — Product data modified
     *   'stock_changed'       — Inventory level changed
     *   'checkout_started'    — Customer entered checkout
     *   'order_completed'     — Order successfully placed
     *   'order_status_changed' — Order status transition
     *
     * @param array $callbacks Map of event name => callable
     */
    public function registerHooks(array $callbacks = []): void;
}
