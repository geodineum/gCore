<?php
/**
 * gCore Framework Demo
 * 
 * This example demonstrates the core capabilities of gCore framework,
 * including geometric topology for service discovery, security features,
 * caching, and error management.
 */

// Set error reporting for better debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constants
define('TESTING', true);
define('GCORE_CONFIG_PATH', __DIR__ . '/../config');
define('ABSPATH', __DIR__ . '/..');

echo "gCore Framework Demo\n";
echo "================================\n\n";

// Now create a simpler test script that shows off all managers
class SimplifiedGCore {
    private static $instance = null;
    private $errorManager = null;
    private $cacheManager = null;
    private $securityManager = null;
    private $initialized = false;
    
    // Singleton pattern
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Initialize all managers
    public function initialize() {
        echo "Initializing gCore framework...\n";
        
        // Initialize ErrorManager first (often needs to exist for logging)
        echo "Initializing ErrorManager...\n";
        $this->errorManager = SimplifiedErrorManager::getInstance();
        $this->errorManager->initialize([
            'enabled' => true,
            'log_level' => 'debug'
        ]);
        
        // Next initialize CacheManager (often needed by other managers)
        echo "Initializing CacheManager...\n";
        $this->cacheManager = SimplifiedCacheManager::getInstance();
        $this->cacheManager->initialize([
            'enabled' => true,
            'connection' => 'localhost:6379'
        ]);
        
        // Finally initialize SecurityManager
        echo "Initializing SecurityManager...\n";
        $this->securityManager = SimplifiedSecurityManager::getInstance();
        $this->securityManager->initialize([
            'enabled' => true,
            'rules' => ['default']
        ]);
        
        $this->initialized = true;
        echo "All managers initialized successfully!\n";
    }
    
    // Get manager instance
    public function getManager($managerName) {
        if (!$this->initialized) {
            throw new Exception("gCore not initialized");
        }
        
        switch ($managerName) {
            case 'ErrorManager':
                return $this->errorManager;
            case 'CacheManager':
                return $this->cacheManager;
            case 'SecurityManager':
                return $this->securityManager;
            default:
                throw new Exception("Unknown manager: $managerName");
        }
    }
    
    // Shutdown all managers in reverse order
    public function shutdown() {
        echo "Shutting down gCore...\n";
        
        if ($this->securityManager) {
            echo "Shutting down SecurityManager...\n";
            $this->securityManager->shutdown();
        }
        
        if ($this->cacheManager) {
            echo "Shutting down CacheManager...\n";
            $this->cacheManager->shutdown();
        }
        
        if ($this->errorManager) {
            echo "Shutting down ErrorManager...\n";
            $this->errorManager->shutdown();
        }
        
        $this->initialized = false;
        echo "All managers shut down successfully!\n";
    }
}

// Simplified manager base class
abstract class ManagerBase {
    protected $initialized = false;
    protected $config = [];
    
    public function initialize(array $config) {
        $this->config = $config;
        $this->initialized = true;
    }
    
    public function isInitialized() {
        return $this->initialized;
    }
    
    public function getConfig() {
        return $this->config;
    }
    
    public function shutdown() {
        $this->initialized = false;
    }
}

// Simplified ErrorManager
class SimplifiedErrorManager extends ManagerBase {
    private static $instance = null;
    
    // Singleton pattern
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Error tracking
    public function trackError($level, $message, $context = []) {
        if (!$this->initialized) {
            echo "ERROR: ErrorManager not initialized\n";
            return;
        }
        
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        echo "[ErrorManager] $level: $message$contextStr\n";
    }
    
    // Log method
    public function log($level, $message, $context = []) {
        return $this->trackError($level, $message, $context);
    }
    
    // Notification
    public function notifyAdmin($subject, $message, $details = []) {
        if (!$this->initialized) {
            echo "ERROR: ErrorManager not initialized\n";
            return;
        }
        
        echo "[ErrorManager] NOTIFICATION: $subject - $message\n";
        if (!empty($details)) {
            echo "  Details: " . json_encode($details) . "\n";
        }
    }
    
    // Exception handling
    public function handleException(\Exception $e, $context = []) {
        if (!$this->initialized) {
            echo "ERROR: ErrorManager not initialized\n";
            return;
        }
        
        $class = get_class($e);
        echo "[ErrorManager] EXCEPTION $class: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n";
    }
}

// Simplified CacheManager
class SimplifiedCacheManager extends ManagerBase {
    private static $instance = null;
    private $cache = [];
    
    // Singleton pattern
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Basic cache operations
    public function set($key, $value, $ttl = 3600) {
        if (!$this->initialized) {
            echo "ERROR: CacheManager not initialized\n";
            return false;
        }
        
        $this->cache[$key] = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        echo "[CacheManager] SET $key = " . json_encode($value) . " (TTL: $ttl)\n";
        return true;
    }
    
    public function get($key) {
        if (!$this->initialized) {
            echo "ERROR: CacheManager not initialized\n";
            return null;
        }
        
        echo "[CacheManager] GET $key\n";
        
        if (!isset($this->cache[$key])) {
            return null;
        }
        
        if ($this->cache[$key]['expires'] < time()) {
            unset($this->cache[$key]);
            return null;
        }
        
        return $this->cache[$key]['value'];
    }
    
    // Hash operations
    public function hSet($key, $fields) {
        if (!$this->initialized) {
            echo "ERROR: CacheManager not initialized\n";
            return false;
        }
        
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = ['value' => [], 'expires' => time() + 3600];
        }
        
        foreach ($fields as $field => $value) {
            $this->cache[$key]['value'][$field] = $value;
        }
        
        echo "[CacheManager] HSET $key " . count($fields) . " fields\n";
        return true;
    }
    
    public function hGet($key, $field) {
        if (!$this->initialized) {
            echo "ERROR: CacheManager not initialized\n";
            return null;
        }
        
        echo "[CacheManager] HGET $key $field\n";
        
        if (!isset($this->cache[$key]) || !isset($this->cache[$key]['value'][$field])) {
            return null;
        }
        
        return $this->cache[$key]['value'][$field];
    }
    
    // Stream operations
    public function streamAdd($key, $fields) {
        if (!$this->initialized) {
            echo "ERROR: CacheManager not initialized\n";
            return false;
        }
        
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = ['value' => [], 'expires' => time() + 3600, 'type' => 'stream'];
        }
        
        // Use string ID to avoid float-to-int precision warning
        $id = (string)microtime(true);
        $this->cache[$key]['value'][$id] = $fields;
        
        echo "[CacheManager] XADD $key $id " . json_encode($fields) . "\n";
        return $id;
    }
}

// Simplified SecurityManager
class SimplifiedSecurityManager extends ManagerBase {
    private static $instance = null;
    private $roles = [];
    private $userRoles = [];
    
    // Singleton pattern
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Access control
    public function defineRole($role, $permissions) {
        if (!$this->initialized) {
            echo "ERROR: SecurityManager not initialized\n";
            return false;
        }
        
        $this->roles[$role] = $permissions;
        echo "[SecurityManager] Defined role $role with " . count($permissions) . " permissions\n";
        return true;
    }
    
    public function assignRole($user, $role) {
        if (!$this->initialized) {
            echo "ERROR: SecurityManager not initialized\n";
            return false;
        }
        
        if (!isset($this->roles[$role])) {
            echo "[SecurityManager] WARNING: Role $role does not exist\n";
            return false;
        }
        
        $this->userRoles[$user] = $role;
        echo "[SecurityManager] Assigned role $role to user $user\n";
        return true;
    }
    
    public function hasPermission($user, $permission) {
        if (!$this->initialized) {
            echo "ERROR: SecurityManager not initialized\n";
            return false;
        }
        
        echo "[SecurityManager] Checking if $user has '$permission' permission\n";
        
        if (!isset($this->userRoles[$user])) {
            return false;
        }
        
        $role = $this->userRoles[$user];
        if (!isset($this->roles[$role])) {
            return false;
        }
        
        return in_array($permission, $this->roles[$role]);
    }
    
    // Data encryption
    public function encrypt($data) {
        if (!$this->initialized) {
            echo "ERROR: SecurityManager not initialized\n";
            return null;
        }
        
        // Simple mock encryption (base64 encoding)
        return 'ENCRYPTED:' . base64_encode($data);
    }
    
    public function decrypt($data) {
        if (!$this->initialized) {
            echo "ERROR: SecurityManager not initialized\n";
            return null;
        }
        
        // Simple mock decryption
        if (strpos($data, 'ENCRYPTED:') !== 0) {
            return null;
        }
        
        return base64_decode(substr($data, 10));
    }
    
    // Input sanitization
    public function sanitizeInput($input) {
        if (!$this->initialized) {
            echo "ERROR: SecurityManager not initialized\n";
            return $input;
        }
        
        // Simple sanitization (strip tags)
        return strip_tags($input);
    }
}

// Initialize the geometric topology system
echo "=== Geometric Topology System Demo ===\n";
echo "Initializing geometric topology system...\n";

// Create a representation of capability dimensions
$capabilityDimensions = [
    'security' => 0,
    'reliability' => 1,
    'storage' => 2,
    'cache' => 3,
    'compute' => 4,
    'error_handling' => 5,
    'api' => 6,
    'scalability' => 7
];

// Register some example services with different capabilities
$services = [
    'AuthService' => [
        'security' => 0.9,
        'reliability' => 0.8,
        'compute' => 0.6
    ],
    'CacheService' => [
        'cache' => 0.9,
        'storage' => 0.8,
        'scalability' => 0.8
    ],
    'ErrorService' => [
        'error_handling' => 0.9,
        'reliability' => 0.9
    ],
    'APIGateway' => [
        'api' => 0.9,
        'security' => 0.7,
        'scalability' => 0.7
    ]
];

// Create representation of n-dimensional capability space
echo "Creating n-dimensional capability manifold...\n";
foreach ($services as $name => $capabilities) {
    echo "- Registered $name with capabilities: ";
    $capList = [];
    foreach ($capabilities as $capability => $value) {
        $capList[] = "$capability($value)";
    }
    echo implode(", ", $capList) . "\n";
}

// Simulate service discovery
echo "\nPerforming service discovery queries:\n";

// Example queries
$queries = [
    'Secure Authentication' => ['security' => 0.8],
    'High Performance Caching' => ['cache' => 0.8, 'scalability' => 0.7],
    'Error Handling' => ['error_handling' => 0.8, 'reliability' => 0.8],
    'API Handling' => ['api' => 0.8]
];

// Process each query
foreach ($queries as $queryName => $requirements) {
    echo "- Query for '$queryName': ";
    
    // Simulate finding services matching requirements
    $matches = [];
    foreach ($services as $serviceName => $capabilities) {
        $meetsRequirements = true;
        
        foreach ($requirements as $reqCapability => $reqValue) {
            if (!isset($capabilities[$reqCapability]) || $capabilities[$reqCapability] < $reqValue) {
                $meetsRequirements = false;
                break;
            }
        }
        
        if ($meetsRequirements) {
            $matches[] = $serviceName;
        }
    }
    
    if (empty($matches)) {
        echo "No matching services\n";
    } else {
        echo "Found " . implode(", ", $matches) . "\n";
    }
}

// Initialize gCore framework with manager implementations
echo "\n=== gCore Manager Demonstration ===\n";
$gCore = SimplifiedGCore::getInstance();
$gCore->initialize();

// Test Error Manager
echo "\n--- Error Manager Functionality ---\n";
$errorManager = $gCore->getManager('ErrorManager');

// Track errors at different levels
$errorManager->trackError('debug', 'This is a debug message');
$errorManager->trackError('info', 'This is an info message');
$errorManager->trackError('warning', 'This is a warning message', ['source' => 'user_module']);
$errorManager->trackError('error', 'This is an error message', ['user_id' => 123]);
$errorManager->trackError('critical', 'This is a critical message', ['service' => 'payment_gateway']);

// Send admin notification
$errorManager->notifyAdmin('Security Alert', 'Multiple failed login attempts', [
    'ip' => '192.168.1.100',
    'attempts' => 5
]);

// Test Cache Manager
echo "\n--- Cache Manager Functionality ---\n";
$cacheManager = $gCore->getManager('CacheManager');

// Set and get a value
$cacheManager->set('user:123', ['id' => 123, 'name' => 'John Doe']);
$user = $cacheManager->get('user:123');
echo "Retrieved user: " . json_encode($user) . "\n";

// Hash operations
$cacheManager->hSet('profile:456', [
    'name' => 'Jane Smith',
    'email' => 'jane@example.com',
    'role' => 'admin'
]);
$name = $cacheManager->hGet('profile:456', 'name');
echo "Retrieved name from hash: $name\n";

// Stream operations
$cacheManager->streamAdd('events:login', [
    'user_id' => 123,
    'timestamp' => time(),
    'ip' => '192.168.1.100'
]);

// Test Security Manager
echo "\n--- Security Manager Functionality ---\n";
$securityManager = $gCore->getManager('SecurityManager');

// Set up roles and permissions
$securityManager->defineRole('admin', ['view_dashboard', 'edit_users', 'delete_users']);
$securityManager->defineRole('editor', ['view_dashboard', 'edit_users']);
$securityManager->defineRole('viewer', ['view_dashboard']);

// Assign roles to users
$securityManager->assignRole('user1', 'admin');
$securityManager->assignRole('user2', 'editor');
$securityManager->assignRole('user3', 'viewer');

// Check permissions
echo "Permission checks:\n";
echo "- user1 can delete_users: " . ($securityManager->hasPermission('user1', 'delete_users') ? 'Yes' : 'No') . "\n";
echo "- user2 can edit_users: " . ($securityManager->hasPermission('user2', 'edit_users') ? 'Yes' : 'No') . "\n";
echo "- user3 can edit_users: " . ($securityManager->hasPermission('user3', 'edit_users') ? 'Yes' : 'No') . "\n";

// Data encryption
$sensitiveData = "This is sensitive data that should be encrypted";
echo "Original data: $sensitiveData\n";
$encrypted = $securityManager->encrypt($sensitiveData);
echo "Encrypted data: $encrypted\n";
$decrypted = $securityManager->decrypt($encrypted);
echo "Decrypted data: $decrypted\n";

// Input sanitization
$dangerousInput = '<script>alert("XSS Attack!");</script>';
echo "Dangerous input: $dangerousInput\n";
$sanitized = $securityManager->sanitizeInput($dangerousInput);
echo "Sanitized input: $sanitized\n";

// Shutdown gCore
echo "\n=== Shutting Down gCore ===\n";
$gCore->shutdown();

echo "\nThis demo showcases the key capabilities of the gCore framework, including:\n";
echo "1. Geometric topology for service discovery in n-dimensional capability space\n";
echo "2. Error tracking and notification through the ErrorManager\n";
echo "3. Caching operations through the CacheManager\n";
echo "4. Security features through the SecurityManager\n";
echo "\nIn a real application, these capabilities would be integrated with actual\n";
echo "backend services, but this demo illustrates the architectural concepts.\n";