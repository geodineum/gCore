# SecretsManager

## Overview

SecretsManager provides centralized secrets and credential management for gCore applications. It supports multiple storage backends with automatic fallback, access control based on privilege levels, and type-specific validation.

**Key Features:**
- Multi-backend support (ENV, File, ValKey, HSM)
- Fallback chain for resilient secret retrieval
- Role-based access control (system, admin, public)
- Type-specific secret validation
- In-memory caching for performance
- Dot-notation key paths for hierarchical organization

## Architecture

### Storage Backends

| Backend | Priority | Use Case | Read/Write |
|---------|----------|----------|------------|
| `BACKEND_ENV` | 1 (highest) | Environment variables | Read-only |
| `BACKEND_FILE` | 2 | config/secrets.json | Read/Write |
| `BACKEND_VALKEY` | 3 | ValKey/Redis storage | Read/Write |
| `BACKEND_HSM` | 4 | Hardware Security Module | Read-only |

### Retrieval Fallback Chain

```
getSecret('cache.auth')
    │
    ├─► Check access level ──► Denied? ──► SecurityException
    │
    ├─► Check in-memory cache ──► Hit? ──► Return cached value
    │
    ├─► Check ENV (VALKEY_AUTH) ──► Found? ──► Cache & return
    │
    ├─► Check preferred backend ──► Found? ──► Cache & return
    │
    ├─► Try remaining backends ──► Found? ──► Cache & return
    │
    └─► Not found ──► Required? ──► ValidationException
                  └─► Optional? ──► Return null
```

## API Reference

### getSecret()

Retrieve a secret value with access control and fallback.

```php
public static function getSecret(
    string $key,
    string $accessLevel = 'system',
    string $preferredBackend = self::BACKEND_ENV
): mixed
```

**Parameters:**
- `$key` - Dot-notation secret path (e.g., `cache.auth`)
- `$accessLevel` - Requester's access level (`system`, `admin`, `public`)
- `$preferredBackend` - First backend to try after ENV

**Returns:** Secret value or `null` if not found and not required

**Throws:**
- `SecurityException` - Access denied for this key/level combination
- `ValidationException` - Required secret not found

**Example:**
```php
// Get ValKey password (defaults: system access, env preferred)
$password = SecretsManager::getSecret('cache.auth');

// Get with explicit backend preference
$smtpPass = SecretsManager::getSecret(
    'notifications.smtp.password',
    'system',
    SecretsManager::BACKEND_FILE
);
```

### setSecret()

Store a secret in a specific backend.

```php
public static function setSecret(
    string $key,
    mixed $value,
    string $accessLevel = 'system',
    string $backend = self::BACKEND_VALKEY
): bool
```

**Parameters:**
- `$key` - Dot-notation secret path
- `$value` - Secret value to store
- `$accessLevel` - Requester's access level
- `$backend` - Target storage backend

**Returns:** `true` on success, `false` on failure

**Throws:**
- `SecurityException` - Access denied or attempting ENV write
- `ValidationException` - Value fails type validation

**Example:**
```php
// Store API key in ValKey
SecretsManager::setSecret(
    'api.stripe.secret',
    'sk_live_xxxxxxxxxxxxx',
    'system',
    SecretsManager::BACKEND_VALKEY
);

// Store in file
SecretsManager::setSecret(
    'custom.config.value',
    ['nested' => 'data'],
    'system',
    SecretsManager::BACKEND_FILE
);
```

### deleteSecret()

Remove a secret from one or all backends.

```php
public static function deleteSecret(
    string $key,
    string $accessLevel = 'system',
    ?string $backend = null
): bool
```

**Parameters:**
- `$key` - Dot-notation secret path
- `$accessLevel` - Requester's access level
- `$backend` - Specific backend or `null` for all

**Example:**
```php
// Delete from all backends
SecretsManager::deleteSecret('deprecated.old.key');

// Delete only from ValKey
SecretsManager::deleteSecret('temp.session.key', 'system', SecretsManager::BACKEND_VALKEY);
```

### secretExists()

Check if a secret exists without retrieving it.

```php
public static function secretExists(
    string $key,
    string $accessLevel = 'system',
    ?string $backend = null
): bool
```

**Example:**
```php
if (SecretsManager::secretExists('security.licenses.quantum_resistant')) {
    // Enable quantum-resistant features
}
```

### clearCache()

Clear the in-memory secrets cache.

```php
public static function clearCache(): void
```

**Use Case:** Call after secret rotation to force re-fetch.

```php
// Rotate secrets externally, then:
SecretsManager::clearCache();
$newSecret = SecretsManager::getSecret('rotated.key');
```

## Secret Types

Secrets are validated based on their type, determined by key pattern:

| Type | Validation | Example Keys |
|------|------------|--------------|
| `TYPE_API_KEY` | String, min 16 chars | Custom API integrations |
| `TYPE_PASSWORD` | Non-empty string | `cache.auth`, `*.password` |
| `TYPE_CERTIFICATE` | Non-empty string | `cache.tls.*` |
| `TYPE_PRIVATE_KEY` | String, min 16 chars | `security.encryption.key` |
| `TYPE_TOKEN` | String, min 8 chars | Session tokens |
| `TYPE_CONNECTION` | String or array | `cache.username` |
| `TYPE_LICENSE` | Non-empty string | `security.licenses.*` |

## Access Control

### Access Levels

| Level | Description | Typical Use |
|-------|-------------|-------------|
| `system` | Full access to all secrets | Internal system operations |
| `admin` | Limited sensitive access | Admin panel, configuration |
| `public` | Contact information only | Public-facing features |

### Access Matrix

```php
// System-only (most restrictive)
'security.encryption.key' => ['system']
'security.hardware.pin' => ['system']
'cache.auth' => ['system']
'cache.*.password' => ['system']

// System + Admin
'security.hardware.module_path' => ['system', 'admin']
'security.licenses.*' => ['system', 'admin']
'notifications.smtp.*' => ['system', 'admin']

// System + Admin + Public
'security.contact.*' => ['system', 'admin', 'public']
```

## Environment Variable Mapping

SecretsManager maps environment variables to internal key paths:

```bash
# Security
export GCORE_SECURITY_KEY="your-encryption-key"
export HSM_PIN="your-hsm-pin"

# ValKey/Redis
export VALKEY_AUTH="redis-password"
export VALKEY_USERNAME="redis-user"
export VALKEY_TLS_CA_FILE="/path/to/ca.crt"

# SMTP
export NOTIFICATION_SMTP_HOST="smtp.example.com"
export NOTIFICATION_SMTP_PASSWORD="smtp-password"

# Licenses
export QUANTUM_RESISTANT_LICENSE_KEY="license-key"
```

These are accessed via their internal paths:
```php
SecretsManager::getSecret('cache.auth');           // VALKEY_AUTH
SecretsManager::getSecret('security.encryption.key'); // GCORE_SECURITY_KEY
```

## File Backend

Secrets stored in `config/secrets.json`:

```json
{
  "cache": {
    "auth": "redis-password",
    "tls": {
      "ca_file": "/etc/ssl/certs/ca.crt",
      "cert_file": "/etc/ssl/certs/client.crt"
    }
  },
  "notifications": {
    "smtp": {
      "host": "smtp.example.com",
      "password": "smtp-password"
    }
  }
}
```

**Security:** File is created with `chmod 0600` (owner read/write only).

## Best Practices

### 1. Use Environment Variables in Production

```php
// Preferred: ENV is read-only and highest priority
// Set in environment, retrieve naturally
$key = SecretsManager::getSecret('security.encryption.key');
```

### 2. Clear Cache After Rotation

```php
function rotateSecrets() {
    // External rotation process...

    // Clear cache to force re-fetch
    SecretsManager::clearCache();
}
```

### 3. Use Appropriate Access Levels

```php
// Admin panel code
$smtpHost = SecretsManager::getSecret('notifications.smtp.host', 'admin');

// System-only operations
$encKey = SecretsManager::getSecret('security.encryption.key', 'system');
```

### 4. Check Existence Before Optional Use

```php
if (SecretsManager::secretExists('optional.feature.key')) {
    $key = SecretsManager::getSecret('optional.feature.key');
    enableOptionalFeature($key);
}
```

## Troubleshooting

### Secret Not Found

1. Check environment variable is set: `echo $VALKEY_AUTH`
2. Check `config/secrets.json` exists and is readable
3. Verify key path matches mapping
4. Check file permissions (should be 0600)

### Access Denied

1. Verify access level matches key pattern in `$accessLevels`
2. Check for typos in key path
3. System-level secrets require `accessLevel = 'system'`

### ValKey Backend Failing

1. Ensure ValKey is running: `redis-cli ping`
2. Check `ValKeyStorage` class exists
3. Verify connection settings (default: 127.0.0.1:6379)

## Security Considerations

1. **Never log secret values** - Log key names only
2. **Use HSM for critical keys** - Encryption keys, HSM PINs
3. **Rotate secrets regularly** - Use `clearCache()` after rotation
4. **Restrict file permissions** - secrets.json should be 0600
5. **Prefer ENV in production** - Immutable after process start
