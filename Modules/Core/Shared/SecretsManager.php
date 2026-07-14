<?php
declare(strict_types=1);
/**
 * SecretsManager - Manages secrets and sensitive configuration values
 *
 * Provides a centralized way to handle secrets across the application with
 * support for multiple storage backends and proper access controls.
 *
 * @package gCore
 * @subpackage Core\Shared
 */

namespace gCore\Modules\Core\Shared;

use gCore\Modules\Core\Exceptions\SecurityException;
use gCore\Modules\Core\Exceptions\ValidationException;

class SecretsManager {
    /**
     * Secret storage backends
     */
    const BACKEND_ENV = 'env';
    const BACKEND_FILE = 'file';
    const BACKEND_VALKEY = 'valkey';
    const BACKEND_HSM = 'hsm';
    
    /**
     * Secret types with different security requirements
     */
    const TYPE_API_KEY = 'api_key';
    const TYPE_PASSWORD = 'password';
    const TYPE_CERTIFICATE = 'certificate';
    const TYPE_PRIVATE_KEY = 'private_key';
    const TYPE_TOKEN = 'token';
    const TYPE_CONNECTION = 'connection';
    const TYPE_LICENSE = 'license';

    /**
     * Mapping from environment variables to internal configuration paths
     * 
     * @var array
     */
    private static $envMapping = [
        // Security keys
        'GCORE_SECURITY_KEY' => 'security.encryption.key',
        'HSM_PIN' => 'security.hardware.pin',
        'HSM_MODULE_PATH' => 'security.hardware.module_path',
        'HSM_KEY_ID' => 'security.hardware.key_id',
        'HSM_SLOT_ID' => 'security.hardware.slot_id',
        'YUBIKEY_MANAGEMENT_KEY' => 'security.hardware.yubikey.management_key',
        
        // License keys
        'QUANTUM_RESISTANT_LICENSE_KEY' => 'security.licenses.quantum_resistant',
        'ADVANCED_ANALYTICS_LICENSE_KEY' => 'security.licenses.advanced_analytics',
        'CUSTOM_ALERTS_LICENSE_KEY' => 'security.licenses.custom_alerts',
        
        // Authentication credentials
        'SECURITY_FALLBACK_USER_ID' => 'security.authentication.fallback_user_id',
        'SECURITY_SESSION_KEY' => 'security.authentication.session_key',
        
        // Notification settings
        'NOTIFICATION_SMTP_HOST' => 'notifications.smtp.host',
        'NOTIFICATION_SMTP_PORT' => 'notifications.smtp.port',
        'NOTIFICATION_SMTP_USERNAME' => 'notifications.smtp.username',
        'NOTIFICATION_SMTP_PASSWORD' => 'notifications.smtp.password',
        'NOTIFICATION_FROM_EMAIL' => 'notifications.from.email',
        'NOTIFICATION_FROM_NAME' => 'notifications.from.name',
        
        // Email recipients
        'NOTIFICATION_ADMIN_EMAIL' => 'notifications.recipients.admin',
        'NOTIFICATION_DEVELOPER_EMAILS' => 'notifications.recipients.developers',
        'NOTIFICATION_OPS_EMAILS' => 'notifications.recipients.operations',
        'SECURITY_NOTIFICATION_EMAIL' => 'security.notifications.email',
        'SECURITY_ALERT_RECIPIENTS' => 'security.notifications.recipients',
        'SECURITY_CONTACT_EMAIL' => 'security.contact.email',
        
        // ValKey/Redis credentials
        'VALKEY_AUTH' => 'cache.auth',
        'VALKEY_USERNAME' => 'cache.username',
        'VALKEY_POOL_PASSWORD' => 'cache.pool.password',
        'VALKEY_TLS_CA_FILE' => 'cache.tls.ca_file',
        'VALKEY_TLS_CERT_FILE' => 'cache.tls.cert_file',
        'VALKEY_TLS_KEY_FILE' => 'cache.tls.key_file',
        'VALKEY_SENTINEL_PASSWORD' => 'cache.sentinel.password',
        'VALKEY_CLUSTER_AUTH' => 'cache.cluster.auth',
        'GCORE_CACHE_AUTH' => 'cache.auth_legacy',
    ];

    /**
     * Access level definition to determine who can access each secret
     * 
     * @var array
     */
    private static $accessLevels = [
        'security.encryption.key' => ['system'],
        'security.hardware.pin' => ['system'],
        'security.hardware.module_path' => ['system', 'admin'],
        'security.hardware.key_id' => ['system', 'admin'],
        'security.hardware.slot_id' => ['system', 'admin'],
        'security.hardware.yubikey.management_key' => ['system'],
        'security.licenses.*' => ['system', 'admin'],
        'cache.auth' => ['system'],
        'cache.username' => ['system'],
        'cache.pool.password' => ['system'],
        'cache.tls.*' => ['system'],
        'cache.sentinel.password' => ['system'],
        'cache.cluster.auth' => ['system'],
        'cache.auth_legacy' => ['system'],
        'notifications.smtp.password' => ['system'],
        'notifications.smtp.*' => ['system', 'admin'],
        'notifications.recipients.*' => ['system', 'admin'],
        'security.notifications.*' => ['system', 'admin'],
        'security.contact.*' => ['system', 'admin', 'public'],
    ];

    /**
     * Secret type mapping for validation and handling
     * 
     * @var array
     */
    private static $secretTypes = [
        'security.encryption.key' => self::TYPE_PRIVATE_KEY,
        'security.hardware.pin' => self::TYPE_PASSWORD,
        'security.hardware.yubikey.management_key' => self::TYPE_PRIVATE_KEY,
        'security.licenses.*' => self::TYPE_LICENSE,
        'cache.auth' => self::TYPE_PASSWORD,
        'cache.username' => self::TYPE_CONNECTION,
        'cache.pool.password' => self::TYPE_PASSWORD,
        'cache.tls.*' => self::TYPE_CERTIFICATE,
        'cache.sentinel.password' => self::TYPE_PASSWORD,
        'cache.cluster.auth' => self::TYPE_PASSWORD,
        'cache.auth_legacy' => self::TYPE_PASSWORD,
        'notifications.smtp.password' => self::TYPE_PASSWORD,
        'notifications.smtp.username' => self::TYPE_CONNECTION,
    ];

    /**
     * Cache of loaded secrets
     * 
     * @var array
     */
    private static $secrets = [];

    /**
     * Get a secret value
     *
     * @param string $key The secret key to retrieve
     * @param string $accessLevel Access level of the requester
     * @param string $preferredBackend Preferred backend for retrieval
     * @return mixed The secret value
     * @throws SecurityException If the access level is not authorized
     * @throws ValidationException If the secret key is invalid
     */
    public static function getSecret(string $key, string $accessLevel = 'system', string $preferredBackend = self::BACKEND_ENV) {
        // Check if we have access to this secret
        if (!self::hasAccess($key, $accessLevel)) {
            throw new SecurityException("Access denied to secret: $key with access level: $accessLevel");
        }

        // Check if already loaded in cache
        if (isset(self::$secrets[$key])) {
            return self::$secrets[$key];
        }

        // Get the environment variable name if it exists
        $envVar = array_search($key, self::$envMapping) ?: null;
        
        // Try to get from environment first (highest priority)
        if ($envVar && getenv($envVar) !== false) {
            $value = getenv($envVar);
            self::$secrets[$key] = $value;
            return $value;
        }

        // Try preferred backend
        switch ($preferredBackend) {
            case self::BACKEND_ENV:
                // Already tried above
                break;
                
            case self::BACKEND_FILE:
                $value = self::getFromFile($key);
                if ($value !== null) {
                    self::$secrets[$key] = $value;
                    return $value;
                }
                break;
                
            case self::BACKEND_VALKEY:
                $value = self::getFromValKey($key);
                if ($value !== null) {
                    self::$secrets[$key] = $value;
                    return $value;
                }
                break;
                
            case self::BACKEND_HSM:
                $value = self::getFromHSM($key);
                if ($value !== null) {
                    self::$secrets[$key] = $value;
                    return $value;
                }
                break;
        }

        // Fallback to other backends if preferred backend failed
        if ($preferredBackend !== self::BACKEND_FILE) {
            $value = self::getFromFile($key);
            if ($value !== null) {
                self::$secrets[$key] = $value;
                return $value;
            }
        }
        
        if ($preferredBackend !== self::BACKEND_VALKEY) {
            $value = self::getFromValKey($key);
            if ($value !== null) {
                self::$secrets[$key] = $value;
                return $value;
            }
        }
        
        if ($preferredBackend !== self::BACKEND_HSM) {
            $value = self::getFromHSM($key);
            if ($value !== null) {
                self::$secrets[$key] = $value;
                return $value;
            }
        }

        // If we get here, we couldn't find the secret
        if (self::isRequired($key)) {
            throw new ValidationException("Required secret not found: $key");
        }
        
        // Return null for non-required secrets
        return null;
    }

    /**
     * Set a secret value
     *
     * @param string $key The secret key to set
     * @param mixed $value The secret value
     * @param string $accessLevel Access level of the requester
     * @param string $backend Backend to store the secret in
     * @return bool True if successful
     * @throws SecurityException If the access level is not authorized
     * @throws ValidationException If the secret value is invalid
     */
    public static function setSecret(string $key, $value, string $accessLevel = 'system', string $backend = self::BACKEND_VALKEY) {
        // Check if we have access to set this secret
        if (!self::hasAccess($key, $accessLevel)) {
            throw new SecurityException("Access denied to set secret: $key with access level: $accessLevel");
        }

        // Validate the secret value
        if (!self::validateSecret($key, $value)) {
            throw new ValidationException("Invalid value for secret: $key");
        }

        // Store in the specified backend
        $result = false;
        switch ($backend) {
            case self::BACKEND_VALKEY:
                $result = self::setInValKey($key, $value);
                break;
                
            case self::BACKEND_FILE:
                $result = self::setInFile($key, $value);
                break;
                
            case self::BACKEND_HSM:
                $result = self::setInHSM($key, $value);
                break;
                
            case self::BACKEND_ENV:
                // Can't set environment variables at runtime
                throw new SecurityException("Cannot set environment variables at runtime");
        }

        // Update cache if successful
        if ($result) {
            self::$secrets[$key] = $value;
        }

        return $result;
    }

    /**
     * Delete a secret
     *
     * @param string $key The secret key to delete
     * @param string $accessLevel Access level of the requester
     * @param string|null $backend Specific backend to delete from (null for all)
     * @return bool True if successful
     * @throws SecurityException If the access level is not authorized
     */
    public static function deleteSecret(string $key, string $accessLevel = 'system', ?string $backend = null) {
        // Check if we have access to delete this secret
        if (!self::hasAccess($key, $accessLevel)) {
            throw new SecurityException("Access denied to delete secret: $key with access level: $accessLevel");
        }

        $result = true;
        
        // Delete from specified backend or all backends
        if ($backend === null || $backend === self::BACKEND_VALKEY) {
            $result = $result && self::deleteFromValKey($key);
        }
        
        if ($backend === null || $backend === self::BACKEND_FILE) {
            $result = $result && self::deleteFromFile($key);
        }
        
        if ($backend === null || $backend === self::BACKEND_HSM) {
            $result = $result && self::deleteFromHSM($key);
        }

        // Remove from cache
        if (isset(self::$secrets[$key])) {
            unset(self::$secrets[$key]);
        }

        return $result;
    }

    /**
     * Check if a secret exists
     *
     * @param string $key The secret key to check
     * @param string $accessLevel Access level of the requester
     * @param string|null $backend Specific backend to check (null for any)
     * @return bool True if the secret exists
     * @throws SecurityException If the access level is not authorized
     */
    public static function secretExists(string $key, string $accessLevel = 'system', ?string $backend = null) {
        // Check if we have access to check this secret
        if (!self::hasAccess($key, $accessLevel)) {
            throw new SecurityException("Access denied to check secret: $key with access level: $accessLevel");
        }

        // Check cache first
        if (isset(self::$secrets[$key])) {
            return true;
        }

        // Get the environment variable name if it exists
        $envVar = array_search($key, self::$envMapping) ?: null;
        
        // Check environment if no specific backend requested or env requested
        if (($backend === null || $backend === self::BACKEND_ENV) && $envVar && getenv($envVar) !== false) {
            return true;
        }

        // Check requested backends
        if ($backend === null || $backend === self::BACKEND_FILE) {
            if (self::existsInFile($key)) {
                return true;
            }
        }
        
        if ($backend === null || $backend === self::BACKEND_VALKEY) {
            if (self::existsInValKey($key)) {
                return true;
            }
        }
        
        if ($backend === null || $backend === self::BACKEND_HSM) {
            if (self::existsInHSM($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear the secrets cache
     *
     * @return void
     */
    public static function clearCache() {
        self::$secrets = [];
    }

    /**
     * Get a secret from a file
     *
     * @param string $key The secret key
     * @return mixed|null The secret value or null if not found
     */
    private static function getFromFile(string $key) {
        $configDir = dirname(dirname(dirname(dirname(__DIR__)))) . '/config';
        $secretsFile = $configDir . '/secrets.json';
        
        if (!file_exists($secretsFile)) {
            return null;
        }
        
        $secrets = json_decode(file_get_contents($secretsFile), true);
        if (!$secrets || !is_array($secrets)) {
            return null;
        }
        
        // Convert dot notation to nested array access
        $parts = explode('.', $key);
        $current = $secrets;
        
        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                return null;
            }
            $current = $current[$part];
        }
        
        return $current;
    }

    /**
     * Set a secret in a file
     *
     * @param string $key The secret key
     * @param mixed $value The secret value
     * @return bool True if successful
     */
    private static function setInFile(string $key, $value) {
        $configDir = dirname(dirname(dirname(dirname(__DIR__)))) . '/config';
        $secretsFile = $configDir . '/secrets.json';
        
        // Create secrets file if it doesn't exist
        if (!file_exists($secretsFile)) {
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }
            file_put_contents($secretsFile, json_encode([], JSON_PRETTY_PRINT));
            chmod($secretsFile, 0600); // Restrictive permissions
        }
        
        $secrets = json_decode(file_get_contents($secretsFile), true) ?: [];
        
        // Convert dot notation to nested array
        $parts = explode('.', $key);
        $current = &$secrets;
        
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }
        
        return file_put_contents($secretsFile, json_encode($secrets, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Check if a secret exists in a file
     *
     * @param string $key The secret key
     * @return bool True if the secret exists
     */
    private static function existsInFile(string $key) {
        return self::getFromFile($key) !== null;
    }

    /**
     * Delete a secret from a file
     *
     * @param string $key The secret key
     * @return bool True if successful
     */
    private static function deleteFromFile(string $key) {
        $configDir = dirname(dirname(dirname(dirname(__DIR__)))) . '/config';
        $secretsFile = $configDir . '/secrets.json';
        
        if (!file_exists($secretsFile)) {
            return true; // Already doesn't exist
        }
        
        $secrets = json_decode(file_get_contents($secretsFile), true);
        if (!$secrets || !is_array($secrets)) {
            return false;
        }
        
        // Convert dot notation to nested array access for deletion
        $parts = explode('.', $key);
        $current = &$secrets;
        $path = [];
        
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                unset($current[$part]);
            } else {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    return true; // Key doesn't exist, so already "deleted"
                }
                $path[] = &$current;
                $current = &$current[$part];
            }
        }
        
        // Clean up empty arrays
        for ($i = count($path) - 1; $i >= 0; $i--) {
            $partIndex = $i + 1;
            if (empty($path[$i][$parts[$partIndex]])) {
                unset($path[$i][$parts[$partIndex]]);
            }
        }
        
        return file_put_contents($secretsFile, json_encode($secrets, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Get ValKey connection configuration from environment variables.
     * No hardcoded fallbacks — env vars must be set.
     *
     * @return array ValKey config array
     * @throws \RuntimeException If required env vars are missing
     */
    private static function getValKeyConfig(): array {
        $host = getenv('VALKEY_HOST');
        $port = getenv('VALKEY_PORT');
        if (!$host || !$port) {
            throw new \RuntimeException(
                'SecretsManager requires VALKEY_HOST and VALKEY_PORT environment variables'
            );
        }
        return [
            'host' => $host,
            'port' => (int)$port,
            'timeout' => 2.0,
            'read_timeout' => 2.0,
            'persistent' => false,
            'prefix' => 'gcore_secrets:'
        ];
    }

    /**
     * Get a secret from ValKey/Redis
     *
     * @param string $key The secret key
     * @return mixed|null The secret value or null if not found
     */
    private static function getFromValKey(string $key) {
        try {
            // Make sure we can access ValKey
            if (!class_exists('\\gCore\\Core\\Adapters\\Shared\\ValKeyStorage')) {
                return null;
            }

            // We need special initialization to avoid circular dependencies
            $valkey = \gCore\Core\Adapters\Shared\ValKeyStorage::getInstance(self::getValKeyConfig());
            
            // Check if secret exists
            if (!$valkey->exists($key)) {
                return null;
            }

            // Get the secret
            $value = $valkey->get($key);
            if ($value === false) {
                return null;
            }

            // Check if it's JSON encoded
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return $value;
        } catch (\Exception $e) {
            error_log("[gCore] SecretsManager::getFromValKey failed for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set a secret in ValKey/Redis
     *
     * @param string $key The secret key
     * @param mixed $value The secret value
     * @return bool True if successful
     */
    private static function setInValKey(string $key, $value) {
        try {
            // Make sure we can access ValKey
            if (!class_exists('\\gCore\\Core\\Adapters\\Shared\\ValKeyStorage')) {
                return false;
            }

            // We need special initialization to avoid circular dependencies
            $valkey = \gCore\Core\Adapters\Shared\ValKeyStorage::getInstance(self::getValKeyConfig());

            // Encode complex values as JSON
            $valueToStore = is_array($value) || is_object($value) ? json_encode($value) : $value;
            
            // Store the secret
            return $valkey->set($key, $valueToStore) !== false;
        } catch (\Exception $e) {
            error_log("[gCore] SecretsManager::setInValKey failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a secret exists in ValKey/Redis
     *
     * @param string $key The secret key
     * @return bool True if the secret exists
     */
    private static function existsInValKey(string $key) {
        try {
            // Make sure we can access ValKey
            if (!class_exists('\\gCore\\Core\\Adapters\\Shared\\ValKeyStorage')) {
                return false;
            }

            // We need special initialization to avoid circular dependencies
            $valkey = \gCore\Core\Adapters\Shared\ValKeyStorage::getInstance(self::getValKeyConfig());

            return $valkey->exists($key);
        } catch (\Exception $e) {
            error_log("[gCore] SecretsManager::existsInValKey failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a secret from ValKey/Redis
     *
     * @param string $key The secret key
     * @return bool True if successful
     */
    private static function deleteFromValKey(string $key) {
        try {
            // Make sure we can access ValKey
            if (!class_exists('\\gCore\\Core\\Adapters\\Shared\\ValKeyStorage')) {
                return false;
            }

            // We need special initialization to avoid circular dependencies
            $valkey = \gCore\Core\Adapters\Shared\ValKeyStorage::getInstance(self::getValKeyConfig());

            return $valkey->del($key) !== false;
        } catch (\Exception $e) {
            error_log("[gCore] SecretsManager::deleteFromValKey failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a secret from Hardware Security Module
     *
     * @param string $key The secret key
     * @return mixed|null The secret value or null if not found
     */
    private static function getFromHSM(string $key) {
        // HSM is only used for specific keys
        $hsmKeys = [
            'security.hardware.pin',
            'security.hardware.yubikey.management_key',
            'security.encryption.key'
        ];
        
        if (!in_array($key, $hsmKeys)) {
            return null;
        }

        try {
            // Check if HardwareSecurityTrait is available
            if (!class_exists('\\gCore\\Managers\\Base\\SecurityManager\\Traits\\HardwareSecurityTrait')) {
                return null;
            }

            // This would require more complex implementation and depends on the actual HSM setup
            // For now, return null to indicate not implemented
            return null;
        } catch (\Exception $e) {
            error_log("[gCore] SecretsManager::getFromHSM failed for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set a secret in Hardware Security Module
     *
     * @param string $key The secret key
     * @param mixed $value The secret value
     * @return bool True if successful
     */
    private static function setInHSM(string $key, $value) {
        // Not implemented - HSM typically doesn't allow setting secrets this way
        return false;
    }

    /**
     * Check if a secret exists in Hardware Security Module
     *
     * @param string $key The secret key
     * @return bool True if the secret exists
     */
    private static function existsInHSM(string $key) {
        return self::getFromHSM($key) !== null;
    }

    /**
     * Delete a secret from Hardware Security Module
     *
     * @param string $key The secret key
     * @return bool True if successful
     */
    private static function deleteFromHSM(string $key) {
        // Not implemented - HSM typically doesn't allow deleting secrets this way
        return false;
    }

    /**
     * Check if the requester has access to the secret
     *
     * @param string $key The secret key
     * @param string $accessLevel Access level of the requester
     * @return bool True if access is allowed
     */
    private static function hasAccess(string $key, string $accessLevel) {
        // If we have an exact match for the key
        if (isset(self::$accessLevels[$key])) {
            return in_array($accessLevel, self::$accessLevels[$key]);
        }
        
        // Check for wildcard matches
        foreach (self::$accessLevels as $pattern => $allowedLevels) {
            // Convert to regex pattern
            $regex = '/^' . str_replace(['*', '.'], ['[^.]+', '\\.'], $pattern) . '$/';
            
            if (preg_match($regex, $key) && in_array($accessLevel, $allowedLevels)) {
                return true;
            }
        }
        
        // Default to system only for undefined keys
        return $accessLevel === 'system';
    }

    /**
     * Validate a secret value
     *
     * @param string $key The secret key
     * @param mixed $value The secret value
     * @return bool True if the value is valid
     */
    private static function validateSecret(string $key, $value) {
        // Get the secret type
        $type = self::getSecretType($key);
        
        // Validate based on type
        switch ($type) {
            case self::TYPE_API_KEY:
                // API keys should be strings with minimum length
                return is_string($value) && strlen($value) >= 16;
                
            case self::TYPE_PASSWORD:
                // Passwords should be strings, non-empty
                return is_string($value) && !empty($value);
                
            case self::TYPE_CERTIFICATE:
                // Certificates could be paths or contents
                return is_string($value) && !empty($value);
                
            case self::TYPE_PRIVATE_KEY:
                // Private keys should be strings with minimum length
                return is_string($value) && strlen($value) >= 16;
                
            case self::TYPE_TOKEN:
                // Tokens should be strings with minimum length
                return is_string($value) && strlen($value) >= 8;
                
            case self::TYPE_CONNECTION:
                // Connection info could be string or array
                return is_string($value) || is_array($value);
                
            case self::TYPE_LICENSE:
                // License keys should be strings
                return is_string($value) && !empty($value);
                
            default:
                // Default validation - accept anything non-empty
                return !empty($value);
        }
    }

    /**
     * Get the type of a secret
     *
     * @param string $key The secret key
     * @return string The secret type
     */
    private static function getSecretType(string $key) {
        // Direct match
        if (isset(self::$secretTypes[$key])) {
            return self::$secretTypes[$key];
        }
        
        // Wildcard match
        foreach (self::$secretTypes as $pattern => $type) {
            // Convert to regex pattern
            $regex = '/^' . str_replace(['*', '.'], ['[^.]+', '\\.'], $pattern) . '$/';
            
            if (preg_match($regex, $key)) {
                return $type;
            }
        }
        
        // Default type
        return self::TYPE_PASSWORD;
    }

    /**
     * Check if a secret is required
     *
     * @param string $key The secret key
     * @return bool True if the secret is required
     */
    private static function isRequired(string $key) {
        // List of required secrets
        $requiredSecrets = [
            'security.encryption.key',
            'cache.auth',
            'notifications.smtp.password'
        ];
        
        return in_array($key, $requiredSecrets);
    }
}