# CommsManager

Admin dashboard and configuration for the Geodineum-COMMS notification daemon.

## Overview

CommsManager provides PHP-side management of notification dispatch settings stored in ValKey. It reads and writes the same keys the Rust-based Geodineum-COMMS daemon consumes. Supports multi-tenant isolation: regular users see their own site, super admins (`manage_gnode_comms` capability) see all sites. Channels: email (SMTP), Telegram, SMS (Twilio).

## Access

```php
$manager = $gCore->getService('CommsManager');
```

## Methods

### Access Control

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `getCurrentSiteId` | `()` | `string` | Domain converted to site_id format (dots to underscores). |
| `isSuperAdmin` | `()` | `bool` | Checks `manage_gnode_comms` cap, multisite super admin, or `GNODE_COMMS_SUPER_ADMINS` constant. |
| `canAccessSite` | `(string $siteId)` | `bool` | Super admins: any site. Others: own site only. |
| `getAccessibleSites` | `()` | `array` | List of site IDs the current user can manage. |

### Site Settings

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `getSiteSettings` | `(string $siteId)` | `?array` | Read channel configs, routing rules, rate limits from ValKey. |
| `saveSiteSettings` | `(string $siteId, array $settings)` | `bool` | Write settings to `{site_id}:comms:config`. |
| `deleteSiteSettings` | `(string $siteId)` | `bool` | Super admin only. |
| `listConfiguredSites` | `()` | `array` | All site IDs with comms config in ValKey. |
| `createDefaultSettings` | `(string $siteId)` | `array` | Creates default config with all channels disabled. |

### Message History

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `getRecentMessages` | `(string $siteId, string $env = 'production', int $count = 50)` | `array` | Reads from ValKey stream via `XREVRANGE`. |
| `getAllRecentMessages` | `(string $env = 'production', int $countPerSite = 20)` | `array` | Aggregates messages across all accessible sites. Super admin only. |
| `getMessage` | `(string $siteId, string $messageId, string $env = 'production')` | `?array` | Fetch a single message by ID. |

### Statistics & Testing

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `getStats` | `(string $siteId, string $env = 'production')` | `array` | Message counts by status and channel. |
| `getGlobalStats` | `(string $env = 'production')` | `array` | Aggregate stats across all accessible sites. |
| `testChannel` | `(string $siteId, string $channel)` | `array` | Validates channel configuration (email/telegram/sms). |
| `getDaemonStatus` | `(string $siteId, string $env = 'production')` | `array` | Checks if COMMS daemon consumer group is active. |

## Status

Extension tier. Base: stub implementation included (returns empty data, no daemon integration). Full version requires an external extension package.
