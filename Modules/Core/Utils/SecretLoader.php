<?php
declare(strict_types=1);
/**
 * SecretLoader - Helper for loading secrets in configuration files
 *
 * Provides a lightweight utility for loading secrets from environment variables
 * or other secure sources, to avoid hardcoding credentials in configuration files.
 *
 * @package gCore
 * @subpackage Core\Utils
 */

namespace gCore\Modules\Core\Utils;

use gCore\Modules\Core\Exceptions\SecurityException;
use gCore\Modules\Core\Shared\SecretsManager;

class SecretLoader {
    /**
     * Secret placeholder marker in YAML/JSON files
     */
    const SECRET_PLACEHOLDER = '__SECRET__:';
    const ENV_PLACEHOLDER = '__ENV__:';
    
    /**
     * Load secrets in configuration array
     *
     * Recursively scans through configuration arrays to find and replace
     * secret placeholders with actual values.
     *
     * @param array $config Configuration array to process
     * @param string $accessLevel Access level of the requester
     * @return array Processed configuration with secrets loaded
     * @throws SecurityException If access to a secret is denied
     */
    public static function loadSecrets(array $config, string $accessLevel = 'system'): array {
        $result = [];
        
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::loadSecrets($value, $accessLevel);
            } elseif (is_string($value) && self::isSecretPlaceholder($value)) {
                $secretKey = self::extractSecretKey($value);
                $result[$key] = SecretsManager::getSecret($secretKey, $accessLevel);
            } elseif (is_string($value) && self::isEnvPlaceholder($value)) {
                $envVar = self::extractEnvVar($value);
                $result[$key] = getenv($envVar) ?: null;
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * Check if a value is a secret placeholder
     *
     * @param string $value The value to check
     * @return bool True if the value is a secret placeholder
     */
    public static function isSecretPlaceholder(string $value): bool {
        return strpos($value, self::SECRET_PLACEHOLDER) === 0;
    }
    
    /**
     * Check if a value is an environment variable placeholder
     *
     * @param string $value The value to check
     * @return bool True if the value is an environment variable placeholder
     */
    public static function isEnvPlaceholder(string $value): bool {
        return strpos($value, self::ENV_PLACEHOLDER) === 0;
    }
    
    /**
     * Extract the secret key from a placeholder
     *
     * @param string $placeholder The placeholder containing the secret key
     * @return string The extracted secret key
     */
    public static function extractSecretKey(string $placeholder): string {
        return substr($placeholder, strlen(self::SECRET_PLACEHOLDER));
    }
    
    /**
     * Extract the environment variable name from a placeholder
     *
     * @param string $placeholder The placeholder containing the environment variable
     * @return string The extracted environment variable name
     */
    public static function extractEnvVar(string $placeholder): string {
        return substr($placeholder, strlen(self::ENV_PLACEHOLDER));
    }
    
    /**
     * Create a secret placeholder for use in configuration files
     *
     * @param string $secretKey The secret key to reference
     * @return string The placeholder string
     */
    public static function createSecretPlaceholder(string $secretKey): string {
        return self::SECRET_PLACEHOLDER . $secretKey;
    }
    
    /**
     * Create an environment variable placeholder for use in configuration files
     *
     * @param string $envVar The environment variable name to reference
     * @return string The placeholder string
     */
    public static function createEnvPlaceholder(string $envVar): string {
        return self::ENV_PLACEHOLDER . $envVar;
    }
}