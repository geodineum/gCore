<?php
declare(strict_types=1);
/**
 * EcommerceManager Stub
 *
 * Free-tier ecommerce integration with adapter auto-detection.
 * When a supported ecommerce platform is detected (e.g. WooCommerce),
 * provides basic cart/product/order operations through the adapter.
 *
 * Upgrade to gcore-ecommerce for full features:
 * - ValKey-backed cart state (sub-ms reads)
 * - Intelligent product cache with auto-invalidation
 * - Checkout rate limiting and fraud detection
 * - Conversion funnel and cart abandonment analytics
 * - Multi-tenant shared catalog
 * - Inventory sync in cache layer
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.1.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\EcommerceAdapterInterface;
use gCore\Modules\Core\Interfaces\Extensions\EcommerceManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class EcommerceManagerStub
 *
 * Free-tier implementation of EcommerceManagerInterface with adapter auto-detection.
 * Detects supported ecommerce platforms and delegates operations through adapters.
 * Extension features (caching, analytics, security) return graceful no-ops.
 */
class EcommerceManagerStub implements EcommerceManagerInterface
{
    /** @var EcommerceManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged */
    private static $upgradeNoticeLogged = false;

    /** @var EcommerceAdapterInterface|null Active adapter */
    private $adapter = null;

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => true,
        'stub_mode' => true,
        'site_id' => 'default',
        'node_id' => 'stub',
        'adapters' => [
            'gCore\\Modules\\Core\\Adapters\\Ecommerce\\WooCommerceAdapter',
        ],
    ];

    /** @var array Capability vector (enhanced when adapter available) */
    private $capabilityVector = [
        'ecommerce' => 0.0,
        'cart' => 0.0,
        'catalog' => 0.0,
        'checkout' => 0.0,
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
     * Initialize stub — auto-detect ecommerce platform
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        $this->config = array_merge($this->defaultConfig, $config);
        $this->initialized = true;

        $this->detectAdapter();
        $this->logUpgradeNotice();
    }

    /**
     * Auto-detect available ecommerce platform adapter
     *
     * Iterates through registered adapter classes, calls detect()
     * on each, and instantiates the first one that returns true.
     * Additional adapters can be registered via config 'adapters' key.
     */
    private function detectAdapter(): void
    {
        $adapterClasses = $this->config['adapters'] ?? [];

        foreach ($adapterClasses as $adapterClass) {
            if (!class_exists($adapterClass)) {
                continue;
            }

            if (!is_subclass_of($adapterClass, EcommerceAdapterInterface::class)) {
                continue;
            }

            if ($adapterClass::detect()) {
                $this->adapter = new $adapterClass();
                $this->updateCapabilityVector();

                if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
                    $info = $this->adapter->getPlatformInfo();
                    error_log("[gCore] EcommerceManager: detected {$info['name']} v{$info['version']}");
                }
                return;
            }
        }
    }

    /**
     * Update capability vector based on detected adapter
     */
    private function updateCapabilityVector(): void
    {
        if ($this->adapter !== null) {
            $this->capabilityVector = [
                'ecommerce' => 0.5,
                'cart' => 0.7,
                'catalog' => 0.5,
                'checkout' => 0.3,
            ];
        }
    }

    /**
     * Log upgrade notice (once per request)
     */
    private function logUpgradeNotice(): void
    {
        if (self::$upgradeNoticeLogged) {
            return;
        }

        self::$upgradeNoticeLogged = true;

        if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
            $mode = $this->adapter !== null
                ? $this->adapter->getPlatformInfo()['name'] . ' adapter'
                : 'no platform detected';
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log("[gCore] EcommerceManager stub active ({$mode}) - the gcore-ecommerce extension provides caching, analytics, and security"); }
        }
    }

    // =========================================================================
    // ADAPTER MANAGEMENT
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getAdapter(): ?EcommerceAdapterInterface
    {
        return $this->adapter;
    }

    /**
     * {@inheritdoc}
     */
    public function hasAdapter(): bool
    {
        return $this->adapter !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPlatformInfo(): array
    {
        if ($this->adapter === null) {
            return [];
        }

        return $this->adapter->getPlatformInfo();
    }

    // =========================================================================
    // CART OPERATIONS — delegate to adapter
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getCart(): array
    {
        if ($this->adapter === null) {
            return [];
        }

        return $this->adapter->getCart();
    }

    /**
     * {@inheritdoc}
     */
    public function addToCart(string $productId, int $quantity = 1, array $options = []): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->addToCart($productId, $quantity, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function removeFromCart(string $itemKey): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->removeFromCart($itemKey);
    }

    /**
     * {@inheritdoc}
     */
    public function updateCartItem(string $itemKey, int $quantity): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->updateCartItem($itemKey, $quantity);
    }

    /**
     * {@inheritdoc}
     */
    public function clearCart(): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->clearCart();
    }

    /**
     * {@inheritdoc}
     */
    public function getCartTotal(): float
    {
        if ($this->adapter === null) {
            return 0.0;
        }

        return $this->adapter->getCartTotal();
    }

    /**
     * {@inheritdoc}
     */
    public function getCartItemCount(): int
    {
        if ($this->adapter === null) {
            return 0;
        }

        return $this->adapter->getCartItemCount();
    }

    // =========================================================================
    // PRODUCT OPERATIONS — delegate to adapter
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getProduct(string $productId): ?array
    {
        if ($this->adapter === null) {
            return null;
        }

        return $this->adapter->getProduct($productId);
    }

    /**
     * {@inheritdoc}
     */
    public function getProducts(array $filters = [], int $limit = 10, int $offset = 0): array
    {
        if ($this->adapter === null) {
            return [];
        }

        return $this->adapter->getProducts($filters, $limit, $offset);
    }

    /**
     * Invalidate cached product data (full feature)
     */
    public function invalidateProductCache(string $productId): void
    {
        // No-op in stub — ValKey product caching requires gcore-ecommerce
    }

    /**
     * Warm product cache (full feature)
     */
    public function warmProductCache(array $productIds = []): int
    {
        // No-op in stub — ValKey product caching requires gcore-ecommerce
        return 0;
    }

    // =========================================================================
    // INVENTORY — delegate to adapter
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function getStock(string $productId): int
    {
        if ($this->adapter === null) {
            return -1;
        }

        return $this->adapter->getProductStock($productId);
    }

    /**
     * {@inheritdoc}
     */
    public function isInStock(string $productId): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->isProductInStock($productId);
    }

    // =========================================================================
    // CHECKOUT SECURITY (full features — no-op in stub)
    // =========================================================================

    /**
     * Validate checkout rate (full feature)
     *
     * Stub always allows — rate limiting requires gcore-ecommerce + SecurityManager
     */
    public function validateCheckoutRate(string $identifier): bool
    {
        return true;
    }

    /**
     * Flag suspicious checkout (full feature)
     */
    public function flagSuspiciousCheckout(string $identifier, string $reason): void
    {
        // No-op in stub — fraud detection requires gcore-ecommerce + SecurityManager
    }

    // =========================================================================
    // ECOMMERCE ANALYTICS (full features — no-op in stub)
    // =========================================================================

    /**
     * Track cart event (full feature)
     */
    public function trackCartEvent(string $event, array $data = []): void
    {
        // No-op in stub — ecommerce analytics requires gcore-ecommerce
    }

    /**
     * Track checkout step (full feature)
     */
    public function trackCheckoutStep(string $step, array $data = []): void
    {
        // No-op in stub — funnel tracking requires gcore-ecommerce
    }

    /**
     * Get conversion funnel (full feature)
     */
    public function getConversionFunnel(string $startDate, string $endDate): array
    {
        return [];
    }

    /**
     * Get cart abandonment rate (full feature)
     */
    public function getCartAbandonmentRate(string $startDate, string $endDate): float
    {
        return -1.0;
    }

    // =========================================================================
    // PAGE CONTEXT — delegate to adapter
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function isCartPage(): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->isCartPage();
    }

    /**
     * {@inheritdoc}
     */
    public function isCheckoutPage(): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->isCheckoutPage();
    }

    /**
     * {@inheritdoc}
     */
    public function isProductPage(): bool
    {
        if ($this->adapter === null) {
            return false;
        }

        return $this->adapter->isProductPage();
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
        $status = [
            'initialized' => $this->initialized,
            'enabled' => $this->adapter !== null,
            'stub_mode' => true,
            'has_adapter' => $this->adapter !== null,
            'platform' => $this->getPlatformInfo(),
            'site_id' => $this->config['site_id'],
            'node_id' => $this->config['node_id'],
            'upgrade_message' => 'The gcore-ecommerce extension provides ValKey caching, checkout security, and conversion analytics',
        ];

        if ($this->adapter !== null) {
            $status['cart_item_count'] = $this->getCartItemCount();
        }

        return $status;
    }

    /**
     * Get capability vector (reflects adapter availability)
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
