<?php
declare(strict_types=1);
/**
 * WooCommerce Adapter
 *
 * Reference EcommerceAdapterInterface implementation for WooCommerce.
 * Auto-detects WooCommerce, normalizes its data structures to gCore's
 * platform-agnostic format, and maps WooCommerce hooks to standardized
 * ecommerce events.
 *
 * Ships with gCore core (Apache 2.0) — the adapter is a data bridge,
 * not an extension feature. Real value comes from what
 * EcommerceManager does with the data (caching, analytics, security).
 *
 * @package     gCore
 * @subpackage  Modules\Core\Adapters\Ecommerce
 * @version     1.0.0
 * @since       3.1.0
 * @license     Apache-2.0
 */

namespace gCore\Modules\Core\Adapters\Ecommerce;

use gCore\Modules\Core\Interfaces\EcommerceAdapterInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

class WooCommerceAdapter implements EcommerceAdapterInterface
{
    /** @var string Adapter version */
    const ADAPTER_VERSION = '1.0.0';

    /** @var array Registered event callbacks */
    private $callbacks = [];

    /** @var bool Whether hooks have been registered */
    private $hooksRegistered = false;

    // =========================================================================
    // PLATFORM DETECTION
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public static function detect(): bool
    {
        return class_exists('WooCommerce') || function_exists('WC');
    }

    /**
     * {@inheritdoc}
     */
    public function getPlatformInfo(): array
    {
        $version = defined('WC_VERSION') ? WC_VERSION : 'unknown';

        return [
            'name' => 'WooCommerce',
            'version' => $version,
            'adapter_version' => self::ADAPTER_VERSION,
        ];
    }

    // =========================================================================
    // CART OPERATIONS
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getCart(): array
    {
        $cart = $this->getWcCart();
        if ($cart === null) {
            return [];
        }

        $items = [];
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            $items[] = [
                'key' => $key,
                'product_id' => (string) $item['product_id'],
                'name' => $product ? $product->get_name() : '',
                'quantity' => (int) $item['quantity'],
                'price' => $product ? (float) $product->get_price() : 0.0,
                'subtotal' => (float) ($item['line_total'] ?? 0.0),
                'image' => $product ? $this->getProductImageUrl($product) : '',
                'options' => $this->extractVariationData($item),
            ];
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function addToCart(string $productId, int $quantity = 1, array $options = []): bool
    {
        $cart = $this->getWcCart();
        if ($cart === null) {
            return false;
        }

        $variationId = isset($options['variation_id']) ? (int) $options['variation_id'] : 0;
        $variations = $options['variations'] ?? [];

        $result = $cart->add_to_cart((int) $productId, $quantity, $variationId, $variations);

        return $result !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function removeFromCart(string $itemKey): bool
    {
        $cart = $this->getWcCart();
        if ($cart === null) {
            return false;
        }

        return $cart->remove_cart_item($itemKey);
    }

    /**
     * {@inheritdoc}
     */
    public function updateCartItem(string $itemKey, int $quantity): bool
    {
        $cart = $this->getWcCart();
        if ($cart === null) {
            return false;
        }

        return $cart->set_quantity($itemKey, $quantity) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function clearCart(): bool
    {
        $cart = $this->getWcCart();
        if ($cart === null) {
            return false;
        }

        $cart->empty_cart();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getCartTotal(): float
    {
        $cart = $this->getWcCart();
        if ($cart === null) {
            return 0.0;
        }

        return (float) $cart->get_cart_contents_total();
    }

    /**
     * {@inheritdoc}
     */
    public function getCartItemCount(): int
    {
        $cart = $this->getWcCart();
        if ($cart === null) {
            return 0;
        }

        return (int) $cart->get_cart_contents_count();
    }

    // =========================================================================
    // PRODUCT OPERATIONS
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getProduct(string $productId): ?array
    {
        if (!function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product((int) $productId);
        if (!$product || !$product->exists()) {
            return null;
        }

        return $this->normalizeProduct($product);
    }

    /**
     * {@inheritdoc}
     */
    public function getProducts(array $filters = [], int $limit = 10, int $offset = 0): array
    {
        if (!function_exists('wc_get_products')) {
            return [];
        }

        $args = [
            'limit' => $limit,
            'offset' => $offset,
            'status' => 'publish',
            'return' => 'objects',
        ];

        if (isset($filters['category'])) {
            $args['category'] = [$filters['category']];
        }
        if (isset($filters['search'])) {
            $args['s'] = $filters['search'];
        }
        if (isset($filters['on_sale']) && $filters['on_sale']) {
            $args['include'] = wc_get_product_ids_on_sale();
        }
        if (isset($filters['in_stock']) && $filters['in_stock']) {
            $args['stock_status'] = 'instock';
        }
        if (isset($filters['type'])) {
            $args['type'] = $filters['type'];
        }
        if (isset($filters['ids'])) {
            $args['include'] = $filters['ids'];
        }

        $products = wc_get_products($args);
        $normalized = [];
        foreach ($products as $product) {
            $normalized[] = $this->normalizeProduct($product);
        }

        return $normalized;
    }

    /**
     * {@inheritdoc}
     */
    public function getProductStock(string $productId): int
    {
        if (!function_exists('wc_get_product')) {
            return -1;
        }

        $product = wc_get_product((int) $productId);
        if (!$product) {
            return -1;
        }

        if (!$product->managing_stock()) {
            return -1;
        }

        return (int) $product->get_stock_quantity();
    }

    /**
     * {@inheritdoc}
     */
    public function isProductInStock(string $productId): bool
    {
        if (!function_exists('wc_get_product')) {
            return false;
        }

        $product = wc_get_product((int) $productId);
        if (!$product) {
            return false;
        }

        return $product->is_in_stock();
    }

    // =========================================================================
    // PAGE CONTEXT
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getCartUrl(): string
    {
        if (function_exists('wc_get_cart_url')) {
            return wc_get_cart_url();
        }
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getCheckoutUrl(): string
    {
        if (function_exists('wc_get_checkout_url')) {
            return wc_get_checkout_url();
        }
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function isCartPage(): bool
    {
        return function_exists('is_cart') && is_cart();
    }

    /**
     * {@inheritdoc}
     */
    public function isCheckoutPage(): bool
    {
        return function_exists('is_checkout') && is_checkout();
    }

    /**
     * {@inheritdoc}
     */
    public function isProductPage(): bool
    {
        return function_exists('is_product') && is_product();
    }

    // =========================================================================
    // ORDER OPERATIONS
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getOrder(string $orderId): ?array
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order((int) $orderId);
        if (!$order) {
            return null;
        }

        return $this->normalizeOrder($order);
    }

    /**
     * {@inheritdoc}
     */
    public function getRecentOrders(int $limit = 10): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }

        $customerId = get_current_user_id();
        if (!$customerId) {
            return [];
        }

        $orders = wc_get_orders([
            'customer_id' => $customerId,
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $normalized = [];
        foreach ($orders as $order) {
            $normalized[] = $this->normalizeOrder($order);
        }

        return $normalized;
    }

    // =========================================================================
    // HOOK REGISTRATION
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function registerHooks(array $callbacks = []): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        $this->callbacks = $callbacks;

        // Cart events
        if (function_exists('add_action')) {
            add_action('woocommerce_add_to_cart', [$this, 'onCartUpdated'], 20);
            add_action('woocommerce_cart_item_removed', [$this, 'onCartUpdated'], 20);
            add_action('woocommerce_cart_item_restored', [$this, 'onCartUpdated'], 20);
            add_action('woocommerce_after_cart_item_quantity_update', [$this, 'onCartUpdated'], 20);

            // Checkout events
            add_action('woocommerce_checkout_process', [$this, 'onCheckoutStarted'], 20);
            add_action('woocommerce_checkout_order_processed', [$this, 'onOrderCompleted'], 20, 2);

            // Product/stock events
            add_action('woocommerce_update_product', [$this, 'onProductUpdated'], 20, 1);
            add_action('woocommerce_product_set_stock', [$this, 'onStockChanged'], 20, 1);
            add_action('woocommerce_variation_set_stock', [$this, 'onStockChanged'], 20, 1);

            // Order status events
            add_action('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 20, 3);
        }

        $this->hooksRegistered = true;
    }

    // =========================================================================
    // HOOK CALLBACKS (called by WordPress action system)
    // =========================================================================

    /**
     * @internal Called by WooCommerce cart actions
     */
    public function onCartUpdated(): void
    {
        $this->fireEvent('cart_updated');
    }

    /**
     * @internal Called on checkout process start
     */
    public function onCheckoutStarted(): void
    {
        $this->fireEvent('checkout_started');
    }

    /**
     * @internal Called when order is processed
     * @param int $orderId
     * @param array $data Posted checkout data
     */
    public function onOrderCompleted($orderId, $data = []): void
    {
        $this->fireEvent('order_completed', [
            'order_id' => (string) $orderId,
        ]);
    }

    /**
     * @internal Called on product save
     * @param int $productId
     */
    public function onProductUpdated($productId): void
    {
        $this->fireEvent('product_updated', [
            'product_id' => (string) $productId,
        ]);
    }

    /**
     * @internal Called on stock level change
     * @param \WC_Product $product
     */
    public function onStockChanged($product): void
    {
        $this->fireEvent('stock_changed', [
            'product_id' => (string) $product->get_id(),
            'new_stock' => (int) $product->get_stock_quantity(),
        ]);
    }

    /**
     * @internal Called on order status transition
     * @param int $orderId
     * @param string $from Old status
     * @param string $to New status
     */
    public function onOrderStatusChanged($orderId, $from, $to): void
    {
        $this->fireEvent('order_status_changed', [
            'order_id' => (string) $orderId,
            'from' => $from,
            'to' => $to,
        ]);
    }

    // =========================================================================
    // INTERNAL HELPERS
    // =========================================================================

    /**
     * Get the WooCommerce cart instance
     *
     * @return \WC_Cart|null
     */
    private function getWcCart()
    {
        if (function_exists('WC') && WC()->cart) {
            return WC()->cart;
        }
        return null;
    }

    /**
     * Fire a registered event callback
     *
     * @param string $event Event name
     * @param array $data Event data
     */
    private function fireEvent(string $event, array $data = []): void
    {
        if (isset($this->callbacks[$event]) && is_callable($this->callbacks[$event])) {
            call_user_func($this->callbacks[$event], $data);
        }
    }

    /**
     * Normalize a WC_Product to gCore's standard product format
     *
     * @param \WC_Product $product
     * @return array Normalized product data
     */
    private function normalizeProduct($product): array
    {
        $categories = [];
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = $term->name;
            }
        }

        $regularPrice = $product->get_regular_price();
        $salePrice = $product->get_sale_price();

        return [
            'id' => (string) $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'type' => $product->get_type(),
            'sku' => $product->get_sku(),
            'price' => (float) $product->get_price(),
            'regular_price' => $regularPrice !== '' ? (float) $regularPrice : 0.0,
            'sale_price' => $salePrice !== '' ? (float) $salePrice : 0.0,
            'on_sale' => $product->is_on_sale(),
            'stock_quantity' => $product->managing_stock() ? (int) $product->get_stock_quantity() : -1,
            'in_stock' => $product->is_in_stock(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'image' => $this->getProductImageUrl($product),
            'categories' => $categories,
            'url' => get_permalink($product->get_id()),
        ];
    }

    /**
     * Normalize a WC_Order to gCore's standard order format
     *
     * @param \WC_Order $order
     * @return array Normalized order data
     */
    private function normalizeOrder($order): array
    {
        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = [
                'key' => (string) $item->get_id(),
                'product_id' => (string) $item->get_product_id(),
                'name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'price' => (float) ($item->get_total() / max(1, $item->get_quantity())),
                'subtotal' => (float) $item->get_total(),
                'image' => $product ? $this->getProductImageUrl($product) : '',
                'options' => [],
            ];
        }

        return [
            'id' => (string) $order->get_id(),
            'status' => $order->get_status(),
            'total' => (float) $order->get_total(),
            'currency' => $order->get_currency(),
            'items' => $items,
            'customer_id' => (string) $order->get_customer_id(),
            'date_created' => $order->get_date_created()
                ? $order->get_date_created()->format('c')
                : '',
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ],
            'shipping' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ],
        ];
    }

    /**
     * Get the primary image URL for a product
     *
     * @param \WC_Product $product
     * @return string Image URL or empty string
     */
    private function getProductImageUrl($product): string
    {
        $imageId = $product->get_image_id();
        if ($imageId) {
            $url = wp_get_attachment_image_url($imageId, 'woocommerce_single');
            return $url ?: '';
        }
        return '';
    }

    /**
     * Extract variation data from a cart item
     *
     * @param array $item WooCommerce cart item data
     * @return array Normalized variation/option data
     */
    private function extractVariationData(array $item): array
    {
        $options = [];

        if (!empty($item['variation_id'])) {
            $options['variation_id'] = (string) $item['variation_id'];
        }

        if (!empty($item['variation'])) {
            foreach ($item['variation'] as $attr => $value) {
                $name = str_replace('attribute_', '', $attr);
                $options[$name] = $value;
            }
        }

        return $options;
    }
}
