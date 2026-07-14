<?php
declare(strict_types=1);
namespace gCore\Modules\Managers\Base\SecurityManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Security\SecurityCapabilityInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\Utils\SelfContainedErrorHandler;
use gCore\Modules\Core\Exceptions\{
    InitializationException,
    SecurityException,
    ValidationException
};

require_once dirname(__DIR__, 2) . '/Traits/StateManagerAware.php';
use gCore\Modules\Managers\Traits\StateManagerAware;

/**
 * Security Manager Implementation
 * 
 * Provides security capabilities with multi-site isolation.
 */
class SecurityManager implements ModuleInterface, SecurityCapabilityInterface {
    use StateManagerAware;
    use ManagerConfigTrait;

    private const DEFAULTS = [
        'site_id' => 'default',
        'node_id' => 'node1',
        'default_role' => 'guest',
        'require_2fa' => false,
        'content_security_policy' => [],
        'cors' => [
            'enabled' => true,
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
        ],
        'traits' => [],
    ];

    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Configuration
     */
    private $config = [];
    
    /**
     * Initialization state
     */
    private $initialized = false;
    
    /**
     * Node metadata for multi-tenant isolation
     */
    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];
    
    /**
     * Roles and permissions
     */
    private $roles = [];
    private $userRoles = [];
    
    /**
     * Get singleton instance
     * 
     * @return ModuleInterface SecurityManager instance
     */
    public static function getInstance(): ModuleInterface {
        if (self::$instance === null) {
            self::$instance = new self();
            // No auto-initialization to prevent circular dependencies
            // The gCore framework will handle initialization with proper config
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
    }
    
    /**
     * Capability vector for GeometricTopology integration
     * 
     * @var array
     */
    private $capabilityVector = [
        'security' => 1.0,
        'auth' => 0.9,
        'crypto' => 0.8,
        'rules' => 0.7
    ];
    
    /**
     * Initialize SecurityManager with configuration
     * 
     * @param array $config Configuration options
     * @return void
     * @throws InitializationException If initialization fails
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

            // Layered config: DEFAULTS → ValKey → $config arg
            $siteId = (string)($config['site_id'] ?? self::DEFAULTS['site_id']);
            $valkeyConfig = [];
            $storage = $this->gcoreResolveStorage($config);
            if ($storage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'SecurityManager');
            }
            $this->config = array_merge(self::DEFAULTS, $valkeyConfig, $config);

            // Sensitive keys: jwt_secret + api_keys read from the secrets
            // keyspace ({site}:gcore:secrets:SecurityManager).
            if ($storage !== null) {
                if (empty($this->config['jwt_secret'])) {
                    $secret = $this->gcoreGetSecret($storage, $siteId, 'SecurityManager', 'jwt_secret');
                    if ($secret !== null) {
                        $this->config['jwt_secret'] = $secret;
                    }
                }
                if (empty($this->config['api_keys'])) {
                    $keys = $this->gcoreGetSecret($storage, $siteId, 'SecurityManager', 'api_keys');
                    if ($keys !== null) {
                        $this->config['api_keys'] = $keys;
                    }
                }
            }

            // Set node metadata for multi-tenant isolation
            $this->nodeMetadata = [
                'site_id' => $this->config['site_id'],
                'node_id' => $this->config['node_id']
            ];

            // Set site ID for StateManagerAware trait
            $this->siteId = $this->config['site_id'];

            if ($this->gNodeClient) {
                SelfContainedErrorHandler::logInfo(
                    'SecurityManager',
                    'initialize',
                    'SecurityManager using gNode-Client integration',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'node_id' => $this->nodeMetadata['node_id']
                    ]
                );
            }

            // Initialize default roles
            $this->initializeDefaultRoles();

            // Initialize traits if configuration provided
            if (!empty($this->config['traits'])) {
                $this->initializeTraits();
            }
            
            $this->initialized = true;
            
            // Log successful initialization using SelfContainedErrorHandler
            SelfContainedErrorHandler::logInfo(
                'SecurityManager',
                'initialize',
                'Successfully initialized SecurityManager',
                ['site_id' => $this->nodeMetadata['site_id'], 'node_id' => $this->nodeMetadata['node_id']]
            );
            
        } catch (\Exception $e) {
            // Log error using SelfContainedErrorHandler
            SelfContainedErrorHandler::logError(
                'SecurityManager',
                'initialize',
                $e,
                ['site_id' => $this->nodeMetadata['site_id'] ?? 'default', 'node_id' => $this->nodeMetadata['node_id'] ?? 'node1']
            );
            
            throw new InitializationException(
                'Failed to initialize SecurityManager: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Initialize traits (reserved for future extension)
     *
     * @return void
     */
    private function initializeTraits(): void {
        // Reserved for trait-based extensions
    }
    
    /**
     * Initialize default roles
     * 
     * @return void
     */
    private function initializeDefaultRoles(): void {
        // Define default roles
        $this->roles = [
            'guest' => [
                'view_public'
            ],
            'member' => [
                'view_public',
                'view_member'
            ],
            'admin' => [
                'view_public',
                'view_member',
                'view_admin',
                'edit_content',
                'manage_users',
                'manage_settings'
            ]
        ];
    }
    
    /**
     * Define a role with permissions
     * 
     * @param string $role Role name
     * @param array $permissions Permissions for this role
     * @return bool Success status
     * @throws ValidationException If role is invalid
     * @api
     */
    public function defineRole(string $role, array $permissions): bool {
        if (empty($role)) {
            throw new ValidationException('Role name cannot be empty');
        }
        
        $this->roles[$role] = $permissions;
        return true;
    }
    
    /**
     * Assign role to user
     * 
     * @param string $user User identifier
     * @param string $role Role name
     * @return bool Success status
     * @throws ValidationException If role or user is invalid
     * @api
     */
    public function assignRole(string $user, string $role): bool {
        if (empty($user)) {
            throw new ValidationException('User identifier cannot be empty');
        }
        
        if (!isset($this->roles[$role])) {
            throw new ValidationException("Role does not exist: {$role}");
        }
        
        $this->userRoles[$user] = $role;
        return true;
    }
    
    /**
     * Check if user has permission
     * 
     * @param string $user User identifier
     * @param string $permission Permission to check
     * @return bool True if user has permission
     * @api
     */
    public function hasPermission(string $user, string $permission): bool {
        $role = $this->userRoles[$user] ?? $this->config['default_role'];
        
        if (!isset($this->roles[$role])) {
            return false;
        }
        
        return in_array($permission, $this->roles[$role]);
    }
    
    /**
     * Check if user has capability
     * 
     * @param string $user User identifier
     * @param string $capability Capability to check
     * @return bool True if user has capability
     * @api
     */
    public function hasCapability(string $user, string $capability): bool {
        return $this->hasPermission($user, $capability);
    }
    
    /**
     * Get user capabilities
     * 
     * @param string $user User identifier
     * @return array List of user capabilities
     * @api
     */
    public function getUserCapabilities(string $user): array {
        $role = $this->userRoles[$user] ?? $this->config['default_role'];
        
        if (!isset($this->roles[$role])) {
            return [];
        }
        
        return $this->roles[$role];
    }
    
    /**
     * Add capability to user
     * 
     * @param string $user User identifier
     * @param string $capability Capability to add
     * @return bool Success status
     */
    public function addCapability(string $user, string $capability): bool {
        $role = $this->userRoles[$user] ?? $this->config['default_role'];
        
        if (!isset($this->roles[$role])) {
            return false;
        }
        
        if (!in_array($capability, $this->roles[$role])) {
            $this->roles[$role][] = $capability;
        }
        
        return true;
    }
    
    /**
     * Remove capability from user
     * 
     * @param string $user User identifier
     * @param string $capability Capability to remove
     * @return bool Success status
     */
    public function removeCapability(string $user, string $capability): bool {
        $role = $this->userRoles[$user] ?? $this->config['default_role'];
        
        if (!isset($this->roles[$role])) {
            return false;
        }
        
        $key = array_search($capability, $this->roles[$role]);
        if ($key !== false) {
            unset($this->roles[$role][$key]);
            $this->roles[$role] = array_values($this->roles[$role]);
            return true;
        }
        
        return false;
    }
    
    /**
     * Sanitize input
     * 
     * @param mixed $input Input to sanitize
     * @return mixed Sanitized input
     */
    public function sanitizeInput($input) {
        if (is_string($input)) {
            // Basic sanitization
            $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        } elseif (is_array($input)) {
            // Recursively sanitize arrays
            foreach ($input as $key => $value) {
                $input[$key] = $this->sanitizeInput($value);
            }
        }
        
        return $input;
    }
    
    // CSRF tokens stored via session (preferred) or ValKey distributed state (fallback)
    // No static in-memory storage — this ensures cross-worker token validation

    /**
     * Validate CSRF token
     *
     * Uses timing-safe comparison and validates token structure,
     * expiration, and action binding.
     *
     * @param string $token CSRF token to validate
     * @param string $action Action name the token was generated for
     * @return bool True if token is valid
     * @api
     */
    public function validateCsrfToken(string $token, string $action = 'default'): bool {
        if (empty($token) || strlen($token) < 64) {
            return false;
        }

        // Check session-stored tokens first (preferred)
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_csrf_tokens'][$action])) {
            $stored = $_SESSION['_csrf_tokens'][$action];
            if (isset($stored['token'], $stored['expires']) && $stored['expires'] > time()) {
                return hash_equals($stored['token'], $token);
            }
            // Token expired, remove it
            unset($_SESSION['_csrf_tokens'][$action]);
            return false;
        }

        // Fallback to distributed state via ValKey (cross-worker validation)
        $key = $this->getCsrfStorageKey($action);
        $stored = $this->getDistributedState($key);
        if ($stored !== null && is_array($stored)) {
            if (isset($stored['token'], $stored['expires']) && $stored['expires'] > time()) {
                return hash_equals($stored['token'], $token);
            }
        }

        return false;
    }

    /**
     * Generate CSRF token
     *
     * Creates a cryptographically secure random token bound to:
     * - The specific action
     * - The current session (when available)
     * - An expiration time
     *
     * @param string $action Action name to bind token to
     * @param int $ttl Token lifetime in seconds (default: 3600)
     * @return string CSRF token (64 hex characters)
     * @api
     */
    public function generateCsrfToken(string $action = 'default', int $ttl = 3600): string {
        // Generate cryptographically secure random token
        $token = bin2hex(random_bytes(32));
        $expires = time() + $ttl;

        $tokenData = [
            'token' => $token,
            'expires' => $expires
        ];

        // Store in session if available (preferred - survives across requests)
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['_csrf_tokens'])) {
                $_SESSION['_csrf_tokens'] = [];
            }
            $_SESSION['_csrf_tokens'][$action] = $tokenData;
        }

        // Also store in distributed state via ValKey (cross-worker validation)
        $key = $this->getCsrfStorageKey($action);
        $this->setDistributedState($key, $tokenData);

        return $token;
    }

    /**
     * Revoke a CSRF token (use after successful form submission)
     *
     * @param string $action Action name
     * @return void
     */
    public function revokeCsrfToken(string $action = 'default'): void {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_csrf_tokens'][$action])) {
            unset($_SESSION['_csrf_tokens'][$action]);
        }

        // Clear from distributed state
        $key = $this->getCsrfStorageKey($action);
        $this->setDistributedState($key, null);
    }

    /**
     * Get storage key for CSRF token
     *
     * @param string $action Action name
     * @return string Storage key
     */
    private function getCsrfStorageKey(string $action): string {
        return $this->nodeMetadata['site_id'] . ':csrf:' . $action;
    }

    // ========================================================================
    // JWT Authentication
    // ========================================================================

    /**
     * Validate a JWT token
     *
     * Supports HS256, HS384, HS512 algorithms with HMAC signature verification.
     * Validates structure, signature, expiration, not-before, and issuer claims.
     *
     * @param string $token JWT token to validate
     * @param array $options Validation options:
     *   - secret: (required) HMAC secret key for signature verification
     *   - algorithms: (optional) Allowed algorithms, default ['HS256']
     *   - issuer: (optional) Required issuer claim
     *   - audience: (optional) Required audience claim
     *   - leeway: (optional) Clock skew tolerance in seconds, default 0
     * @return array Validation result:
     *   - valid: bool
     *   - payload: array (decoded payload if valid)
     *   - error: string (error message if invalid)
     *   - error_code: string (error code if invalid)
     * @api
     */
    public function validateJWT(string $token, array $options = []): array {
        $secret = $options['secret'] ?? $this->config['jwt_secret'] ?? null;
        $allowedAlgorithms = $options['algorithms'] ?? ['HS256'];
        $leeway = $options['leeway'] ?? 0;

        if (empty($secret)) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'JWT secret not configured',
                'error_code' => 'missing_secret'
            ];
        }

        // Split token into parts
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'Invalid token structure',
                'error_code' => 'invalid_structure'
            ];
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        // Decode header
        $header = $this->base64UrlDecode($headerB64);
        if ($header === false) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'Invalid header encoding',
                'error_code' => 'invalid_header'
            ];
        }

        $headerData = json_decode($header, true);
        if (!$headerData || !isset($headerData['alg'])) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'Invalid header format',
                'error_code' => 'invalid_header'
            ];
        }

        // Verify algorithm is allowed
        $algorithm = $headerData['alg'];
        if (!in_array($algorithm, $allowedAlgorithms, true)) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => "Algorithm '{$algorithm}' not allowed",
                'error_code' => 'algorithm_not_allowed'
            ];
        }

        // Decode payload
        $payload = $this->base64UrlDecode($payloadB64);
        if ($payload === false) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'Invalid payload encoding',
                'error_code' => 'invalid_payload'
            ];
        }

        $payloadData = json_decode($payload, true);
        if (!$payloadData) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'Invalid payload format',
                'error_code' => 'invalid_payload'
            ];
        }

        // Verify signature
        $signature = $this->base64UrlDecode($signatureB64);
        if ($signature === false) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'Invalid signature encoding',
                'error_code' => 'invalid_signature'
            ];
        }

        $expectedSignature = $this->computeJWTSignature($headerB64, $payloadB64, $secret, $algorithm);
        if (!hash_equals($expectedSignature, $signature)) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => 'Signature verification failed',
                'error_code' => 'invalid_signature'
            ];
        }

        // Validate time-based claims
        $now = time();

        // Check expiration (exp)
        if (isset($payloadData['exp'])) {
            if ($payloadData['exp'] < ($now - $leeway)) {
                return [
                    'valid' => false,
                    'payload' => $payloadData,
                    'error' => 'Token has expired',
                    'error_code' => 'token_expired'
                ];
            }
        }

        // Check not-before (nbf)
        if (isset($payloadData['nbf'])) {
            if ($payloadData['nbf'] > ($now + $leeway)) {
                return [
                    'valid' => false,
                    'payload' => $payloadData,
                    'error' => 'Token not yet valid',
                    'error_code' => 'token_not_valid_yet'
                ];
            }
        }

        // Check issued-at (iat) - token shouldn't be from the future
        if (isset($payloadData['iat'])) {
            if ($payloadData['iat'] > ($now + $leeway)) {
                return [
                    'valid' => false,
                    'payload' => $payloadData,
                    'error' => 'Token issued in the future',
                    'error_code' => 'invalid_iat'
                ];
            }
        }

        // Validate issuer if specified
        if (isset($options['issuer'])) {
            if (!isset($payloadData['iss']) || $payloadData['iss'] !== $options['issuer']) {
                return [
                    'valid' => false,
                    'payload' => $payloadData,
                    'error' => 'Invalid issuer',
                    'error_code' => 'invalid_issuer'
                ];
            }
        }

        // Validate audience if specified
        if (isset($options['audience'])) {
            $aud = $payloadData['aud'] ?? null;
            $validAudience = false;
            if (is_array($aud)) {
                $validAudience = in_array($options['audience'], $aud, true);
            } else {
                $validAudience = ($aud === $options['audience']);
            }
            if (!$validAudience) {
                return [
                    'valid' => false,
                    'payload' => $payloadData,
                    'error' => 'Invalid audience',
                    'error_code' => 'invalid_audience'
                ];
            }
        }

        return [
            'valid' => true,
            'payload' => $payloadData,
            'error' => null,
            'error_code' => null
        ];
    }

    /**
     * Generate a JWT token
     *
     * @param array $payload Token payload (claims)
     * @param array $options Generation options:
     *   - secret: (required) HMAC secret key
     *   - algorithm: (optional) Algorithm, default 'HS256'
     *   - ttl: (optional) Time to live in seconds, default 3600
     *   - issuer: (optional) Issuer claim
     *   - audience: (optional) Audience claim
     * @return string JWT token
     * @throws \InvalidArgumentException If secret is not configured
     * @api
     */
    public function generateJWT(array $payload, array $options = []): string {
        $secret = $options['secret'] ?? $this->config['jwt_secret'] ?? null;
        $algorithm = $options['algorithm'] ?? 'HS256';
        $ttl = $options['ttl'] ?? 3600;

        if (empty($secret)) {
            throw new \InvalidArgumentException('JWT secret not configured');
        }

        $now = time();

        // Build header
        $header = [
            'typ' => 'JWT',
            'alg' => $algorithm
        ];

        // Add standard claims if not present
        if (!isset($payload['iat'])) {
            $payload['iat'] = $now;
        }
        if (!isset($payload['exp'])) {
            $payload['exp'] = $now + $ttl;
        }
        if (isset($options['issuer']) && !isset($payload['iss'])) {
            $payload['iss'] = $options['issuer'];
        }
        if (isset($options['audience']) && !isset($payload['aud'])) {
            $payload['aud'] = $options['audience'];
        }
        if (!isset($payload['jti'])) {
            $payload['jti'] = bin2hex(random_bytes(16));
        }

        // Encode parts
        $headerB64 = $this->base64UrlEncode(json_encode($header));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload));

        // Create signature
        $signature = $this->computeJWTSignature($headerB64, $payloadB64, $secret, $algorithm);
        $signatureB64 = $this->base64UrlEncode($signature);

        return "{$headerB64}.{$payloadB64}.{$signatureB64}";
    }

    /**
     * Compute JWT signature
     *
     * @param string $headerB64 Base64url-encoded header
     * @param string $payloadB64 Base64url-encoded payload
     * @param string $secret HMAC secret
     * @param string $algorithm Algorithm (HS256, HS384, HS512)
     * @return string Raw signature bytes
     */
    private function computeJWTSignature(string $headerB64, string $payloadB64, string $secret, string $algorithm): string {
        $data = "{$headerB64}.{$payloadB64}";

        $algoMap = [
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512'
        ];

        $hashAlgo = $algoMap[$algorithm] ?? 'sha256';
        return hash_hmac($hashAlgo, $data, $secret, true);
    }

    /**
     * Base64url encode
     *
     * @param string $data Data to encode
     * @return string Base64url-encoded string
     */
    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode
     *
     * @param string $data Base64url-encoded string
     * @return string|false Decoded data or false on failure
     */
    private function base64UrlDecode(string $data) {
        $padded = str_pad($data, strlen($data) % 4, '=', STR_PAD_RIGHT);
        return base64_decode(strtr($padded, '-_', '+/'));
    }

    // ========================================================================
    // API Key Authentication
    // ========================================================================

    /**
     * API key storage
     * @var array
     */
    private $apiKeys = [];

    /**
     * Validate an API key
     *
     * Checks key existence, active status, expiration, and optionally
     * required capabilities/scopes.
     *
     * @param string $apiKey API key to validate
     * @param array $options Validation options:
     *   - capability: (optional) Required capability
     *   - scope: (optional) Required scope
     * @return array Validation result:
     *   - valid: bool
     *   - key_data: array (key metadata if valid)
     *   - user: array (user info extracted from key)
     *   - error: string (error message if invalid)
     *   - error_code: string (error code if invalid)
     * @api
     */
    public function validateAPIKey(string $apiKey, array $options = []): array {
        if (empty($apiKey)) {
            return [
                'valid' => false,
                'key_data' => null,
                'user' => null,
                'error' => 'API key is required',
                'error_code' => 'missing_key'
            ];
        }

        // Load keys if not already loaded
        if (empty($this->apiKeys)) {
            $this->loadAPIKeys();
        }

        // Look up key
        $keyData = $this->apiKeys[$apiKey] ?? null;

        if (!$keyData) {
            return [
                'valid' => false,
                'key_data' => null,
                'user' => null,
                'error' => 'Invalid API key',
                'error_code' => 'invalid_key'
            ];
        }

        // Check if key is active
        if (isset($keyData['active']) && !$keyData['active']) {
            return [
                'valid' => false,
                'key_data' => $keyData,
                'user' => null,
                'error' => 'API key is inactive',
                'error_code' => 'key_inactive'
            ];
        }

        // Check expiration
        if (isset($keyData['expires']) && $keyData['expires'] < time()) {
            return [
                'valid' => false,
                'key_data' => $keyData,
                'user' => null,
                'error' => 'API key has expired',
                'error_code' => 'key_expired'
            ];
        }

        // Check required capability
        if (isset($options['capability'])) {
            $capabilities = $keyData['capabilities'] ?? [];
            if (!in_array($options['capability'], $capabilities, true)) {
                return [
                    'valid' => false,
                    'key_data' => $keyData,
                    'user' => null,
                    'error' => "API key lacks required capability: {$options['capability']}",
                    'error_code' => 'missing_capability'
                ];
            }
        }

        // Check required scope
        if (isset($options['scope'])) {
            $scopes = $keyData['scopes'] ?? [];
            if (!in_array($options['scope'], $scopes, true)) {
                return [
                    'valid' => false,
                    'key_data' => $keyData,
                    'user' => null,
                    'error' => "API key lacks required scope: {$options['scope']}",
                    'error_code' => 'missing_scope'
                ];
            }
        }

        // Update last used timestamp
        $this->updateAPIKeyLastUsed($apiKey);

        // Build user info from key data
        $user = [
            'auth_method' => 'api_key',
            'api_key' => $apiKey,
            'name' => $keyData['name'] ?? 'API User',
            'roles' => $keyData['roles'] ?? ['api_user'],
            'capabilities' => $keyData['capabilities'] ?? [],
            'scopes' => $keyData['scopes'] ?? []
        ];

        return [
            'valid' => true,
            'key_data' => $keyData,
            'user' => $user,
            'error' => null,
            'error_code' => null
        ];
    }

    /**
     * Create a new API key
     *
     * @param array $data Key configuration:
     *   - name: (optional) Human-readable name
     *   - roles: (optional) Array of roles
     *   - capabilities: (optional) Array of capabilities
     *   - scopes: (optional) Array of scopes
     *   - expires: (optional) Expiration timestamp or null for no expiration
     * @return array Result with 'key' (the generated key) and 'data' (key metadata)
     * @api
     */
    public function createAPIKey(array $data = []): array {
        // Generate secure random key
        $apiKey = bin2hex(random_bytes(32));

        $keyData = [
            'name' => $data['name'] ?? 'API Key',
            'active' => true,
            'created' => time(),
            'last_used' => null,
            'expires' => $data['expires'] ?? null,
            'roles' => $data['roles'] ?? ['api_user'],
            'capabilities' => $data['capabilities'] ?? [],
            'scopes' => $data['scopes'] ?? [],
            'site_id' => $this->nodeMetadata['site_id']
        ];

        $this->apiKeys[$apiKey] = $keyData;
        $this->saveAPIKeys();

        return [
            'key' => $apiKey,
            'data' => $keyData
        ];
    }

    /**
     * Revoke an API key
     *
     * @param string $apiKey API key to revoke
     * @return bool True if key was found and revoked
     * @api
     */
    public function revokeAPIKey(string $apiKey): bool {
        if (!isset($this->apiKeys[$apiKey])) {
            return false;
        }

        unset($this->apiKeys[$apiKey]);
        $this->saveAPIKeys();
        return true;
    }

    /**
     * Load API keys from storage
     *
     * @return void
     */
    private function loadAPIKeys(): void {
        $siteId = $this->nodeMetadata['site_id'];

        // Load via gNode-Client FCALL (canonical path)
        if ($this->gNodeClient !== null) {
            try {
                $keys = $this->gNodeClient->luaHGetAll("{$siteId}:api_keys");
                if (is_array($keys) && !empty($keys)) {
                    $this->apiKeys = [];
                    foreach ($keys as $key => $data) {
                        $this->apiKeys[$key] = is_string($data) ? json_decode($data, true) : $data;
                    }
                    return;
                }
            } catch (\Throwable $e) {
                error_log("[gCore] SecurityManager::loadAPIKeys gNodeClient failed: {$e->getMessage()}");
            }
        }

        // Fallback to WordPress options (non-ValKey environments)
        if (function_exists('get_option')) {
            $keys = get_option("gcore_api_keys_{$siteId}", []);
            if (is_array($keys)) {
                $this->apiKeys = $keys;
            }
        }

        // Fallback to config
        if (empty($this->apiKeys) && isset($this->config['api_keys'])) {
            $this->apiKeys = $this->config['api_keys'];
        }
    }

    /**
     * Save API keys to storage
     *
     * @return void
     */
    private function saveAPIKeys(): void {
        $siteId = $this->nodeMetadata['site_id'];

        // Save via gNode-Client FCALL (canonical path)
        if ($this->gNodeClient !== null) {
            try {
                foreach ($this->apiKeys as $key => $data) {
                    $this->gNodeClient->luaHSet("{$siteId}:api_keys", $key, json_encode($data));
                }
                return;
            } catch (\Throwable $e) {
                error_log("[gCore] SecurityManager::saveAPIKeys gNodeClient failed: {$e->getMessage()}");
            }
        }

        // Fallback to WordPress options (non-ValKey environments)
        if (function_exists('update_option')) {
            update_option("gcore_api_keys_{$siteId}", $this->apiKeys);
        }
    }

    /**
     * Update API key last used timestamp
     *
     * @param string $apiKey API key
     * @return void
     */
    private function updateAPIKeyLastUsed(string $apiKey): void {
        if (!isset($this->apiKeys[$apiKey])) {
            return;
        }

        $this->apiKeys[$apiKey]['last_used'] = time();

        // Async update to storage (don't block request)
        // In production, this would use a deferred write queue
        $this->saveAPIKeys();
    }

    /**
     * Get module configuration
     *
     * @return array Configuration
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * Update module configuration
     * 
     * @param array $config New configuration
     * @return void
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
        // Determine rate limiting mode and storage type
        $mode = 'free_tier';
        $storageType = 'memory';
        $storageStatus = 'available';

        if ($this->gNodeClient !== null) {
            $mode = 'full';
            $storageType = 'gnode';
            $storageStatus = 'connected';
        } elseif ($this->valkeyStorage !== null) {
            try {
                if (method_exists($this->valkeyStorage, 'isConnected') && $this->valkeyStorage->isConnected()) {
                    $mode = 'full';
                    $storageType = 'valkey';
                    $storageStatus = 'connected';
                }
            } catch (\Throwable $e) {
                // ValKey not available, stay in free_tier mode
            }
        }

        return [
            'initialized' => $this->initialized,
            'site_id' => $this->nodeMetadata['site_id'],
            'node_id' => $this->nodeMetadata['node_id'],
            'mode' => $mode,
            'storage_type' => $storageType,
            'storage' => $storageStatus,
            'roles' => array_keys($this->roles),
            'user_count' => count($this->userRoles),
            'capabilities' => $this->capabilityVector
        ];
    }
    
    /**
     * Get capability vector for service discovery
     *
     * @return array Capability vector
     */
    public function getCapabilityVector(): array {
        return $this->capabilityVector;
    }

    // Rate limiting uses TIER 0 (gNode Lua), TIER 1 (direct ValKey), or
    // TIER 2 (StateManagerAware incrementCounter) — no static in-memory storage

    /**
     * ValKey storage for distributed rate limiting
     *
     * @var \gCore\Modules\Core\Adapters\Shared\ValKeyStorage|null
     */
    private $valkeyStorage = null;

    /**
     * gNode-Client for centralized metrics via ValKey Lua functions
     *
     * When set, rate limiting uses GNODE_SITE_RATE_LIMIT Lua function
     * for automatic metrics tracking and centralized observability.
     *
     * @var \gCore\gNode\gNodeClientInterface|null
     */
    private $gNodeClient = null;

    /**
     * Set the gNode-Client for centralized rate limiting with metrics
     *
     * When a gNode-Client is provided, SecurityManager will use ValKey Lua functions
     * for rate limiting, which provides:
     * - Automatic metrics tracking (ratelimit:check, ratelimit:exceeded)
     * - Origin-based metric categorization
     * - Centralized observability via gNode monitoring
     *
     * @param \gCore\gNode\gNodeClientInterface $client gNode client instance
     * @return void
     * @api
     */
    public function setgNodeClient($client): void {
        $this->gNodeClient = $client;
    }

    /**
     * Get the gNode-Client instance
     *
     * @return \gCore\gNode\gNodeClientInterface|null
     */
    public function getgNodeClient() {
        return $this->gNodeClient;
    }

    /**
     * Validate an API request with rate limiting and parameter validation
     *
     * This method provides API request validation including:
     * - Rate limiting per client identifier
     * - Parameter type and constraint validation
     * - Automatic sanitization of inputs
     *
     * @param mixed $request Request object (WP_REST_Request or similar)
     * @param array $options Validation options:
     *   - rate_limit: array with 'limit', 'window', 'identifier' keys
     *   - params: array of parameter validation rules
     *   - sanitize: bool whether to sanitize inputs (default: true)
     * @return array Validation result:
     *   - valid: bool
     *   - error_code: string (if invalid)
     *   - error_message: string (if invalid)
     *   - status_code: int HTTP status code (if invalid)
     *   - rate_limit_remaining: int (if rate limiting enabled)
     *   - rate_limit_reset: int timestamp (if rate limiting enabled)
     * @api
     */
    public function validateAPIRequest($request, array $options = []): array {
        $result = [
            'valid' => true,
            'error_code' => null,
            'error_message' => null,
            'status_code' => 200
        ];

        // Rate limiting validation
        if (!empty($options['rate_limit'])) {
            $rateLimitResult = $this->checkRateLimit($options['rate_limit']);

            if (!$rateLimitResult['allowed']) {
                return [
                    'valid' => false,
                    'error_code' => 'rate_limit_exceeded',
                    'error_message' => 'Too many requests. Please try again later.',
                    'status_code' => 429,
                    'rate_limit_remaining' => 0,
                    'rate_limit_reset' => $rateLimitResult['reset_at']
                ];
            }

            $result['rate_limit_remaining'] = $rateLimitResult['remaining'];
            $result['rate_limit_reset'] = $rateLimitResult['reset_at'];
        }

        // Parameter validation
        if (!empty($options['params'])) {
            $paramResult = $this->validateParams($request, $options['params']);

            if (!$paramResult['valid']) {
                return array_merge($result, [
                    'valid' => false,
                    'error_code' => $paramResult['error_code'],
                    'error_message' => $paramResult['error_message'],
                    'status_code' => 400
                ]);
            }
        }

        return $result;
    }

    /**
     * Check rate limit for a client
     *
     * Uses gNode-Client's Lua functions for centralized metrics tracking.
     * Falls back to direct ValKey commands if gNode-Client unavailable.
     * Falls back to in-memory tracking if ValKey is unavailable.
     *
     * @param array $config Rate limit configuration:
     *   - limit: int max requests per window
     *   - window: int window size in seconds
     *   - identifier: string client identifier (IP, API key, etc.)
     *   - endpoint: string (optional) endpoint being accessed
     * @return array Result with 'allowed', 'remaining', 'reset_at' keys
     */
    private function checkRateLimit(array $config): array {
        $limit = $config['limit'] ?? 100;
        $window = $config['window'] ?? 60;
        $identifier = $config['identifier'] ?? '0.0.0.0';
        $endpoint = $config['endpoint'] ?? 'api';
        $siteId = $this->nodeMetadata['site_id'] ?? 'default';
        $now = time();

        // TIER 0: Use gNode-Client for centralized metrics via Lua functions
        if ($this->gNodeClient !== null) {
            $gNodeResult = $this->checkRateLimitGNode($identifier, $limit, $window, $endpoint);
            if ($gNodeResult !== null) {
                return $gNodeResult;
            }
        }

        // TIER 1: Try direct ValKey-based rate limiting (distributed, atomic, no metrics)
        $valkeyResult = $this->checkRateLimitValkey($siteId, $identifier, $limit, $window);
        if ($valkeyResult !== null) {
            return $valkeyResult;
        }

        // TIER 2: Fall back to in-memory rate limiting (per-process, non-distributed)
        return $this->checkRateLimitInMemory($siteId, $identifier, $limit, $window, $now);
    }

    /**
     * Check rate limit using gNode-Client's Lua function
     *
     * This provides centralized metrics tracking via gNode_SITE_RATE_LIMIT Lua function.
     * All rate limit checks are tracked with origin metadata for observability.
     *
     * @param string $identifier Client identifier (IP address)
     * @param int $limit Max requests per window
     * @param int $window Window size in seconds
     * @param string $endpoint Endpoint being accessed
     * @return array|null Result array or null if gNode-Client unavailable
     */
    private function checkRateLimitGNode(string $identifier, int $limit, int $window, string $endpoint): ?array {
        try {
            // Build operation key from identifier hash
            $ipHash = substr(md5($identifier), 0, 16);
            $operation = 'api:' . $ipHash;

            // Call gNode-Client with origin metadata for centralized metrics
            $result = $this->gNodeClient->checkRateLimit($operation, $limit, $window, [
                'origin' => 'SecurityManager',
                'endpoint' => $endpoint,
                'client_ip' => $identifier,
                'site_id' => $this->nodeMetadata['site_id'] ?? 'default'
            ]);

            // Convert to SecurityManager response format
            return [
                'allowed' => $result['allowed'],
                'remaining' => $result['remaining'],
                'reset_at' => time() + $window,
                'current_count' => $result['current'],
                'source' => 'gnode-lua'
            ];

        } catch (\Throwable $e) {
            error_log("SecurityManager: gNode rate limit failed: " . $e->getMessage());
            return null; // Fall through to TIER 1
        }
    }

    /**
     * Check rate limit using ValKey's atomic INCR operation
     *
     * This provides distributed, atomic rate limiting across all PHP-FPM workers.
     * Uses simple INCR with EXPIRE for sliding window rate limiting.
     *
     * @param string $siteId Site identifier
     * @param string $identifier Client identifier (IP address)
     * @param int $limit Max requests per window
     * @param int $window Window size in seconds
     * @return array|null Result array or null if ValKey unavailable
     */
    private function checkRateLimitValkey(string $siteId, string $identifier, int $limit, int $window): ?array {
        // Use gNodeClient for rate limiting (canonical FCALL path)
        if ($this->gNodeClient === null) {
            return null; // gNodeClient not available, fall back to in-memory
        }

        try {
            // Build rate limit key using site namespace
            $ipHash = substr(md5($identifier), 0, 16);
            $rateKey = '{' . $siteId . '}:ratelimit:api:' . $ipHash;

            // Atomic increment via gNodeClient typed method
            $currentCount = $this->gNodeClient->luaIncrBy($rateKey, 1);

            // Set expiration on first request (when count is 1)
            if ($currentCount === 1) {
                $this->gNodeClient->fcall('GNODE_CACHE_SET', [$rateKey], [(string)$currentCount, (string)$window]);
            }

            // Check if within limit
            $allowed = $currentCount <= $limit;
            $remaining = max(0, $limit - $currentCount);

            // Estimate reset time from window
            $resetAt = time() + $window;

            return [
                'allowed' => $allowed,
                'remaining' => $remaining,
                'reset_at' => $resetAt,
                'current_count' => $currentCount,
                'source' => 'valkey'
            ];

        } catch (\Throwable $e) {
            // Log error and fall back to in-memory
            error_log("SecurityManager: ValKey rate limit failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Initialize ValKey storage for rate limiting
     *
     * Uses shared gNode storage when available (eliminates duplicate connections),
     * falls back to gCore's getStorage() or creating a new instance.
     *
     * @return void
     */
    private function initializeValkeyStorage(): void {
        try {
            // SHARED ADAPTER PATH: Use pre-created gNodeStorageAdapter (maximum efficiency)
            // This adapter is created ONCE in gCore and shared by ALL managers
            if (isset($this->config['gnode_storage_adapter']) && $this->config['gnode_storage_adapter'] !== null) {
                // Use the SINGLE shared adapter directly - no wrapping needed
                $this->valkeyStorage = $this->config['gnode_storage_adapter'];
                return;
            }

            // Try to get storage adapter from gCore (preferred - FCALL-only)
            $gCore = \gCore\Modules\Core\gCore::getInstance();
            if ($gCore && method_exists($gCore, 'getStorageAdapter')) {
                $this->valkeyStorage = $gCore->getStorageAdapter();
                if ($this->valkeyStorage) {
                    return;
                }
            }

            // Fallback: try legacy getStorage() method
            if ($gCore && method_exists($gCore, 'getStorage')) {
                $this->valkeyStorage = $gCore->getStorage();
                if ($this->valkeyStorage) {
                    return;
                }
            }

            // NO LEGACY PATH: Do not create direct ValKeyStorage connections
            // If no shared adapter is available, valkeyStorage stays null
            // Rate limiting will fall back to in-memory (per-process) mode
            // This ensures all ValKey operations go through gNode-Client FCALL
        } catch (\Throwable $e) {
            error_log("SecurityManager: Failed to initialize ValKey storage: " . $e->getMessage());
            $this->valkeyStorage = null;
        }
    }

    /**
     * Check rate limit using StateManagerAware distributed counter (fallback)
     *
     * Uses incrementCounter with TTL for sliding-window rate limiting via
     * a different ValKey path (StateManager → CacheManager). When StateManager
     * is also unavailable, incrementCounter returns $delta, effectively
     * disabling rate limiting — which is the correct degradation behavior
     * (per-process static counters give false security in multi-worker setups).
     *
     * @param string $siteId Site identifier
     * @param string $identifier Client identifier
     * @param int $limit Max requests per window
     * @param int $window Window size in seconds
     * @param int $now Current timestamp
     * @return array Result with 'allowed', 'remaining', 'reset_at' keys
     */
    private function checkRateLimitInMemory(string $siteId, string $identifier, int $limit, int $window, int $now): array {
        $ipHash = substr(md5($identifier), 0, 16);
        $counterKey = "ratelimit:{$siteId}:{$ipHash}";

        $currentCount = $this->incrementCounter($counterKey, 1, $window);
        $remaining = max(0, $limit - $currentCount);
        $resetAt = $now + $window;

        return [
            'allowed' => $currentCount <= $limit,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'current_count' => $currentCount,
            'source' => $this->hasStateManager() ? 'state_manager' : 'degraded'
        ];
    }

    /**
     * Validate request parameters
     *
     * @param mixed $request Request object
     * @param array $rules Parameter validation rules
     * @return array Validation result
     */
    private function validateParams($request, array $rules): array {
        foreach ($rules as $paramName => $paramRules) {
            // Get parameter value from request
            $value = null;
            if (is_object($request)) {
                if (method_exists($request, 'get_param')) {
                    $value = $request->get_param($paramName);
                } elseif (isset($request->$paramName)) {
                    $value = $request->$paramName;
                } elseif (is_array($request) || $request instanceof \ArrayAccess) {
                    $value = $request[$paramName] ?? null;
                }
            } elseif (is_array($request)) {
                $value = $request[$paramName] ?? null;
            }

            // Check required
            if (!empty($paramRules['required']) && ($value === null || $value === '')) {
                return [
                    'valid' => false,
                    'error_code' => 'missing_parameter',
                    'error_message' => "Missing required parameter: {$paramName}"
                ];
            }

            // Skip further validation if value is null and not required
            if ($value === null) {
                continue;
            }

            // Type validation
            if (!empty($paramRules['type'])) {
                $typeValid = $this->validateType($value, $paramRules['type']);
                if (!$typeValid) {
                    return [
                        'valid' => false,
                        'error_code' => 'invalid_type',
                        'error_message' => "Parameter {$paramName} must be of type {$paramRules['type']}"
                    ];
                }
            }

            // Min/max validation for numbers
            if (isset($paramRules['min']) && is_numeric($value) && $value < $paramRules['min']) {
                return [
                    'valid' => false,
                    'error_code' => 'value_too_small',
                    'error_message' => "Parameter {$paramName} must be at least {$paramRules['min']}"
                ];
            }

            if (isset($paramRules['max']) && is_numeric($value) && $value > $paramRules['max']) {
                return [
                    'valid' => false,
                    'error_code' => 'value_too_large',
                    'error_message' => "Parameter {$paramName} must be at most {$paramRules['max']}"
                ];
            }

            // String length validation
            if (isset($paramRules['minLength']) && is_string($value) && strlen($value) < $paramRules['minLength']) {
                return [
                    'valid' => false,
                    'error_code' => 'string_too_short',
                    'error_message' => "Parameter {$paramName} must be at least {$paramRules['minLength']} characters"
                ];
            }

            if (isset($paramRules['maxLength']) && is_string($value) && strlen($value) > $paramRules['maxLength']) {
                return [
                    'valid' => false,
                    'error_code' => 'string_too_long',
                    'error_message' => "Parameter {$paramName} must be at most {$paramRules['maxLength']} characters"
                ];
            }

            // Pattern validation
            if (!empty($paramRules['pattern']) && is_string($value)) {
                if (!preg_match($paramRules['pattern'], $value)) {
                    return [
                        'valid' => false,
                        'error_code' => 'pattern_mismatch',
                        'error_message' => "Parameter {$paramName} does not match required pattern"
                    ];
                }
            }

            // Enum validation
            if (!empty($paramRules['enum']) && !in_array($value, $paramRules['enum'], true)) {
                $allowed = implode(', ', $paramRules['enum']);
                return [
                    'valid' => false,
                    'error_code' => 'invalid_enum_value',
                    'error_message' => "Parameter {$paramName} must be one of: {$allowed}"
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Validate value type
     *
     * @param mixed $value Value to validate
     * @param string $type Expected type
     * @return bool True if type is valid
     */
    private function validateType($value, string $type): bool {
        switch ($type) {
            case 'integer':
            case 'int':
                return is_int($value) || (is_string($value) && ctype_digit($value));
            case 'float':
            case 'number':
                return is_numeric($value);
            case 'string':
                return is_string($value);
            case 'boolean':
            case 'bool':
                return is_bool($value) || in_array($value, ['true', 'false', '0', '1', 0, 1], true);
            case 'array':
                return is_array($value);
            case 'object':
                return is_object($value) || (is_array($value) && !array_is_list($value));
            case 'email':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            case 'url':
                return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
            default:
                return true;
        }
    }
}
