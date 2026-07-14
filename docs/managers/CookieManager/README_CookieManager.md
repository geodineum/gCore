# CookieManager

**Version:** 2.0.0
**Namespace:** `gCore\Modules\Managers\Base\CookieManager`
**Implements:** `ModuleInterface`

## Overview

CookieManager provides GDPR-compliant cookie management with consent tracking and privacy controls. It is framework-agnostic with conditional WordPress integration for privacy tools.

### Key Features

- **GDPR Compliance** - Explicit consent, right to be forgotten, data portability
- **Cookie Categories** - Essential, Functional, Analytics, Marketing
- **Encryption Support** - AES-256-CBC cookie value encryption
- **Multi-tenant Isolation** - Cookie namespacing via site_id/node_id
- **WordPress Privacy Tools** - Integrates with WP data exporters and erasers
- **Consent Banner** - Built-in consent UI (WordPress)

## Architecture

CookieManager manages cookies through a category-based consent system:

1. **Essential Cookies**: Always allowed, required for functionality
2. **Functional Cookies**: Enhanced features, requires consent
3. **Analytics Cookies**: Visitor tracking, requires consent
4. **Marketing Cookies**: Advertising, requires consent

### Capability Vector

```php
[
    'gdpr' => 1.0,
    'privacy' => 0.95,
    'cookies' => 1.0,
    'security' => 0.3,
    'consent' => 1.0
]
```

## Cookie Categories

| Category | Required | Default TTL | Description |
|----------|----------|-------------|-------------|
| `essential` | Yes | 1 year | Basic functionality cookies |
| `functional` | No | 6 months | Enhanced features and preferences |
| `analytics` | No | 3 months | Visitor interaction tracking |
| `marketing` | No | 1 month | Targeted advertising |

## Installation & Initialization

### Via gCore (Recommended)

```php
$core = gCore::getInstance();
$cookieManager = $core->getService('CookieManager');
```

### Direct Initialization

```php
$cookieManager = CookieManager::getInstance();
$cookieManager->initialize([
    'enabled' => true,
    'require_explicit_consent' => true,
    'minimum_age' => 16,
    'encryption_key' => 'your-secret-key',
    'site_id' => 'mysite',
    'node_id' => 'node1'
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `enabled` | bool | true | Enable cookie management |
| `debug` | bool | WP_DEBUG | Enable debug logging |
| `consent_duration` | int | YEAR_IN_SECONDS | How long consent is stored |
| `require_explicit_consent` | bool | true | Require explicit opt-in |
| `minimum_age` | int | 16 | Minimum age for consent (GDPR) |
| `encryption_key` | string | null | Key for cookie encryption |
| `admin_capability` | string | 'manage_options' | WordPress admin capability |
| `site_id` | string | 'default' | Multi-tenant site identifier |
| `node_id` | string | 'node1' | Multi-tenant node identifier |

## Public API

### Cookie Operations

#### `setCookie(string $name, $value, array $options = []): bool`

Set a cookie with security options.

```php
// Basic cookie
$cookieManager->setCookie('user_pref', 'dark_mode');

// With custom options
$cookieManager->setCookie('session', $token, [
    'expires' => time() + 3600,
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Strict'
]);
```

**Default Security Options:**
- `secure`: true
- `httponly`: true
- `samesite`: 'Strict'
- `path`: '/'

#### `getCookie(string $name, $default = null): mixed`

Retrieve a cookie value (decrypted if encryption is enabled).

```php
$preference = $cookieManager->getCookie('user_pref', 'default_value');
```

#### `deleteCookie(string $name): bool`

Delete a cookie and its tracking data.

```php
$cookieManager->deleteCookie('session_token');
```

### Cookie Expiry Management

#### `getCookieExpiry(string $name): ?array`

Get expiry information for a tracked cookie.

```php
$expiry = $cookieManager->getCookieExpiry('user_session');
// Returns: ['expires' => 1704067200, 'remaining' => 3600, 'created' => 1704063600]
```

#### `extendCookieExpiry(string $name, int $additionalTime): bool`

Extend a cookie's expiry time.

```php
// Extend session by 1 hour
$cookieManager->extendCookieExpiry('user_session', 3600);
```

#### `refreshCookie(string $name): bool`

Refresh a cookie with its original TTL.

```php
// Reset expiry to original duration
$cookieManager->refreshCookie('user_session');
```

#### `getCookiesExpiringSoon(int $withinSeconds = 86400): array`

Get list of cookies expiring within the specified time window.

```php
// Find cookies expiring in the next hour
$expiring = $cookieManager->getCookiesExpiringSoon(3600);
// Returns: [['name' => 'session', 'expires' => 1704067200, 'remaining' => 1800], ...]
```

### Consent Management

#### `hasConsent(string $category): bool`

Check if user has consented to a cookie category.

```php
if ($cookieManager->hasConsent('analytics')) {
    // Track page view
    trackAnalytics();
}

// Essential cookies always return true
$hasEssential = $cookieManager->hasConsent('essential'); // always true
```

#### `updateConsent(array $preferences): bool`

Update user's consent preferences.

```php
$cookieManager->updateConsent([
    'functional' => true,
    'analytics' => true,
    'marketing' => false
]);
```

### WordPress-Specific Methods

#### `displayConsentBanner(): void`

Display the consent banner (hooked to `wp_footer`).

```php
// Automatically called via WordPress hook
// Or manually:
$cookieManager->displayConsentBanner();
```

#### `handleConsentUpdate(): void`

Process consent form submission (hooked to `init`).

#### `registerPrivacyPolicy(): void`

Register privacy policy content with WordPress.

#### `registerExporter(array $exporters): array`

Register GDPR data exporter (filter: `wp_privacy_personal_data_exporters`).

#### `registerEraser(array $erasers): array`

Register GDPR data eraser (filter: `wp_privacy_personal_data_erasers`).

#### `exportPersonalData(string $email_address): array`

Export user's cookie consent data.

```php
$data = $cookieManager->exportPersonalData('user@example.com');
// Returns consent preferences for GDPR data export
```

#### `erasePersonalData(string $email_address): array`

Erase user's cookie consent data.

```php
$result = $cookieManager->erasePersonalData('user@example.com');
// Clears all consent preferences
```

### WordPress Admin Interface

#### `renderAdminPage(): void`

Renders the Cookie Settings admin page under the gCore menu. Provides:
- Cookie category configuration
- Consent duration settings
- Current consent preferences display
- Cookie statistics (total, expiring soon)
- Quick actions for refreshing/deleting cookies

The admin page is automatically registered under **gCore → Cookie Settings** in WordPress admin.

### Module Interface Methods

#### `getInstance(): ModuleInterface`

Get singleton instance.

#### `initialize(array $config = []): void`

Initialize with configuration.

#### `isInitialized(): bool`

Check initialization status.

#### `getConfig(): array`

Get current configuration.

#### `updateConfig(array $config): void`

Update configuration at runtime.

#### `getStatus(): array`

Get status information.

```php
$status = $cookieManager->getStatus();
// Returns: initialized, preferences, categories, site_id, node_id, framework
```

## Usage Examples

### Basic Consent Flow

```php
$cookies = CookieManager::getInstance();
$cookies->initialize(['site_id' => 'shop']);

// Check consent before tracking
if ($cookies->hasConsent('analytics')) {
    // Safe to set analytics cookies
    $cookies->setCookie('_ga_session', $sessionId);
}

// User updates preferences
$cookies->updateConsent([
    'analytics' => true,
    'marketing' => false
]);
```

### Encrypted Cookies

```php
$cookies = CookieManager::getInstance();
$cookies->initialize([
    'encryption_key' => 'your-32-character-secret-key-here'
]);

// Value is automatically encrypted with AES-256-CBC
$cookies->setCookie('sensitive_data', ['user_id' => 123]);
```

### GDPR Data Export

```php
// In WordPress context
add_filter('wp_privacy_personal_data_exporters', function($exporters) {
    $cookieManager = CookieManager::getInstance();
    return $cookieManager->registerExporter($exporters);
});
```

### Multi-Tenant Cookie Isolation

```php
// Site A cookies
$cookiesA = CookieManager::getInstance();
$cookiesA->initialize(['site_id' => 'site_a']);
$cookiesA->setCookie('pref', 'value');
// Cookie name: site_a_node1_pref

// Site B cookies (different namespace)
// Cookie name: site_b_node1_pref
```

## Integration Points

### Dependencies

| Manager | Relationship | Purpose |
|---------|--------------|---------|
| **CacheManager** | Required | Consent preference storage |
| **ErrorManager** | Required | Error logging |
| **gCore** | Parent | Service discovery |

### WordPress Hooks

CookieManager registers the following hooks in WordPress:

| Hook | Callback | Priority | Purpose |
|------|----------|----------|---------|
| `init` | `handleConsentUpdate` | 10 | Process consent forms |
| `wp_footer` | `displayConsentBanner` | 999 | Show consent UI |
| `admin_menu` | `addAdminMenuPage` | 10 | Admin settings page |
| `admin_init` | `registerSettings` | 10 | Register WP settings |
| `admin_init` | `registerPrivacyPolicy` | 10 | Privacy policy content |

### WordPress Privacy Filters

| Filter | Callback | Purpose |
|--------|----------|---------|
| `wp_privacy_personal_data_exporters` | `registerExporter` | GDPR export |
| `wp_privacy_personal_data_erasers` | `registerEraser` | GDPR erasure |

### WordPress Action

```php
// Triggered when consent is updated
do_action('gCore_cookie_consent_updated', $preferences);
```

### gNode Integration

CookieManager registers with gNode for capability-based service discovery:

```php
$this->gNodeClient->registerService(
    'CookieManager',
    $this->capabilityVector,
    [
        'type' => 'manager',
        'tier' => 'TOOL',
        'priority' => '400'
    ]
);
```

## Key Namespacing

Cookies are namespaced using the pattern:
```
{site_id}_{node_id}_{cookie_name}
```

Consent preferences are cached with key:
```
cookie_preferences_{site_id}_{node_id}
```

## Security Features

### Cookie Security Defaults

```php
const COOKIE_DEFAULTS = [
    'secure' => true,      // HTTPS only
    'httponly' => true,    // No JavaScript access
    'samesite' => 'Strict', // CSRF protection
    'path' => '/',
    'domain' => ''
];
```

### Encryption

When `encryption_key` is configured:
- Values serialized and encrypted with AES-256-CBC
- Random IV generated per encryption
- Base64 encoded for storage

```php
// Encryption process
$iv = random_bytes(16);
$encrypted = openssl_encrypt($serialized, 'AES-256-CBC', $key, 0, $iv);
return base64_encode($iv . $encrypted);
```

## Error Handling

```php
try {
    $cookieManager->initialize($config);
} catch (\RuntimeException $e) {
    // Required dependencies (ErrorManager, CacheManager) not available
    error_log('CookieManager init failed: ' . $e->getMessage());
}
```

## Best Practices

1. **Always check consent**: Use `hasConsent()` before setting non-essential cookies
2. **Use encryption**: Enable for sensitive cookie data
3. **Respect user choices**: Don't set cookies without proper consent
4. **Implement data export**: Support GDPR data portability
5. **Clear on erasure**: Implement proper data erasure
6. **Multi-tenant isolation**: Configure site_id/node_id properly

## Troubleshooting

### Consent Not Persisting

1. Verify CacheManager is available
2. Check ValKey connection
3. Verify `consent_duration` setting

### Cookies Not Setting

1. Check if headers already sent
2. Verify HTTPS for secure cookies
3. Check domain and path settings

### Encryption Issues

1. Verify `encryption_key` is set
2. Ensure key is consistent across requests
3. Check OpenSSL extension is available
