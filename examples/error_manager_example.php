<?php
/**
 * gCore Framework - Error Manager Example
 * 
 * This example demonstrates the Error Manager capabilities
 * including error tracking, logging, notification, and custom error handling.
 */

// Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

echo "gCore Error Manager Example\n";
echo "==========================\n\n";

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $env = file_get_contents(__DIR__ . '/../.env');
    $lines = explode("\n", $env);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . "=" . trim($value));
        }
    }
}

// Initialize gCore with our configuration
$config = [
    'core' => [
        'environment' => 'development',
        'debug' => true,
    ],
    'site_id' => 'error_example',
    'node_id' => 'error_node',
    'storage' => [
        'host' => getenv('VALKEY_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('VALKEY_PORT') ?: 6379),
        'auth' => getenv('VALKEY_AUTH') ?: null,
        'tls' => getenv('VALKEY_TLS') === 'true',
    ]
];

echo "Initializing gCore...\n";
// Use the standalone initialization pattern
require_once __DIR__ . '/../gcore-standalone.php';
$gCore = gcore_init($config);

// Get the Error Manager instance
echo "Loading Error Manager...\n";
$errorManager = gcore_get_error_manager();

// Demo 1: Basic Error Tracking
echo "\n=== Basic Error Tracking Demo ===\n";

// Track various types of errors
try {
    // Track a warning
    $errorManager->trackError('warning', 'File upload size exceeded limit', [
        'file' => 'large_image.jpg',
        'size' => '12MB',
        'limit' => '10MB',
        'user_id' => 123
    ]);
    echo "Tracked a warning error\n";
    
    // Track an info message
    $errorManager->trackError('info', 'User logged in successfully', [
        'user_id' => 123,
        'ip' => '192.168.1.1',
        'login_time' => date('Y-m-d H:i:s')
    ]);
    echo "Tracked an info message\n";
    
    // Track an error
    $errorManager->trackError('error', 'Database connection failed', [
        'host' => 'db.example.com',
        'port' => 3306,
        'error_code' => 'ECONNREFUSED'
    ]);
    echo "Tracked an error message\n";
    
    // Track a critical error
    $errorManager->trackError('critical', 'Payment processing failed', [
        'order_id' => 'ORD-12345',
        'amount' => 99.99,
        'payment_method' => 'credit_card',
        'error_details' => 'Gateway timeout'
    ]);
    echo "Tracked a critical error\n";
} catch (Exception $e) {
    echo "Error while tracking errors: " . $e->getMessage() . "\n";
}

// Demo 2: Error Logging
echo "\n=== Error Logging Demo ===\n";

// Write errors to log
try {
    // Log different levels
    $errorManager->log('debug', 'Debugging information', ['module' => 'user_service']);
    $errorManager->log('info', 'User profile updated', ['user_id' => 456, 'fields' => ['name', 'email']]);
    $errorManager->log('warning', 'Rate limit approaching', ['ip' => '192.168.1.100', 'rate' => '95/100']);
    $errorManager->log('error', 'API request failed', ['endpoint' => '/api/v1/users', 'status' => 500]);
    $errorManager->log('critical', 'Security breach detected', ['ip' => '203.0.113.42', 'attempts' => 20]);
    
    echo "Logged errors at various severity levels\n";
    
    // Get recent logs
    $logs = $errorManager->getRecentLogs(5);
    echo "Recent logs (" . count($logs) . "):\n";
    foreach ($logs as $log) {
        echo "  • [{$log['level']}] {$log['message']}\n";
    }
} catch (Exception $e) {
    echo "Error during logging: " . $e->getMessage() . "\n";
}

// Demo 3: Error Notifications
echo "\n=== Error Notifications Demo ===\n";

try {
    // Send notifications for significant errors
    $errorManager->notifyAdmin('Security Alert', 'Multiple failed login attempts detected', [
        'ip_address' => '203.0.113.42',
        'attempts' => 10,
        'timestamp' => time(),
        'severity' => 'high'
    ]);
    echo "Sent admin notification about security alert\n";
    
    // System outage notification
    $errorManager->notifyAdmin('System Outage', 'Database server is not responding', [
        'host' => 'db-master.example.com',
        'service' => 'MySQL',
        'since' => date('Y-m-d H:i:s', time() - 300),
        'severity' => 'critical'
    ]);
    echo "Sent admin notification about system outage\n";
    
    echo "Note: In a real system, these would trigger emails, SMS, or Slack notifications\n";
} catch (Exception $e) {
    echo "Error during notifications: " . $e->getMessage() . "\n";
}

// Demo 4: Exception Handling
echo "\n=== Exception Handling Demo ===\n";

// Define a function that will throw an exception
function riskyOperation($value) {
    if ($value < 0) {
        throw new \InvalidArgumentException("Value cannot be negative");
    }
    
    if ($value > 100) {
        throw new \RangeException("Value cannot exceed 100");
    }
    
    if ($value === 0) {
        throw new \gCore\Modules\Core\Exceptions\ValidationException("Value cannot be zero");
    }
    
    return $value * 2;
}

// Use the error manager to handle exceptions
try {
    // Try with a valid value
    $result = riskyOperation(50);
    echo "Operation succeeded with result: $result\n";
    
    // Try with invalid values
    $testValues = [-10, 0, 150];
    
    foreach ($testValues as $value) {
        try {
            $result = riskyOperation($value);
            echo "Operation with $value succeeded: $result\n";
        } catch (Exception $e) {
            // Let the error manager handle the exception
            $errorManager->handleException($e, [
                'function' => 'riskyOperation',
                'input_value' => $value,
                'timestamp' => time()
            ]);
            
            echo "Handled exception for value $value: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Unhandled exception: " . $e->getMessage() . "\n";
}

// Demo 5: Custom Error Handler Registration
echo "\n=== Custom Error Handler Demo ===\n";

// Define a custom error handler
$customHandler = function($level, $message, $context) {
    echo "✱ Custom Handler: [$level] $message\n";
    
    // Example of how to handle specific errors
    if ($level === 'critical' || $level === 'error') {
        echo "  → This would trigger an immediate notification in production\n";
    }
    
    if (isset($context['user_id'])) {
        echo "  → User affected: " . $context['user_id'] . "\n";
    }
    
    return true; // Returning true means the error was handled
};

// Register the custom handler
$errorManager->registerErrorHandler('custom_demo_handler', $customHandler);
echo "Registered custom error handler\n";

// Test the custom handler
$errorManager->trackError('error', 'Payment gateway timeout', [
    'user_id' => 789,
    'order_id' => 'ORD-67890',
    'gateway' => 'stripe'
]);

echo "\nThis example demonstrates several key features of the gCore Error Manager.\n";
echo "In a real application, these capabilities would help you monitor, track,\n";
echo "and respond to errors throughout your system.\n";