# WordPressManager

Database cloning, PII scrubbing, and environment management for WordPress sites.

## Overview

WordPressManager handles database cloning between DTAP environments and PII anonymization for non-production copies. It operates directly on `$wpdb` and MySQL -- no gNode dependency. Production data is never modified by scrub operations; you must first clone to a staging database and swap to it before scrubbing.

## Access

```php
$manager = $gCore->getService('WordPressManager');
```

## Methods

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `cloneDatabase` | `(string $target_env, bool $drop_existing = false)` | `array` | Clones production DB to `{base}_{env}_db`. Returns `cloned`, `tables`, `errors`. |
| `swapDatabase` | `(string $target_db)` | `array` | Rewrites `DB_NAME` in `wp-config.php`. Stores original DB name on first swap. |
| `getProductionDbName` | `()` | `string` | Returns the original production DB name (from `wp_options` or current `DB_NAME`). |
| `getEnvironmentDbName` | `(string $environment)` | `string` | Derives environment DB name: `{base}_{env}_db`. |
| `scrubPII` | `(bool $confirm = false, array $options = [])` | `array` | Anonymizes users, usermeta, WooCommerce fields, and comments. Refuses to run on production. |
| `getScrubPreview` | `()` | `array` | Counts affected rows without modifying data. |
| `isScrubSafe` | `()` | `bool` | Returns `true` if current environment is not production. |
| `getEnvironmentInfo` | `()` | `array` | Returns WP version, multisite status, site URL, DB name, detected environment. |
| `getStatus` | `()` | `array` | Module status including environment, scrub safety, and DB names. |

## Configuration

```php
$config = [
    'preserve_admin'    => true,   // Exclude user ID 1 from scrubbing
    'scrub_comments'    => true,   // Anonymize wp_comments
    'scrub_woocommerce' => true,   // Anonymize WooCommerce billing/shipping meta
];
```

## Typical Workflow

```php
$wp = $gCore->getService('WordPressManager');

// 1. Clone production to staging
$result = $wp->cloneDatabase('staging');

// 2. Switch wp-config.php to the clone
$wp->swapDatabase($result['target_db']);

// 3. Scrub PII on the clone (never touches production)
$wp->scrubPII(true);
```

## Scrubbed Fields

- **wp_users**: `user_email`, `display_name`, `user_nicename`
- **wp_usermeta**: `first_name`, `last_name`, `nickname`, `description`
- **WooCommerce**: 18 billing/shipping meta keys (address, email, phone)
- **wp_comments**: `comment_author`, `comment_author_email`, `comment_author_url`, `comment_author_IP`

## Status

Base tier -- included in core framework.
