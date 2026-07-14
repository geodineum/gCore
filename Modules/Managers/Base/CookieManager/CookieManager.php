<?php
declare(strict_types=1);
/**
 * Cookie Management System
 *
 * GDPR-compliant cookie management with consent tracking
 * and privacy controls. Framework-agnostic with conditional WordPress support.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\CookieManager
 * @version     2.0.0
 * @since       2.0.0
 *
 * FEATURES:
 * - Granular cookie categories (Essential, Functional, Analytics, Marketing)
 * - GDPR compliance (explicit consent, right to be forgotten, data portability)
 * - Cookie encryption and security
 * - Multi-tenant isolation
 * - WordPress privacy tools integration (conditional)
 *
 * @author    Niels Erik Toren
 * @copyright 2024 gCore
 */

namespace gCore\Modules\Managers\Base\CookieManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\gCore;

// Define WordPress constants if not in WordPress context
if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}
if (!defined('MONTH_IN_SECONDS')) {
    define('MONTH_IN_SECONDS', 2592000);
}

class CookieManager implements ModuleInterface {
    use ManagerConfigTrait;
    /** @var CookieManager Singleton instance */
    private static $instance = null;

    /** @var array Cookie consent preferences */
    private $preferences = [];

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var \gCore\Modules\Managers\Base\ErrorManager\ErrorManager Error handling */
    private $error;

    /** @var \gCore\Modules\Managers\Base\CacheManager\CacheManager Cache system */
    private $cache;

    /** @var array Node metadata for multi-tenant isolation */
    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];

    /** @var array Capability vector for gNode integration */
    private $capabilityVector = [
        'gdpr' => 1.0,
        'privacy' => 0.95,
        'cookies' => 1.0,
        'security' => 0.3,
        'consent' => 1.0
    ];

    /** @var \gCore\gNode\gNodeClientInterface|null gNode-Client instance for topology registration */
    private $gNodeClient = null;

    /** @var array Registered cookie categories */
    private const COOKIE_CATEGORIES = [
        'essential' => [
            'required' => true,
            'ttl' => YEAR_IN_SECONDS,
            'description' => 'Essential cookies required for basic functionality.'
        ],
        'functional' => [
            'required' => false,
            'ttl' => MONTH_IN_SECONDS * 6,
            'description' => 'Cookies that enable enhanced functionality and preferences.'
        ],
        'analytics' => [
            'required' => false,
            'ttl' => MONTH_IN_SECONDS * 3,
            'description' => 'Cookies that help understand visitor interactions.'
        ],
        'marketing' => [
            'required' => false,
            'ttl' => MONTH_IN_SECONDS,
            'description' => 'Cookies used for targeted advertising.'
        ]
    ];

    /** @var array Cookie security defaults */
    private const COOKIE_DEFAULTS = [
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
        'path' => '/',
        'domain' => ''
    ];

    /** @var array Default configuration */
    private $default_config = [
        'enabled' => true,
        'display_banner' => false,  // Themes provide their own styled banner
        'debug' => false,
        'consent_duration' => YEAR_IN_SECONDS,
        'require_explicit_consent' => true,
        'minimum_age' => 16,
        'encryption_key' => null,
        'admin_capability' => 'manage_options',
        'site_id' => 'default',
        'node_id' => 'node1'
    ];

    /** @var array Tracked cookies with expiry metadata */
    private $trackedCookies = [];

    /** @var string Cache key for cookie tracking */
    private const COOKIE_TRACKING_KEY = 'cookie_tracking_';

    /**
     * Get singleton instance
     *
     * @return ModuleInterface CookieManager instance
     */
    public static function getInstance(): ModuleInterface {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor
     */
    private function __construct() {}

    /**
     * Initialize module
     *
     * @param array $config Configuration options
     * @throws \RuntimeException If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Check for gNode-Client integration (standardized pattern)
            if (isset($config['gnode_client']) &&
                $config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface) {
                $this->gNodeClient = $config['gnode_client'];
            }

            // Layered config: default_config → ValKey → $config arg.
            // WP_DEBUG override fixed (audit #6): only takes effect when
            // no explicit `debug` value exists in ValKey or $config.
            $siteId = (string)($config['site_id'] ?? $this->default_config['site_id']);
            $valkeyConfig = [];
            $storage = $this->gcoreResolveStorage($config);
            if ($storage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'CookieManager');
            }
            $floorDefaults = $this->default_config;
            $debugWasExplicit = array_key_exists('debug', $valkeyConfig) || array_key_exists('debug', $config);
            if (!$debugWasExplicit) {
                $floorDefaults['debug'] = defined('WP_DEBUG') ? WP_DEBUG : false;
            }
            $this->config = array_merge($floorDefaults, $valkeyConfig, $config);

            // Sensitive key: encryption_key reads from the secrets keyspace
            // ({site}:gcore:secrets:CookieManager) with fallback to $config
            // passthrough (legacy callers still supply it directly).
            if (empty($this->config['encryption_key']) && $storage !== null) {
                $secret = $this->gcoreGetSecret($storage, $siteId, 'CookieManager', 'encryption_key');
                if ($secret !== null) {
                    $this->config['encryption_key'] = $secret;
                }
            }

            // Store node metadata for multi-tenant isolation
            $this->nodeMetadata['site_id'] = $this->config['site_id'] ?? 'default';
            $this->nodeMetadata['node_id'] = $this->config['node_id'] ?? 'node1';

            // Get dependencies from gCore
            $core = gCore::getInstance();
            $this->error = $core->getService('ErrorManager');
            $this->cache = $core->getService('CacheManager');

            if (!$this->error || !$this->cache) {
                throw new \RuntimeException('Required dependencies not available from gCore');
            }

            // Register WordPress hooks if in WordPress
            if (defined('ABSPATH') && function_exists('add_action')) {
                $this->registerHooks();
            }

            // Load existing preferences
            $this->loadPreferences();

            // Load cookie tracking data
            $this->loadCookieTracking();

            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('CookieManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('init', [$this, 'handleConsentUpdate']);
        add_action('wp_footer', [$this, 'displayConsentBanner'], 999);
        add_action('admin_menu', [$this, 'addAdminMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);

        // Privacy tools integration
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);

        // Privacy policy content
        add_action('admin_init', [$this, 'registerPrivacyPolicy']);
    }

    /**
     * Set cookie with security options and expiry tracking
     *
     * @param string $name Cookie name
     * @param mixed $value Cookie value
     * @param array $options Cookie options
     * @param string|null $category Cookie category for consent tracking
     * @return bool Success status
     * @api
     */
    public function setCookie(string $name, $value, array $options = [], ?string $category = null): bool {
        $options = array_merge(self::COOKIE_DEFAULTS, $options);

        // Add site/node prefix for multi-tenant isolation
        $prefix = $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'] . '_';
        $prefixedName = $prefix . $name;

        // Encrypt value if encryption key is set
        $storedValue = $value;
        if ($this->config['encryption_key']) {
            $storedValue = $this->encryptValue($value);
        }

        $expires = isset($options['expires']) ? $options['expires'] : time() + YEAR_IN_SECONDS;

        // Track cookie for expiry management
        $this->trackCookie($name, [
            'name' => $name,
            'prefixed_name' => $prefixedName,
            'category' => $category,
            'expires' => $expires,
            'created_at' => time(),
            'ttl' => $expires - time(),
            'options' => $options
        ]);

        if (function_exists('setcookie')) {
            return setcookie(
                $prefixedName,
                $storedValue,
                [
                    'expires' => $expires,
                    'path' => $options['path'],
                    'domain' => $options['domain'],
                    'secure' => $options['secure'] && (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
                    'httponly' => $options['httponly'],
                    'samesite' => $options['samesite']
                ]
            );
        }

        return false;
    }

    /**
     * Get cookie value (with decryption if enabled)
     *
     * @param string $name Cookie name (without prefix)
     * @param mixed $default Default value if cookie not found
     * @return mixed Cookie value or default
     * @api
     */
    public function getCookie(string $name, $default = null) {
        $prefix = $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'] . '_';
        $prefixedName = $prefix . $name;

        if (!isset($_COOKIE[$prefixedName])) {
            return $default;
        }

        $value = $_COOKIE[$prefixedName];

        // Decrypt if encryption key is set
        if ($this->config['encryption_key']) {
            try {
                return $this->decryptValue($value);
            } catch (\Throwable $e) {
                if ($this->config['debug']) {
                    error_log('CookieManager: Failed to decrypt cookie ' . $name . ' - ' . $e->getMessage());
                }
                return $default;
            }
        }

        return $value;
    }

    /**
     * Delete a cookie
     *
     * @param string $name Cookie name (without prefix)
     * @return bool Success status
     * @api
     */
    public function deleteCookie(string $name): bool {
        $prefix = $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'] . '_';
        $prefixedName = $prefix . $name;

        // Remove from tracking
        $this->untrackCookie($name);

        // Delete the cookie by setting expiry in the past
        if (function_exists('setcookie')) {
            return setcookie($prefixedName, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }

        return false;
    }

    // =========================================================================
    // COOKIE EXPIRY MANAGEMENT
    // =========================================================================

    /**
     * Track a cookie for expiry management
     *
     * @param string $name Cookie name
     * @param array $metadata Cookie metadata
     */
    private function trackCookie(string $name, array $metadata): void {
        $this->trackedCookies[$name] = $metadata;
        $this->persistCookieTracking();
    }

    /**
     * Remove a cookie from tracking
     *
     * @param string $name Cookie name
     */
    private function untrackCookie(string $name): void {
        unset($this->trackedCookies[$name]);
        $this->persistCookieTracking();
    }

    /**
     * Persist cookie tracking to cache
     */
    private function persistCookieTracking(): void {
        if (!$this->cache) {
            return;
        }

        $key = self::COOKIE_TRACKING_KEY . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
        $this->cache->set($key, $this->trackedCookies, YEAR_IN_SECONDS);
    }

    /**
     * Load cookie tracking from cache
     */
    private function loadCookieTracking(): void {
        if (!$this->cache) {
            return;
        }

        $key = self::COOKIE_TRACKING_KEY . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
        $tracked = $this->cache->get($key);

        if ($tracked && is_array($tracked)) {
            $this->trackedCookies = $tracked;
        }
    }

    /**
     * Get expiry information for a cookie
     *
     * @param string $name Cookie name
     * @return array|null Expiry info ['expires' => timestamp, 'remaining' => seconds, 'expired' => bool]
     * @api
     */
    public function getCookieExpiry(string $name): ?array {
        if (!isset($this->trackedCookies[$name])) {
            return null;
        }

        $metadata = $this->trackedCookies[$name];
        $now = time();
        $remaining = $metadata['expires'] - $now;

        return [
            'expires' => $metadata['expires'],
            'expires_formatted' => date('Y-m-d H:i:s', $metadata['expires']),
            'remaining' => max(0, $remaining),
            'remaining_formatted' => $this->formatDuration($remaining),
            'expired' => $remaining <= 0,
            'category' => $metadata['category'] ?? null,
            'created_at' => $metadata['created_at']
        ];
    }

    /**
     * Extend a cookie's expiry
     *
     * @param string $name Cookie name
     * @param int $additionalTime Additional time in seconds
     * @return bool Success status
     * @api
     */
    public function extendCookieExpiry(string $name, int $additionalTime): bool {
        if (!isset($this->trackedCookies[$name])) {
            return false;
        }

        // Get current cookie value
        $currentValue = $this->getCookie($name);
        if ($currentValue === null) {
            return false;
        }

        $metadata = $this->trackedCookies[$name];
        $newExpiry = max($metadata['expires'], time()) + $additionalTime;

        $options = $metadata['options'] ?? self::COOKIE_DEFAULTS;
        $options['expires'] = $newExpiry;

        return $this->setCookie($name, $currentValue, $options, $metadata['category'] ?? null);
    }

    /**
     * Refresh a cookie (reset TTL to original duration)
     *
     * @param string $name Cookie name
     * @return bool Success status
     * @api
     */
    public function refreshCookie(string $name): bool {
        if (!isset($this->trackedCookies[$name])) {
            return false;
        }

        // Get current cookie value
        $currentValue = $this->getCookie($name);
        if ($currentValue === null) {
            return false;
        }

        $metadata = $this->trackedCookies[$name];
        $originalTtl = $metadata['ttl'] ?? YEAR_IN_SECONDS;

        $options = $metadata['options'] ?? self::COOKIE_DEFAULTS;
        $options['expires'] = time() + $originalTtl;

        return $this->setCookie($name, $currentValue, $options, $metadata['category'] ?? null);
    }

    /**
     * Get all cookies expiring within a time window
     *
     * @param int $withinSeconds Seconds from now
     * @return array Cookies expiring soon
     * @api
     */
    public function getCookiesExpiringSoon(int $withinSeconds = 86400): array {
        $now = time();
        $threshold = $now + $withinSeconds;
        $expiring = [];

        foreach ($this->trackedCookies as $name => $metadata) {
            if ($metadata['expires'] <= $threshold && $metadata['expires'] > $now) {
                $expiring[$name] = $this->getCookieExpiry($name);
            }
        }

        return $expiring;
    }

    /**
     * Get all expired cookies
     *
     * @return array Expired cookies
     * @api
     */
    public function getExpiredCookies(): array {
        $now = time();
        $expired = [];

        foreach ($this->trackedCookies as $name => $metadata) {
            if ($metadata['expires'] <= $now) {
                $expired[$name] = $this->getCookieExpiry($name);
            }
        }

        return $expired;
    }

    /**
     * Clean up expired cookie tracking
     *
     * @return int Number of entries cleaned
     * @api
     */
    public function cleanupExpiredTracking(): int {
        $now = time();
        $cleaned = 0;

        foreach ($this->trackedCookies as $name => $metadata) {
            if ($metadata['expires'] <= $now) {
                unset($this->trackedCookies[$name]);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->persistCookieTracking();
        }

        return $cleaned;
    }

    /**
     * Format duration in human-readable form
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration
     */
    private function formatDuration(int $seconds): string {
        if ($seconds <= 0) {
            return 'expired';
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) $parts[] = $days . 'd';
        if ($hours > 0) $parts[] = $hours . 'h';
        if ($minutes > 0 && $days == 0) $parts[] = $minutes . 'm';

        return implode(' ', $parts) ?: '< 1m';
    }

    /**
     * Check if user has consent for category
     *
     * @param string $category Cookie category
     * @return bool Has consent
     * @api
     */
    public function hasConsent(string $category): bool {
        if (!isset(self::COOKIE_CATEGORIES[$category])) {
            return false;
        }

        // Essential cookies are always allowed
        if (self::COOKIE_CATEGORIES[$category]['required']) {
            return true;
        }

        return isset($this->preferences[$category]) && $this->preferences[$category] === true;
    }

    /**
     * Update consent preferences
     *
     * @param array $preferences New preferences
     * @return bool Success status
     * @api
     */
    public function updateConsent(array $preferences): bool {
        try {
            // Validate preferences
            foreach ($preferences as $category => $consent) {
                if (!isset(self::COOKIE_CATEGORIES[$category])) {
                    continue;
                }
                $this->preferences[$category] = (bool) $consent;
            }

            // Store preferences
            $this->storePreferences();

            // Trigger event if in WordPress
            if (function_exists('do_action')) {
                do_action('gCore_cookie_consent_updated', $this->preferences);
            }

            return true;

        } catch (\Exception $e) {
            if ($this->error) {
                $this->error->logError('cookie_consent_update_failed', $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Display consent banner (WordPress-specific)
     * @api
     */
    public function displayConsentBanner(): void {
        // Skip if disabled or if theme provides its own banner
        if (!$this->config['enabled'] || !$this->config['display_banner'] || $this->hasAllConsent()) {
            return;
        }

        $siteId = $this->nodeMetadata['site_id'] ?? 'default';
        $restUrl = function_exists('rest_url') ? rest_url('gcore/v1/cookie-consent') : '/wp-json/gcore/v1/cookie-consent';
        $nonce = function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '';

        // Simple consent banner
        echo '<div id="gCore-cookie-consent" style="position:fixed;bottom:0;left:0;right:0;background:#000;color:#fff;padding:20px;z-index:9999;">';
        echo '<p>We use cookies to enhance your experience. By continuing to visit this site you agree to our use of cookies.</p>';
        echo '<button onclick="gCoreCookieConsent.acceptAll()">Accept All</button>';
        echo '<button onclick="gCoreCookieConsent.reject()">Reject</button>';
        echo '</div>';

        // JavaScript for cookie consent
        echo '<script>
(function() {
    var banner = document.getElementById("gCore-cookie-consent");
    var consentKey = "gcore_cookie_consent_' . esc_js($siteId) . '";

    // Check if already consented
    if (localStorage.getItem(consentKey)) {
        banner.style.display = "none";
    }

    window.gCoreCookieConsent = {
        acceptAll: function() {
            this.save({ essential: true, functional: true, analytics: true, marketing: true });
        },
        reject: function() {
            this.save({ essential: true, functional: false, analytics: false, marketing: false });
        },
        save: function(prefs) {
            localStorage.setItem(consentKey, JSON.stringify({ preferences: prefs, timestamp: Date.now() }));
            banner.style.display = "none";
            fetch("' . esc_js($restUrl) . '", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-WP-Nonce": "' . esc_js($nonce) . '" },
                body: JSON.stringify(prefs)
            }).catch(function(e) { console.log("Cookie consent save:", e); });
        }
    };
})();
</script>';
    }

    /**
     * Handle consent update (WordPress-specific)
     */
    public function handleConsentUpdate(): void {
        if (!isset($_POST['gCore_cookie_consent']) || !function_exists('wp_verify_nonce')) {
            return;
        }

        if (!wp_verify_nonce($_POST['gCore_cookie_nonce'], 'gCore_cookie_consent')) {
            return;
        }

        $preferences = [];
        foreach (self::COOKIE_CATEGORIES as $category => $settings) {
            $preferences[$category] = isset($_POST['consent_' . $category]);
        }

        $this->updateConsent($preferences);
    }

    /**
     * Register privacy policy (WordPress-specific)
     */
    public function registerPrivacyPolicy(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content(
            'gCore Cookie Manager',
            $this->getPrivacyPolicyContent()
        );
    }

    /**
     * Get privacy policy content
     *
     * @return string Privacy policy content
     */
    private function getPrivacyPolicyContent(): string {
        $content = '<h2>Cookie Usage</h2>';
        $content .= '<p>This website uses cookies to enhance your browsing experience.</p>';

        foreach (self::COOKIE_CATEGORIES as $category => $settings) {
            $content .= '<h3>' . ucfirst($category) . ' Cookies</h3>';
            $content .= '<p>' . $settings['description'] . '</p>';
        }

        return $content;
    }

    /**
     * Load consent preferences
     */
    private function loadPreferences(): void {
        if (!$this->cache) {
            return;
        }

        $key = 'cookie_preferences_' . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
        $stored = $this->cache->get($key);

        if ($stored) {
            $this->preferences = $stored;
        }
    }

    /**
     * Store consent preferences
     */
    private function storePreferences(): void {
        if (!$this->cache) {
            return;
        }

        $key = 'cookie_preferences_' . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
        $this->cache->set($key, $this->preferences, $this->config['consent_duration']);
    }

    /**
     * Encrypt cookie value with authenticated encryption (Encrypt-then-MAC)
     *
     * Uses AES-256-CBC for encryption and HMAC-SHA256 for authentication.
     * Uses JSON encoding instead of serialize() to prevent object injection attacks.
     *
     * Format: base64(IV || ciphertext || HMAC)
     * - IV: 16 bytes
     * - Ciphertext: variable length (base64 encoded by openssl)
     * - HMAC: 32 bytes (SHA-256)
     *
     * @param mixed $value Value to encrypt
     * @return string Encrypted and authenticated value
     */
    private function encryptValue($value): string {
        if (!$this->config['encryption_key']) {
            return is_string($value) ? $value : json_encode($value);
        }

        $json = json_encode($value);
        if ($json === false) {
            throw new \RuntimeException('Failed to JSON encode cookie value');
        }

        $iv = random_bytes(16);
        $encryptionKey = $this->config['encryption_key'];

        // Encrypt the value
        $ciphertext = openssl_encrypt($json, 'AES-256-CBC', $encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt cookie value');
        }

        // Compute HMAC over IV + ciphertext (Encrypt-then-MAC)
        $hmacKey = $this->deriveHmacKey($encryptionKey);
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $hmacKey, true);

        // Format: IV || ciphertext || HMAC
        return base64_encode($iv . $ciphertext . $hmac);
    }

    /**
     * Decrypt cookie value with authentication verification
     *
     * Verifies HMAC before decryption (Encrypt-then-MAC pattern).
     * Uses JSON decoding instead of unserialize() to prevent object injection attacks.
     *
     * @param string $encoded Encrypted value
     * @return mixed Decrypted value
     * @throws \RuntimeException If authentication or decryption fails
     */
    private function decryptValue(string $encoded) {
        if (!$this->config['encryption_key']) {
            $decoded = json_decode($encoded, true);
            return $decoded !== null ? $decoded : $encoded;
        }

        $decoded = base64_decode($encoded, true);
        // Minimum length: 16 (IV) + 1 (ciphertext) + 32 (HMAC) = 49 bytes
        if ($decoded === false || strlen($decoded) < 49) {
            throw new \RuntimeException('Invalid encrypted cookie format');
        }

        $encryptionKey = $this->config['encryption_key'];
        $hmacKey = $this->deriveHmacKey($encryptionKey);

        // Extract components: IV (16) + ciphertext (variable) + HMAC (32)
        $iv = substr($decoded, 0, 16);
        $hmac = substr($decoded, -32);
        $ciphertext = substr($decoded, 16, -32);

        // Verify HMAC first (timing-safe comparison)
        $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $hmacKey, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            throw new \RuntimeException('Cookie authentication failed - possible tampering detected');
        }

        // Decrypt after successful authentication
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $encryptionKey, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            throw new \RuntimeException('Failed to decrypt cookie value');
        }

        $value = json_decode($decrypted, true);
        if ($value === null && $decrypted !== 'null') {
            throw new \RuntimeException('Failed to decode decrypted cookie value');
        }

        return $value;
    }

    /**
     * Derive HMAC key from encryption key
     *
     * Uses HKDF-like derivation to create a separate key for HMAC.
     * This ensures encryption and authentication use different keys.
     *
     * @param string $encryptionKey Base encryption key
     * @return string Derived HMAC key
     */
    private function deriveHmacKey(string $encryptionKey): string {
        // Use hash_hkdf if available (PHP 7.1.2+), otherwise use simple derivation
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $encryptionKey, 32, 'gcore-cookie-hmac');
        }

        // Fallback: simple but secure key derivation
        return hash('sha256', $encryptionKey . 'gcore-cookie-hmac-key', true);
    }

    /**
     * Check if all consent is granted
     *
     * @return bool All consent granted
     */
    private function hasAllConsent(): bool {
        foreach (self::COOKIE_CATEGORIES as $category => $settings) {
            if (!$settings['required'] && !$this->hasConsent($category)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Register data exporter (WordPress-specific)
     *
     * @param array $exporters Existing exporters
     * @return array Modified exporters
     * @api
     */
    public function registerExporter(array $exporters): array {
        $exporters['gCore-cookie-manager'] = [
            'exporter_friendly_name' => 'Cookie Consent Data',
            'callback' => [$this, 'exportPersonalData']
        ];
        return $exporters;
    }

    /**
     * Export personal data (WordPress-specific)
     *
     * @param string $email_address Email address
     * @return array Export data
     * @api
     */
    public function exportPersonalData(string $email_address): array {
        return [
            'data' => [[
                'group_id' => 'cookie-consent',
                'group_label' => 'Cookie Consent',
                'item_id' => 'preferences',
                'data' => [
                    ['name' => 'Preferences', 'value' => json_encode($this->preferences)]
                ]
            ]],
            'done' => true
        ];
    }

    /**
     * Register data eraser (WordPress-specific)
     *
     * @param array $erasers Existing erasers
     * @return array Modified erasers
     * @api
     */
    public function registerEraser(array $erasers): array {
        $erasers['gCore-cookie-manager'] = [
            'eraser_friendly_name' => 'Cookie Consent Data',
            'callback' => [$this, 'erasePersonalData']
        ];
        return $erasers;
    }

    /**
     * Erase personal data (WordPress-specific)
     *
     * @param string $email_address Email address
     * @return array Erase result
     * @api
     */
    public function erasePersonalData(string $email_address): array {
        $this->preferences = [];
        $this->storePreferences();

        return [
            'items_removed' => true,
            'items_retained' => false,
            'messages' => [],
            'done' => true
        ];
    }

    /**
     * Add admin submenu page under existing gCore menu (WordPress-specific)
     *
     * Uses the existing gCore admin menu rather than creating a new top-level menu.
     */
    public function addAdminMenuPage(): void {
        if (!function_exists('add_submenu_page')) {
            return;
        }

        // Add as submenu under gCore's main menu
        add_submenu_page(
            'gcore-dashboard',                             // Parent slug (gCore's main menu)
            'Cookie Consent Settings',                      // Page title
            'Cookie Consent',                               // Menu title
            $this->config['admin_capability'],              // Capability required
            'gcore-cookie-settings',                        // Menu slug
            [$this, 'renderAdminPage']                      // Callback function
        );
    }

    /**
     * Render the admin settings page (WordPress-specific)
     */
    public function renderAdminPage(): void {
        if (!function_exists('current_user_can') || !current_user_can($this->config['admin_capability'])) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle form submission
        if (isset($_POST['gcore_cookie_settings_nonce']) &&
            wp_verify_nonce($_POST['gcore_cookie_settings_nonce'], 'gcore_cookie_settings')) {

            $this->config['enabled'] = isset($_POST['cookie_enabled']);
            $this->config['require_explicit_consent'] = isset($_POST['require_explicit_consent']);
            $this->config['minimum_age'] = intval($_POST['minimum_age'] ?? 16);

            // Update consent duration
            $duration = intval($_POST['consent_duration'] ?? 365);
            $this->config['consent_duration'] = $duration * 86400; // Convert days to seconds

            // Save settings to WordPress options
            update_option('gcore_cookie_settings', [
                'enabled' => $this->config['enabled'],
                'require_explicit_consent' => $this->config['require_explicit_consent'],
                'minimum_age' => $this->config['minimum_age'],
                'consent_duration' => $this->config['consent_duration']
            ]);

            echo '<div class="notice notice-success"><p>Settings saved successfully.</p></div>';
        }

        // Load saved settings
        $saved = get_option('gcore_cookie_settings', []);
        $this->config = array_merge($this->config, $saved);

        ?>
        <div class="wrap">
            <h1>gCore Cookie Consent Settings</h1>

            <form method="post" action="">
                <?php wp_nonce_field('gcore_cookie_settings', 'gcore_cookie_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Cookie Consent</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cookie_enabled" value="1"
                                    <?php checked($this->config['enabled']); ?>>
                                Enable cookie consent management
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Require Explicit Consent</th>
                        <td>
                            <label>
                                <input type="checkbox" name="require_explicit_consent" value="1"
                                    <?php checked($this->config['require_explicit_consent']); ?>>
                                Require explicit opt-in before setting non-essential cookies (GDPR compliant)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Minimum Age</th>
                        <td>
                            <input type="number" name="minimum_age" value="<?php echo esc_attr($this->config['minimum_age']); ?>" min="13" max="21">
                            <p class="description">Minimum age for consent (GDPR default: 16)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Consent Duration</th>
                        <td>
                            <input type="number" name="consent_duration" value="<?php echo esc_attr(intval($this->config['consent_duration'] / 86400)); ?>" min="1" max="730">
                            <p class="description">Days to remember consent preference</p>
                        </td>
                    </tr>
                </table>

                <h2>Cookie Categories</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Required</th>
                            <th>Default TTL</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (self::COOKIE_CATEGORIES as $category => $settings): ?>
                        <tr>
                            <td><strong><?php echo esc_html(ucfirst($category)); ?></strong></td>
                            <td><?php echo $settings['required'] ? 'Yes' : 'No'; ?></td>
                            <td><?php echo $this->formatDuration($settings['ttl']); ?></td>
                            <td><?php echo esc_html($settings['description']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h2>Current Preferences</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Consent Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (self::COOKIE_CATEGORIES as $category => $settings): ?>
                        <tr>
                            <td><?php echo esc_html(ucfirst($category)); ?></td>
                            <td>
                                <?php if ($settings['required']): ?>
                                    <span style="color: green;">Always Allowed</span>
                                <?php elseif ($this->hasConsent($category)): ?>
                                    <span style="color: green;">Consented</span>
                                <?php else: ?>
                                    <span style="color: red;">Not Consented</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register settings (WordPress-specific)
     */
    public function registerSettings(): void {
        if (!function_exists('register_setting')) {
            return;
        }

        register_setting('gcore_cookie_settings_group', 'gcore_cookie_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeSettings'],
            'default' => [
                'enabled' => true,
                'require_explicit_consent' => true,
                'minimum_age' => 16,
                'consent_duration' => YEAR_IN_SECONDS
            ]
        ]);
    }

    /**
     * Sanitize settings input
     *
     * @param array $input Raw input
     * @return array Sanitized input
     */
    public function sanitizeSettings(array $input): array {
        return [
            'enabled' => !empty($input['enabled']),
            'require_explicit_consent' => !empty($input['require_explicit_consent']),
            'minimum_age' => max(13, min(21, intval($input['minimum_age'] ?? 16))),
            'consent_duration' => max(86400, min(YEAR_IN_SECONDS * 2, intval($input['consent_duration'] ?? YEAR_IN_SECONDS)))
        ];
    }

    /**
     * Get module configuration
     *
     * @return array Configuration settings
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     *
     * @param array $config New configuration settings
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if module is initialized
     *
     * @return bool Initialization status
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get module status
     *
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'preferences' => $this->preferences,
            'categories' => array_keys(self::COOKIE_CATEGORIES),
            'tracked_cookies' => count($this->trackedCookies),
            'expiring_soon' => count($this->getCookiesExpiringSoon(86400)),
            'expired' => count($this->getExpiredCookies()),
            'encryption_enabled' => !empty($this->config['encryption_key']),
            'site_id' => $this->nodeMetadata['site_id'],
            'node_id' => $this->nodeMetadata['node_id'],
            'framework' => defined('ABSPATH') ? 'WordPress' : 'Generic PHP'
        ];
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}
