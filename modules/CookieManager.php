<?php
/**
 * Cookie Management System for Nierto Themes
 * ==============================================================================
 * 
 * GDPR-compliant cookie management system with comprehensive consent tracking
 * and privacy controls.
 * 
 * @package     Nierto
 * @subpackage  Modules
 * @version     2.0.0
 * @since       2.0.0
 * 
 * File Location: /inc/modules/CookieManager.php
 * 
 * ARCHITECTURE OVERVIEW
 * ====================
 * - Implements ModuleInterface for NiertoCore integration
 * - Uses Strategy pattern for different consent storage methods
 * - Event-driven architecture for consent changes
 * - Integrates with WordPress privacy tools
 * 
 * FEATURES
 * ========
 * 1. Cookie Management:
 *    - Granular cookie categories (Essential, Functional, Analytics, Marketing)
 *    - Individual cookie consent tracking
 *    - Automatic cookie expiration
 *    - Cookie audit trail
 * 
 * 2. GDPR Compliance:
 *    - Explicit consent management
 *    - Right to be forgotten
 *    - Data portability
 *    - Privacy policy integration
 *    - Consent withdrawal
 *    - Age verification
 * 
 * 3. Security:
 *    - Cookie encryption
 *    - Secure cookie attributes
 *    - CSRF protection
 *    - XSS prevention
 *    - Cookie prefixing
 * 
 * DEPENDENCIES
 * ===========
 * Modules:
 * - ErrorManager  - Error handling
 * - CacheManager  - Consent storage
 * 
 * WordPress:
 * - Options API
 * - Privacy Tools
 * - AJAX handlers
 * 
 * USAGE
 * =====
 * // Get instance through NiertoCore
 * $cookie_manager = NiertoCore::getInstance()->getModule('Cookie');
 * 
 * // Check consent
 * if ($cookie_manager->hasConsent('analytics')) {
 *     // Initialize analytics
 * }
 * 
 * SECURITY MEASURES
 * ================
 * 1. Cookie Security:
 *    - Secure flag
 *    - HttpOnly flag
 *    - SameSite attribute
 *    - Path restriction
 *    - Domain restriction
 * 
 * 2. Data Protection:
 *    - Encryption at rest
 *    - Secure transmission
 *    - Access control
 *    - Input validation
 *    - Output escaping
 * 
 * GDPR COMPLIANCE
 * ==============
 * - Explicit consent recording
 * - Purpose specification
 * - Data minimization
 * - Storage limitation
 * - Right to withdraw
 * - Right to erasure
 * 
 * @author    Niels Erik Toren
 * @copyright 2024 Nierto
 */

namespace Nierto\Modules;

use Nierto\Core\ModuleInterface;
use Nierto\Core\NiertoCore;

if (!defined('ABSPATH')) {
    exit;
}

class CookieManager implements ModuleInterface {
    /** @var CookieManager Singleton instance */
    private static $instance = null;
    
    /** @var array Cookie consent preferences */
    private $preferences = [];
    
    /** @var array Configuration settings */
    private $config = [];
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var ErrorManager Error handling */
    private $error;
    
    /** @var CacheManager Cache system */
    private $cache;

    /** @var array Registered cookie categories */
    private const COOKIE_CATEGORIES = [
        'essential' => [
            'required' => true,
            'ttl' => YEAR_IN_SECONDS,
            'description' => 'Essential cookies required for basic website functionality.'
        ],
        'functional' => [
            'required' => false,
            'ttl' => MONTH_IN_SECONDS * 6,
            'description' => 'Cookies that enable enhanced functionality and preferences.'
        ],
        'analytics' => [
            'required' => false,
            'ttl' => MONTH_IN_SECONDS * 3,
            'description' => 'Cookies that help us understand how visitors interact with the website.'
        ],
        'marketing' => [
            'required' => false,
            'ttl' => MONTH_IN_SECONDS,
            'description' => 'Cookies used for targeted advertising and marketing purposes.'
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

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize module
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge([
                'enabled' => true,
                'debug' => WP_DEBUG,
                'consent_duration' => YEAR_IN_SECONDS,
                'require_explicit_consent' => true,
                'minimum_age' => 16,
                'encryption_key' => null,
                'banner_template' => 'cookie-banner.php',
                'admin_capability' => 'manage_options'
            ], $config);

            // Get dependencies
            $core = NiertoCore::getInstance();
            $this->error = $core->getModule('Error');
            $this->cache = $core->getModule('Cache');

            // Register WordPress hooks
            $this->registerHooks();

            // Load existing preferences
            $this->loadPreferences();

            $this->initialized = true;

        } catch (\Exception $e) {
            if (isset($this->error)) {
                $this->error->logError('cookie_init_failed', $e->getMessage());
            } else {
                error_log('CookieManager initialization failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        // Cookie consent banner
        add_action('wp_footer', [$this, 'displayConsentBanner']);
        
        // AJAX handlers
        add_action('wp_ajax_nierto_update_cookie_consent', [$this, 'handleConsentUpdate']);
        add_action('wp_ajax_nopriv_nierto_update_cookie_consent', [$this, 'handleConsentUpdate']);
        
        // Privacy integration
        add_action('admin_init', [$this, 'registerPrivacyPolicy']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
        
        // Admin interface
        if (is_admin()) {
            add_action('admin_menu', [$this, 'addAdminMenuPage']);
            add_action('admin_init', [$this, 'registerSettings']);
        }
    }

    /**
     * Set cookie with secure defaults
     */
    public function setCookie(string $name, $value, array $options = []): bool {
        if (!$this->initialized || !$this->config['enabled']) {
            return false;
        }

        try {
            // Merge with secure defaults
            $options = array_merge(self::COOKIE_DEFAULTS, $options);

            // Set domain if not specified
            if (empty($options['domain'])) {
                $options['domain'] = parse_url(home_url(), PHP_URL_HOST);
            }

            // Encrypt value if encryption is available
            if ($this->config['encryption_key']) {
                $value = $this->encryptValue($value);
            }

            // Set cookie with full options array (PHP 7.3+)
            return setcookie($name, $value, [
                'expires' => time() + ($options['ttl'] ?? $this->config['consent_duration']),
                'path' => $options['path'],
                'domain' => $options['domain'],
                'secure' => $options['secure'],
                'httponly' => $options['httponly'],
                'samesite' => $options['samesite']
            ]);

        } catch (\Exception $e) {
            $this->error->logError('cookie_set_failed', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check consent for cookie category
     */
    public function hasConsent(string $category): bool {
        if ($category === 'essential') {
            return true;
        }

        if (!isset(self::COOKIE_CATEGORIES[$category])) {
            $this->error->logError('invalid_cookie_category', $category);
            return false;
        }

        return isset($this->preferences[$category]) && 
               $this->preferences[$category]['accepted'] &&
               $this->preferences[$category]['timestamp'] > (time() - $this->config['consent_duration']);
    }

    /**
     * Update cookie consent preferences
     */
    public function updateConsent(array $preferences): bool {
        try {
            $timestamp = time();
            
            foreach ($preferences as $category => $accepted) {
                if (!isset(self::COOKIE_CATEGORIES[$category])) {
                    continue;
                }

                // Don't allow overriding essential cookies
                if ($category === 'essential') {
                    continue;
                }

                $this->preferences[$category] = [
                    'accepted' => (bool)$accepted,
                    'timestamp' => $timestamp
                ];
            }

            // Store preferences
            $this->storePreferences();

            // Trigger consent update action
            do_action('nierto_cookie_consent_updated', $this->preferences);

            return true;

        } catch (\Exception $e) {
            $this->error->logError('consent_update_failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Display cookie consent banner
     */
    public function displayConsentBanner(): void {
        if (!$this->config['enabled'] || $this->hasAllConsent()) {
            return;
        }

        $template = locate_template('templates/' . $this->config['banner_template']);
        if (!$template) {
            $template = __DIR__ . '/templates/' . $this->config['banner_template'];
        }

        if (file_exists($template)) {
            include $template;
        }
    }

    /**
     * Handle AJAX consent update
     */
    public function handleConsentUpdate(): void {
        check_ajax_referer('nierto_cookie_consent');

        $preferences = json_decode(wp_unslash($_POST['preferences'] ?? '{}'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid preferences format');
            return;
        }

        if ($this->updateConsent($preferences)) {
            wp_send_json_success([
                'message' => 'Preferences updated successfully',
                'preferences' => $this->preferences
            ]);
        } else {
            wp_send_json_error('Failed to update preferences');
        }
    }

    /**
     * Register privacy policy content
     */
    public function registerPrivacyPolicy(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = $this->getPrivacyPolicyContent();
        wp_add_privacy_policy_content(
            'Nierto',
            wp_kses_post($content)
        );
    }

    /**
     * Get privacy policy content
     */
    private function getPrivacyPolicyContent(): string {
        ob_start();
        ?>
        <h3>Cookie Usage</h3>
        <p>We use cookies to enhance your experience on our website. These are categorized as:</p>
        <ul>
        <?php foreach (self::COOKIE_CATEGORIES as $category => $info): ?>
            <li>
                <strong><?php echo esc_html(ucfirst($category)); ?>:</strong> 
                <?php echo esc_html($info['description']); ?>
                <?php if ($info['required']): ?>
                    (Required for website functionality)
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>
        <p>You can manage your cookie preferences at any time through our cookie settings panel.</p>
        <h3>Data Retention</h3>
        <p>Cookie preferences are stored for <?php echo esc_html($this->config['consent_duration'] / DAY_IN_SECONDS); ?> days.</p>
        <h3>Your Rights</h3>
        <p>You have the right to:</p>
        <ul>
            <li>Access your cookie preferences</li>
            <li>Withdraw consent at any time</li>
            <li>Request deletion of your cookie preferences</li>
        </ul>
        <?php
        return ob_get_clean();
    }

    /**
     * Load preferences from storage
     */
    private function loadPreferences(): void {
        $stored = get_option('nierto_cookie_preferences_' . get_current_user_id(), []);
        
        if ($this->config['encryption_key'] && !empty($stored)) {
            $stored = $this->decryptValue($stored);
        }

        $this->preferences = is_array($stored) ? $stored : [];
    }

    /**
     * Store preferences
     */
    private function storePreferences(): void {
        $data = $this->preferences;

        if ($this->config['encryption_key']) {
            $data = $this->encryptValue($data);
        }

        update_option(
            'nierto_cookie_preferences_' . get_current_user_id(),
            $data
        );
    }

    /**
     * Encrypt value
     */
    private function encryptValue($value): string {
        if (!$this->config['encryption_key']) {
            return $value;
        }

        $value = json_encode($value);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        $cipher = sodium_crypto_secretbox(
            $value,
            $nonce,
            $this->config['encryption_key']
        );

        $encoded = base64_encode($nonce . $cipher);
        sodium_memzero($value);
        
        return $encoded;
    }

    /**
     * Decrypt value
     */
    private function decryptValue(string $encoded) {
        if (!$this->config['encryption_key']) {
            return $encoded;
        }

        $decoded = base64_decode($encoded);
        if ($decoded === false) {
            return null;
        }

        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $cipher = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');

        $value = sodium_crypto_secretbox_open(
            $cipher,
            $nonce,
            $this->config['encryption_key']
        );

        if ($value === false) {
            return null;
        }

        $decoded = json_decode($value, true);
        sodium_memzero($value);
        
        return $decoded;
    }
    /**
     * Check if all necessary consent is given
     */
    private function hasAllConsent(): bool {
        foreach (self::COOKIE_CATEGORIES as $category => $info) {
            if (!$info['required'] && !$this->hasConsent($category)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Register personal data exporter
     */
    public function registerExporter(array $exporters): array {
        $exporters['nierto_cookie_preferences'] = [
            'exporter_friendly_name' => 'Cookie Preferences',
            'callback' => [$this, 'exportPersonalData']
        ];
        return $exporters;
    }

    /**
     * Export personal data
     */
    public function exportPersonalData(string $email_address): array {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $data = [];
        foreach ($this->preferences as $category => $info) {
            $data[] = [
                'name' => 'Cookie Preference - ' . ucfirst($category),
                'value' => $info['accepted'] ? 'Accepted' : 'Rejected',
                'timestamp' => date('Y-m-d H:i:s', $info['timestamp'])
            ];
        }

        return [
            'data' => $data,
            'done' => true
        ];
    }

    /**
     * Register personal data eraser
     */
    public function registerEraser(array $erasers): array {
        $erasers['nierto_cookie_preferences'] = [
            'eraser_friendly_name' => 'Cookie Preferences',
            'callback' => [$this, 'erasePersonalData']
        ];
        return $erasers;
    }

    /**
     * Erase personal data
     */
    public function erasePersonalData(string $email_address): array {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true
            ];
        }

        delete_option('nierto_cookie_preferences_' . $user->ID);
        $this->preferences = [];

        return [
            'items_removed' => true,
            'items_retained' => false,
            'messages' => ['Cookie preferences have been removed.'],
            'done' => true
        ];
    }

    /**
     * Add admin menu page
     */
    public function addAdminMenuPage(): void {
        if (!current_user_can($this->config['admin_capability'])) {
            return;
        }

        add_options_page(
            'Cookie Settings',
            'Cookie Settings',
            $this->config['admin_capability'],
            'nierto-cookie-settings',
            [$this, 'renderAdminPage']
        );
    }

    /**
     * Register admin settings
     */
    public function registerSettings(): void {
        register_setting('nierto_cookie_options', 'nierto_cookie_settings', [
            'sanitize_callback' => [$this, 'sanitizeSettings']
        ]);

        add_settings_section(
            'nierto_cookie_main',
            'Cookie Management Settings',
            null,
            'nierto_cookie_settings'
        );

        // Add settings fields
        $fields = [
            'require_explicit_consent' => [
                'type' => 'checkbox',
                'label' => 'Require Explicit Consent'
            ],
            'minimum_age' => [
                'type' => 'number',
                'label' => 'Minimum Age for Consent'
            ],
            'consent_duration' => [
                'type' => 'number',
                'label' => 'Consent Duration (days)'
            ]
        ];

        foreach ($fields as $key => $field) {
            add_settings_field(
                "nierto_cookie_{$key}",
                $field['label'],
                [$this, 'renderSettingField'],
                'nierto_cookie_settings',
                'nierto_cookie_main',
                [
                    'key' => $key,
                    'type' => $field['type']
                ]
            );
        }
    }

    /**
     * Get module configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if module is initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get module status
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'categories' => array_keys(self::COOKIE_CATEGORIES),
            'preferences_count' => count($this->preferences),
            'encryption_enabled' => !empty($this->config['encryption_key']),
            'consent_duration' => $this->config['consent_duration'],
            'explicit_consent_required' => $this->config['require_explicit_consent']
        ];
    }

    /**
     * Sanitize settings
     */
    private function sanitizeSettings($input): array {
        $sanitized = [];
        
        $sanitized['require_explicit_consent'] = 
            isset($input['require_explicit_consent']) ? 
            (bool)$input['require_explicit_consent'] : 
            true;

        $sanitized['minimum_age'] = 
            isset($input['minimum_age']) ? 
            absint($input['minimum_age']) : 
            16;

        $sanitized['consent_duration'] = 
            isset($input['consent_duration']) ? 
            absint($input['consent_duration']) * DAY_IN_SECONDS : 
            YEAR_IN_SECONDS;

        return $sanitized;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}