<?php
/**
 * gCore Framework - Security Extension for Message Broker
 * 
 * This extension adds API key authentication support to the SecurityManager.
 */

namespace gCore\Examples\MessageBroker;

/**
 * SecurityExtension - Extends the base SecurityManager with API key functionality
 */
class SecurityExtension {
    private $cacheManager;
    private $errorManager;
    private $securityManager;
    private $userPrefix = 'mb:user:';
    private $apiKeyPrefix = 'mb:api_key:';
    
    /**
     * Constructor
     */
    public function __construct($cacheManager, $errorManager, $securityManager) {
        $this->cacheManager = $cacheManager;
        $this->errorManager = $errorManager;
        $this->securityManager = $securityManager;
    }
    
    /**
     * Register a user with associated metadata
     * 
     * @param string $userId User identifier
     * @param array $userData User data including role and API key
     * @return bool Success status
     */
    public function registerUser($userId, array $userData) {
        try {
            // Validate input
            if (empty($userId)) {
                $this->errorManager->trackError('warning', 'Empty user ID in registerUser', ['data' => $userData]);
                return false;
            }
            
            if (empty($userData['api_key'])) {
                // Generate API key if not provided
                $userData['api_key'] = $this->generateApiKey();
            }
            
            // Store the API key mapping
            $this->cacheManager->set(
                $this->apiKeyPrefix . $userData['api_key'],
                $userId,
                0 // No expiration
            );
            
            // Store user data
            $userData['id'] = $userId; // Ensure ID is in the data
            $this->cacheManager->set(
                $this->userPrefix . $userId,
                $userData,
                0 // No expiration
            );
            
            // Assign role if specified
            if (!empty($userData['role'])) {
                $this->securityManager->assignRole($userId, $userData['role']);
            }
            
            $this->errorManager->log('info', 'Registered user', [
                'user_id' => $userId,
                'role' => $userData['role'] ?? 'none'
            ]);
            
            return true;
        } catch (\Exception $e) {
            $this->errorManager->handleException($e, [
                'context' => 'registerUser',
                'user_id' => $userId
            ]);
            return false;
        }
    }
    
    /**
     * Get user by API key
     * 
     * @param string $apiKey The API key to look up
     * @return array|null User data or null if not found
     */
    public function getUserByApiKey($apiKey) {
        try {
            if (empty($apiKey)) {
                return null;
            }
            
            // Look up user ID by API key
            $userId = $this->cacheManager->get($this->apiKeyPrefix . $apiKey);
            
            if (!$userId) {
                return null;
            }
            
            // Get user data
            return $this->getUserById($userId);
        } catch (\Exception $e) {
            $this->errorManager->handleException($e, [
                'context' => 'getUserByApiKey',
                'api_key_length' => strlen($apiKey)
            ]);
            return null;
        }
    }
    
    /**
     * Get user by ID
     * 
     * @param string $userId The user ID
     * @return array|null User data or null if not found
     */
    public function getUserById(string $userId) {
        try {
            if (empty($userId)) {
                return null;
            }
            
            // Get user data
            return $this->cacheManager->get($this->userPrefix . $userId);
        } catch (\Exception $e) {
            $this->errorManager->handleException($e, [
                'context' => 'getUserById',
                'user_id' => $userId
            ]);
            return null;
        }
    }
    
    /**
     * Alias for getUserById to maintain compatibility with SecurityManager
     * 
     * @param string $userId The user ID or API key
     * @return array|null User data or null if not found
     */
    public function getUser($userId) {
        return $this->getUserById($userId);
    }
    
    /**
     * Generate a secure API key
     * 
     * @return string Generated API key
     */
    private function generateApiKey() {
        return bin2hex(random_bytes(16)); // 32 character hex string
    }
}