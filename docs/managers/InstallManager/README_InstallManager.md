# InstallManager

## Overview

InstallManager handles gCore/gCube extension installation, file integrity verification, warranty tracking, and .htaccess security management. It communicates with the geodineum.com central registry for extension downloads, hash verification, and tampering reports.

**Key Responsibilities:**
- Install/update gCore extensions from geodineum.com registry
- Verify file integrity against central hash registry
- Track warranty status and detect tampering
- Manage .htaccess security rules (firewall, IP blocking)
- First-run environment setup
- Backup and restore operations

## Architecture

```
InstallManager
    |
    +-- Extension Management
    |       +-- Download from geodineum.com
    |       +-- Hash verification
    |       +-- Installation/update
    |
    +-- Integrity Verification
    |       +-- Hash registry sync
    |       +-- File verification
    |       +-- Tampering detection
    |       +-- Warranty tracking
    |
    +-- htaccess Management
    |       +-- Security rules
    |       +-- IP blocking
    |       +-- Directory protection
    |
    +-- Backup Management
            +-- Create/restore backups
            +-- Retention cleanup
```

## Initialization

```php
$gCore = \gCore\Modules\Core\gCore::getInstance();
$install = $gCore->getService('InstallManager');

// Or with custom configuration
$install->initialize([
    'installation_base_path' => '/var/www/myapp',
    'site_id' => 'my-site',
    'node_id' => 'node1',
    'license_key' => 'XXXX-XXXX-XXXX',
    'auto_verify' => true,
    'verify_interval' => 86400, // Daily
    'htaccess_path' => '/var/www/myapp/.htaccess'
]);
```

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `installation_base_path` | string | `getcwd()` | Base installation path |
| `site_id` | string | `default` | Multi-tenant site identifier |
| `node_id` | string | `node1` | Node identifier |
| `license_key` | string | `null` | Geodineum license key |
| `debug` | bool | `false` | Enable debug logging |
| `auto_verify` | bool | `true` | Auto-verify on init |
| `verify_interval` | int | `86400` | Verification interval (seconds) |
| `htaccess_path` | string | auto | Path to .htaccess file |
| `permissions.files` | int | `0644` | File permissions |
| `permissions.directories` | int | `0755` | Directory permissions |

## Public API

### Extension Management

#### getAvailableExtensions(?string $type = null)

Lists available extensions from geodineum.com registry.

```php
$extensions = $install->getAvailableExtensions('manager');
// [
//     ['id' => 'translate-manager', 'version' => '1.2.0', ...],
//     ['id' => 'analytics-manager', 'version' => '2.0.0', ...]
// ]

// Filter by type: 'manager', 'theme', 'plugin'
$themes = $install->getAvailableExtensions('theme');
```

#### installExtension(string $extensionId, ?string $licenseKey = null)

Installs an extension from the registry.

```php
$result = $install->installExtension('translate-manager', 'LICENSE-KEY');
// [
//     'success' => true,
//     'extension_id' => 'translate-manager',
//     'version' => '1.2.0',
//     'path' => '/path/to/extension'
// ]

// On failure:
// [
//     'success' => false,
//     'error' => 'Invalid or expired license key',
//     'extension_id' => 'translate-manager'
// ]
```

#### updateExtension(string $extensionId)

Updates an installed extension (creates backup first).

```php
$result = $install->updateExtension('translate-manager');
```

#### getInstalledExtensions()

Returns all installed extensions with metadata.

```php
$installed = $install->getInstalledExtensions();
// [
//     'translate-manager' => [
//         'version' => '1.2.0',
//         'installed_at' => 1704067200,
//         'license_key' => 'XXXX...',
//         'path' => '/path/to/extension'
//     ]
// ]
```

### Integrity Verification

#### verifyIntegrity(bool $force = false)

Verifies system integrity against geodineum.com hash registry.

```php
$result = $install->verifyIntegrity(true); // Force check
// [
//     'status' => 'VALID',      // VALID, MODIFIED, TAMPERED, ERROR
//     'violations' => [],
//     'files_checked' => 150,
//     'duration' => 0.523,
//     'cached' => false
// ]
```

#### getWarrantyInfo()

Returns current warranty status.

```php
$warranty = $install->getWarrantyInfo();
// [
//     'status' => 'VALID',
//     'last_verified' => 1704067200,
//     'violations' => 0,
//     'modified_files' => [],
//     'site_id' => 'my-site',
//     'extensions' => ['translate-manager', 'analytics-manager']
// ]
```

#### validateLicense(string $licenseKey, ?string $product = null)

Validates a license key with geodineum.com.

```php
$valid = $install->validateLicense('XXXX-XXXX-XXXX', 'translate-manager');
// true or false
```

### Environment Setup

#### setupEnvironment()

First-run environment setup (directories, htaccess).

```php
$result = $install->setupEnvironment();
// [
//     'directories' => [
//         '/logs' => true,
//         '/cache' => true,
//         '/temp' => true,
//         '/backups' => true
//     ],
//     'htaccess' => true,
//     'permissions' => [...]
// ]
```

#### validateEnvironment()

Validates PHP and system requirements.

```php
$result = $install->validateEnvironment();
// [
//     'passed' => true,
//     'requirements' => [
//         'php_version' => ['required' => '7.4.0', 'current' => '8.1.0', 'passed' => true],
//         'memory_limit' => ['required' => '64M', 'current' => '256M', 'passed' => true],
//         'curl' => ['required' => true, 'current' => true, 'passed' => true],
//         'json' => ['required' => true, 'current' => true, 'passed' => true],
//         'writable_base' => ['required' => true, 'current' => true, 'passed' => true]
//     ]
// ]
```

### htaccess Management

#### setupHtaccess()

Installs gCore security rules into .htaccess.

```php
$success = $install->setupHtaccess();
```

Security rules installed:
- Protect hidden files (`.htaccess`, `.env`)
- Block backup files (`*.bak`, `*.swp`)
- Security headers (X-Content-Type-Options, X-Frame-Options, etc.)
- Disable directory browsing
- Protect gCore directories (logs, cache, temp, backups)

#### blockIP(string $ip, string $reason = '', ?int $duration = null)

Blocks an IP address at the server level.

```php
// Permanent block
$install->blockIP('192.168.1.100', 'Brute force attempt');

// Temporary block (1 hour)
$install->blockIP('192.168.1.100', 'Rate limit exceeded', 3600);
```

#### unblockIP(string $ip)

Removes IP from block list.

```php
$install->unblockIP('192.168.1.100');
```

#### getBlockedIPs()

Lists all blocked IPs with metadata.

```php
$blocked = $install->getBlockedIPs();
// [
//     [
//         'ip' => '192.168.1.100',
//         'blocked_at' => '2024-01-01 12:00:00',
//         'reason' => 'Brute force attempt',
//         'expires' => 'permanent'
//     ]
// ]
```

#### cleanExpiredBlocks()

Removes expired IP blocks (for cron jobs).

```php
$removed = $install->cleanExpiredBlocks();
// Returns count of removed blocks
```

#### addHtaccessRule(string $rule, string $section = 'Custom')

Adds custom rule to .htaccess.

```php
$install->addHtaccessRule('RewriteRule ^old-page$ /new-page [R=301,L]', 'Redirects');
```

### Backup Management

#### createBackup(string $name, string $path)

Creates a backup of a file or directory.

```php
$backupPath = $install->createBackup('my-extension', '/path/to/extension');
// Returns: /backups/my-extension-2024-01-01-120000
```

#### restoreBackup(string $backupPath, string $targetPath)

Restores from a backup.

```php
$success = $install->restoreBackup(
    '/backups/my-extension-2024-01-01-120000',
    '/path/to/extension'
);
```

#### cleanOldBackups(int $retentionDays = 30)

Removes backups older than retention period.

```php
$removed = $install->cleanOldBackups(7); // Keep 7 days
```

### Status

#### getStatus()

Returns manager status.

```php
$status = $install->getStatus();
// [
//     'initialized' => true,
//     'warranty_status' => 'VALID',
//     'last_verification' => 1704067200,
//     'violations' => 0,
//     'installed_extensions' => 2,
//     'site_id' => 'my-site',
//     'api_endpoint' => 'https://api.geodineum.com/v1'
// ]
```

## Geodineum.com API Integration

InstallManager communicates with geodineum.com for:

| Endpoint | Purpose |
|----------|---------|
| `/integrity/hashes` | Hash registry for installed files |
| `/extensions` | Extension catalog and downloads |
| `/integrity/report` | Tampering reports |
| `/license/validate` | License validation |

All requests include:
- `site_id`: Multi-tenant identifier
- `site_url`: Site URL
- `gcore_version`: Framework version
- `php_version`: PHP version

## Warranty Status

| Status | Description |
|--------|-------------|
| `VALID` | All files match hash registry |
| `MODIFIED` | Non-critical files modified |
| `TAMPERED` | Critical files modified (warranty voided) |
| `UNVERIFIED` | Not yet verified |
| `ERROR` | Verification failed |

## htaccess Security Rules

Default security rules installed:

```apache
# BEGIN gCore Security
# Protect sensitive files
<FilesMatch "^\.">
    Deny from all
</FilesMatch>

# Protect configuration files
<FilesMatch "(wp-config\.php|\.env|composer\.json)$">
    Deny from all
</FilesMatch>

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Disable directory browsing
Options -Indexes

# Protect gCore directories
RewriteRule ^(logs|cache|temp|backups)/ - [F,L]
# END gCore Security
```

## Required Directories

InstallManager creates and protects these directories:

| Directory | Purpose |
|-----------|---------|
| `/logs` | Error and debug logs |
| `/cache` | Cache storage |
| `/temp` | Temporary files |
| `/backups` | Backup storage |

Each directory includes a `.htaccess` file denying web access.

## Troubleshooting

### Extension installation fails

1. Verify network connectivity to api.geodineum.com
2. Check license key validity
3. Ensure write permissions on installation path
4. Check PHP zip extension is loaded

### Integrity verification errors

1. Check network connectivity
2. Verify cached hash registry isn't stale
3. Run with `force = true` to bypass cache
4. Check file permissions allow reading

### htaccess rules not working

1. Verify Apache mod_rewrite is enabled
2. Check AllowOverride is set to All
3. Verify .htaccess file permissions (644)
4. Check Apache error logs

### IP blocking not working

1. Verify mod_authz_host is enabled
2. Check .htaccess syntax
3. Verify IP format is valid

## Dependencies

- **gCore\Modules\Core\Interfaces\ModuleInterface**: Singleton pattern
- **gCore\Modules\Core\gCore**: Manager resolution
- **ErrorManager** (optional): Logging
- **CacheManager** (optional): State persistence
- **curl**: API communication
- **ZipArchive**: Package extraction

## Security Considerations

- All API communication uses HTTPS
- Hash verification uses SHA-256
- License keys partially redacted in logs
- File permissions enforced on created files
- htaccess protected with deny rules

## Related Managers

- **SecurityManager**: Uses IP blocking for firewall
- **CacheManager**: State persistence
- **ErrorManager**: Error logging and broadcasting
