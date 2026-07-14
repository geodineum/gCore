<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Adapters\Security;

use gCore\Modules\Core\Interfaces\Security\SecurityCapabilityInterface;
use gCore\Modules\Core\Exceptions\AuthorizationException;

class StandaloneCapabilityChecker implements SecurityCapabilityInterface {
    /** @var array Configuration */
    private $config;
    
    /** @var array User role map */
    private $role_map = [];
    
    /** @var array Role hierarchy */
    private $role_hierarchy = [
        'administrator' => ['editor', 'author', 'contributor', 'subscriber'],
        'editor' => ['author', 'contributor', 'subscriber'],
        'author' => ['contributor', 'subscriber'],
        'contributor' => ['subscriber']
    ];
    
    /** @var array|null Current user */
    private $current_user = null;
    
    /**
     * Constructor
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'default_capability' => 'administrator',
            'capability_map' => [
                'view' => 'subscriber',
                'edit' => 'author',
                'delete' => 'editor',
                'manage' => 'administrator'
            ],
            'allow_fallback' => false,
            'fallback_user_id' => 1,
            'session_key' => 'gcore_user_session'
        ], $config);
        
        // Initialize role hierarchy from config if provided
        if (isset($config['role_hierarchy'])) {
            $this->role_hierarchy = array_merge($this->role_hierarchy, $config['role_hierarchy']);
        }
        
        // Initialize capability mapping
        $this->initializeRoleMap();
        
        // Set current user from session
        $this->initializeCurrentUser();
    }
    
    /**
     * Check if user has capability
     */
    public function hasCapability(string $capability): bool {
        // Check if user is logged in
        if (!$this->current_user) {
            return false;
        }
        
        // Direct capability match
        if (isset($this->current_user['capabilities'][$capability])) {
            return (bool)$this->current_user['capabilities'][$capability];
        }
        
        // Map to role if needed
        $required_role = $this->config['capability_map'][$capability] ?? $this->config['default_capability'];
        $user_role = $this->current_user['role'] ?? '';
        
        // Check if user has the role or higher
        return $this->hasRole($user_role, $required_role);
    }
    
    /**
     * Get current user ID
     */
    public function getCurrentUserId(): ?int {
        return $this->current_user ? (int)$this->current_user['id'] : null;
    }
    
    /**
     * Initialize role-capability map
     */
    private function initializeRoleMap(): void {
        // Standard WordPress-like roles for standalone mode
        $this->role_map = [
            'administrator' => [
                'manage_options' => true,
                'edit_posts' => true,
                'delete_posts' => true,
                'view_posts' => true,
                'manage_security' => true
            ],
            'editor' => [
                'edit_posts' => true,
                'delete_posts' => true,
                'view_posts' => true
            ],
            'author' => [
                'edit_posts' => true,
                'view_posts' => true
            ],
            'contributor' => [
                'edit_posts' => true,
                'view_posts' => true
            ],
            'subscriber' => [
                'view_posts' => true
            ]
        ];
        
        // Merge with provided role map if available
        if (isset($this->config['role_map'])) {
            foreach ($this->config['role_map'] as $role => $capabilities) {
                if (isset($this->role_map[$role])) {
                    $this->role_map[$role] = array_merge($this->role_map[$role], $capabilities);
                } else {
                    $this->role_map[$role] = $capabilities;
                }
            }
        }
    }
    
    /**
     * Check if user has the given role in hierarchy
     */
    private function hasRole(string $userRole, string $requiredRole): bool {
        // Direct match
        if ($userRole === $requiredRole) {
            return true;
        }
        
        // Check role hierarchy
        if (isset($this->role_hierarchy[$userRole])) {
            return in_array($requiredRole, $this->role_hierarchy[$userRole]);
        }
        
        return false;
    }
    
    /**
     * Initialize current user from session
     */
    private function initializeCurrentUser(): void {
        // For standalone mode, check session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $session_key = $this->config['session_key'];
        
        if (isset($_SESSION[$session_key])) {
            $this->current_user = $_SESSION[$session_key];
            return;
        }
        
        // Fallback user if allowed
        if ($this->config['allow_fallback']) {
            $this->current_user = [
                'id' => $this->config['fallback_user_id'],
                'role' => 'administrator',
                'capabilities' => $this->role_map['administrator']
            ];
        }
    }
    
    /**
     * Set current user for testing/CLI usage
     */
    public function setCurrentUser(int $id, string $role, array $capabilities = []): void {
        $this->current_user = [
            'id' => $id,
            'role' => $role,
            'capabilities' => $capabilities ?: ($this->role_map[$role] ?? [])
        ];
        
        // Store in session if available
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$this->config['session_key']] = $this->current_user;
        }
    }
    
    /**
     * Clear current user (logout)
     */
    public function clearCurrentUser(): void {
        $this->current_user = null;
        
        // Clear from session if available
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[$this->config['session_key']]);
        }
    }
    
    /**
     * Get user capabilities for a role
     */
    public function getRoleCapabilities(string $role): array {
        return $this->role_map[$role] ?? [];
    }
    
    /**
     * Get all registered roles
     */
    public function getAvailableRoles(): array {
        return array_keys($this->role_map);
    }
}