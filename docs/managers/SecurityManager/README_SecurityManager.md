# SecurityManager Documentation

## Overview

SecurityManager provides security capabilities with multi-site isolation for the gCore framework. It handles role-based authorization, API request validation, rate limiting, input sanitization, and CSRF protection. With gNode integration, it provides distributed rate limiting with centralized metrics.

**Namespace**: `gCore\Modules\Managers\Base\SecurityManager`
**Implements**: `ModuleInterface`, `SecurityCapabilityInterface`
**Pattern**: Singleton (accessed via `gCore::getService()`)

## Architecture

SecurityManager operates with tiered rate limiting:

1. **Tier 0 (gNode-Lua)**: Centralized rate limiting via `GNODE_SITE_RATE_LIMIT` Lua function with automatic metrics
2. **Tier 1 (ValKey)**: Distributed atomic rate limiting via INCR/EXPIRE
3. **Tier 2 (Memory)**: Per-process in-memory rate limiting (fallback)

### Rate Limiting Flow

```
API Request
├── Tier 0: gNode-Client FCALL (if available)
│   └── GNODE_SITE_RATE_LIMIT with origin metadata
├── Tier 1: ValKey INCR/EXPIRE (if gNode unavailable)
│   └── {site_id}:ratelimit:api:{ip_hash}
└── Tier 2: In-memory static array (fallback)
    └── rate_limit:{site_id}:{identifier}
```

## Initialization

```php
// Get SecurityManager instance via gCore
$securityManager = gCore::getService('SecurityManager');

// Configuration (passed during gCore initialization)
$config = [
    'site_id' => 'my_site',
    'node_id' => 'node1',
    'default_role' => 'guest',
    'require_2fa' => false,
    'csrf_salt' => 'your-secret-salt',
    'cors' => [
        'enabled' => true,
        'allowed_origins' => ['*'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key']
    ],
    'gnode_client' => $gNodeClient,
    'gnode_storage_adapter' => $adapter
];
```

## Public API Reference

### Singleton & Lifecycle

#### `getInstance(): ModuleInterface`
Returns the singleton instance of SecurityManager.

#### `initialize(array $config = []): void`
Initializes security system with configuration, sets up default roles.
- **Throws**: `InitializationException` if initialization fails

#### `isInitialized(): bool`
Check if manager is initialized.

#### `getStatus(): array`
Get full status including roles, user count, mode, and storage info.

#### `getConfig(): array`
Get current configuration.

#### `updateConfig(array $config): void`
Update configuration at runtime.

### Role Management

#### `defineRole(string $role, array $permissions): bool`
Define a role with its permissions.
- **Parameters**:
  - `$role`: Role name (e.g., 'admin', 'editor')
  - `$permissions`: Array of permission strings
- **Returns**: `true` on success
- **Throws**: `ValidationException` if role name is empty

```php
$securityManager->defineRole('editor', [
    'view_public',
    'view_member',
    'edit_content',
    'publish_content'
]);
```

#### `assignRole(string $user, string $role): bool`
Assign a role to a user.
- **Parameters**:
  - `$user`: User identifier
  - `$role`: Role name (must exist)
- **Returns**: `true` on success
- **Throws**: `ValidationException` if user is empty or role doesn't exist

```php
$securityManager->assignRole('user_123', 'editor');
```

### Permission Checking

#### `hasPermission(string $user, string $permission): bool`
Check if user has a specific permission.
- Uses user's assigned role or `default_role` from config
- **Returns**: `true` if user has permission

```php
if ($securityManager->hasPermission('user_123', 'edit_content')) {
    // Allow edit
}
```

#### `hasCapability(string $user, string $capability): bool`
Alias for `hasPermission()`.

#### `getUserCapabilities(string $user): array`
Get all permissions for a user based on their role.
- **Returns**: Array of permission strings

### Capability Management

#### `addCapability(string $user, string $capability): bool`
Add a capability to a user's role.
- **Returns**: `true` on success

#### `removeCapability(string $user, string $capability): bool`
Remove a capability from a user's role.
- **Returns**: `true` if removed, `false` if not found

### Input Sanitization

#### `sanitizeInput(mixed $input): mixed`
Sanitize input for safe usage.
- Applies `htmlspecialchars()` with ENT_QUOTES, UTF-8
- Recursively sanitizes arrays
- **Returns**: Sanitized input

```php
$cleanInput = $securityManager->sanitizeInput($userInput);
$cleanArray = $securityManager->sanitizeInput(['name' => '<script>alert(1)</script>']);
// Result: ['name' => '&lt;script&gt;alert(1)&lt;/script&gt;']
```

### CSRF Protection

#### `generateCsrfToken(string $action = 'default'): string`
Generate CSRF token for an action.
- **Returns**: MD5 hash token

#### `validateCsrfToken(string $token, string $action = 'default'): bool`
Validate CSRF token.
- **Returns**: `true` if valid

```php
// In form generation
$token = $securityManager->generateCsrfToken('delete_user');
echo '<input type="hidden" name="csrf_token" value="' . $token . '">';

// In form processing
if (!$securityManager->validateCsrfToken($_POST['csrf_token'], 'delete_user')) {
    throw new SecurityException('Invalid CSRF token');
}
```

### API Request Validation

#### `validateAPIRequest(mixed $request, array $options = []): array`
API request validation with rate limiting and parameter validation.
- **Parameters**:
  - `$request`: Request object (WP_REST_Request or similar) or array
  - `$options`: Validation options
- **Returns**: Validation result array

```php
$result = $securityManager->validateAPIRequest($request, [
    'rate_limit' => [
        'limit' => 100,
        'window' => 60,
        'identifier' => $_SERVER['REMOTE_ADDR'],
        'endpoint' => '/api/users'
    ],
    'params' => [
        'email' => ['required' => true, 'type' => 'email'],
        'age' => ['type' => 'integer', 'min' => 0, 'max' => 150],
        'status' => ['enum' => ['active', 'inactive', 'pending']]
    ]
]);

if (!$result['valid']) {
    return error_response($result['error_code'], $result['error_message'], $result['status_code']);
}

// Access rate limit info
$remaining = $result['rate_limit_remaining'];
$resetAt = $result['rate_limit_reset'];
```

**Validation Result Structure**:
```php
[
    'valid' => bool,
    'error_code' => string|null,      // 'rate_limit_exceeded', 'missing_parameter', etc.
    'error_message' => string|null,
    'status_code' => int,             // 200, 400, 429
    'rate_limit_remaining' => int,
    'rate_limit_reset' => int         // Unix timestamp
]
```

**Parameter Validation Rules**:
- `required`: bool - Parameter must be present
- `type`: string - integer|int|float|number|string|boolean|bool|array|object|email|url
- `min`, `max`: numeric - Min/max for numbers
- `minLength`, `maxLength`: int - String length constraints
- `pattern`: string - Regex pattern
- `enum`: array - Allowed values

### gNode Client Management

#### `setgNodeClient($client): void`
Set gNode-Client for centralized rate limiting with metrics.

#### `getgNodeClient(): ?gNodeClientInterface`
Get current gNode-Client instance.

### Capability Discovery

#### `getCapabilityVector(): array`
Get capability vector for geometric service discovery.

## Default Roles

SecurityManager initializes with these default roles:

```php
[
    'guest' => ['view_public'],
    'member' => ['view_public', 'view_member'],
    'admin' => [
        'view_public',
        'view_member',
        'view_admin',
        'edit_content',
        'manage_users',
        'manage_settings'
    ]
]
```

## Usage Examples

### Role-Based Access Control

```php
$security = gCore::getService('SecurityManager');

// Define custom role
$security->defineRole('moderator', [
    'view_public',
    'view_member',
    'edit_content',
    'delete_comments',
    'ban_users'
]);

// Assign role to user
$security->assignRole('user_456', 'moderator');

// Check permission
if ($security->hasPermission('user_456', 'ban_users')) {
    performBan($targetUser);
}

// Get all user capabilities
$caps = $security->getUserCapabilities('user_456');
```

### API Request Validation

```php
$result = $security->validateAPIRequest($_REQUEST, [
    'rate_limit' => [
        'limit' => 60,
        'window' => 60,
        'identifier' => $_SERVER['REMOTE_ADDR']
    ],
    'params' => [
        'username' => [
            'required' => true,
            'type' => 'string',
            'minLength' => 3,
            'maxLength' => 50,
            'pattern' => '/^[a-zA-Z0-9_]+$/'
        ],
        'email' => [
            'required' => true,
            'type' => 'email'
        ],
        'age' => [
            'type' => 'integer',
            'min' => 13,
            'max' => 120
        ]
    ]
]);

if (!$result['valid']) {
    http_response_code($result['status_code']);
    echo json_encode([
        'error' => $result['error_code'],
        'message' => $result['error_message']
    ]);
    exit;
}
```

### Input Sanitization

```php
// Sanitize user input before display
$userComment = $security->sanitizeInput($_POST['comment']);

// Sanitize entire array
$formData = $security->sanitizeInput($_POST);
```

## Rate Limiting Tiers

| Tier | Method | Characteristics |
|------|--------|-----------------|
| 0 (gNode) | `GNODE_SITE_RATE_LIMIT` | Centralized metrics, origin tracking, FCALL-only |
| 1 (ValKey) | `INCR/EXPIRE` | Distributed, atomic, no metrics |
| 2 (Memory) | Static array | Per-process, non-distributed, fallback |

## Capability Vector

```php
[
    'security' => 1.0,  // Primary capability
    'auth' => 0.9,      // Authentication
    'crypto' => 0.8,    // Cryptographic operations
    'rules' => 0.7      // Security rules/policies
]
```

## Error Codes

| Code | HTTP | Description |
|------|------|-------------|
| `rate_limit_exceeded` | 429 | Too many requests |
| `missing_parameter` | 400 | Required parameter not provided |
| `invalid_type` | 400 | Parameter type mismatch |
| `value_too_small` | 400 | Numeric value below minimum |
| `value_too_large` | 400 | Numeric value above maximum |
| `string_too_short` | 400 | String below minLength |
| `string_too_long` | 400 | String above maxLength |
| `pattern_mismatch` | 400 | String doesn't match pattern |
| `invalid_enum_value` | 400 | Value not in allowed enum |

---

*Last Updated: January 2026*
