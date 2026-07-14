<?php
/**
 * gCore Framework - Security Manager Example (Simplified)
 * 
 * This example demonstrates the Security Manager capabilities
 * with a focus on basic security operations.
 */

// Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

echo "gCore Security Manager Example (Simplified)\n";
echo "========================================\n\n";

// Initialize core functionality
echo "Initializing gCore...\n";

// Create a simplified gCore instance
$config = [
    'core' => [
        'environment' => 'development',
        'debug' => true,
    ],
    'site_id' => 'security_example',
    'node_id' => 'security_node',
    'storage' => [
        'host' => '127.0.0.1',
        'port' => 6379
    ]
];

try {
    // Initialize gCore instance
    $gCore = \gCore\Modules\Core\gCore::getInstance();
    $gCore->initialize($config);
    
    echo "gCore initialized successfully\n";
    
    // Get the Security Manager
    echo "\nLoading Security Manager...\n";
    $security = $gCore->getService('SecurityManager');
    
    echo "Security Manager loaded successfully!\n";
    
    // Basic security operations demo
    echo "\n=== Basic Security Operations ===\n";
    
    // 1. Check available encryption methods
    echo "Available security operations:\n";
    $methods = get_class_methods($security);
    $securityMethods = array_filter($methods, function($method) {
        return !in_array($method, ['__construct', '__destruct', 'getInstance', 'isInitialized']);
    });
    echo "- " . implode("\n- ", array_slice($securityMethods, 0, 10)) . "\n";
    
    // 2. Sanitize some input (if method exists)
    if (method_exists($security, 'sanitize') || method_exists($security, 'sanitizeInput')) {
        $sanitizeMethod = method_exists($security, 'sanitize') ? 'sanitize' : 'sanitizeInput';
        echo "\nTesting input sanitization...\n";
        $dangerousInput = '<script>alert("XSS Attack!");</script>';
        echo "Before: $dangerousInput\n";
        $sanitized = $security->$sanitizeMethod($dangerousInput);
        echo "After: $sanitized\n";
    } else {
        echo "\nSanitization method not available in this version\n";
    }
    
    // 3. Simple encryption (if method exists)
    if (method_exists($security, 'encrypt') && method_exists($security, 'decrypt')) {
        echo "\nTesting encryption...\n";
        $original = "This is sensitive data";
        echo "Original: $original\n";
        
        try {
            $encrypted = $security->encrypt($original);
            echo "Encrypted: $encrypted\n";
            
            $decrypted = $security->decrypt($encrypted);
            echo "Decrypted: $decrypted\n";
        } catch (Exception $e) {
            echo "Encryption error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "\nEncryption methods not available in this version\n";
    }
    
    // 4. Simple permission check (mock implementation)
    echo "\n=== Permission System Demo ===\n";
    echo "This is a simplified demonstration of how permissions would work:\n";
    
    // Example roles and permissions
    $roles = [
        'admin' => ['view_dashboard', 'edit_users', 'delete_users'],
        'editor' => ['view_dashboard', 'edit_users'],
        'viewer' => ['view_dashboard']
    ];
    
    // Simulate permission checks
    foreach ($roles as $role => $permissions) {
        echo "$role can:\n";
        foreach (['view_dashboard', 'edit_users', 'delete_users'] as $perm) {
            $hasPermission = in_array($perm, $permissions);
            echo "- $perm: " . ($hasPermission ? "Yes" : "No") . "\n";
        }
        echo "\n";
    }
    
    echo "\nThis simplified example demonstrates the basic structure of gCore's\n";
    echo "Security Manager. In a full implementation, it provides security\n";
    echo "features including encryption, access control, sanitization, and more.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
}