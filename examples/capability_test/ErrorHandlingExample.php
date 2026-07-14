<?php
/**
 * ErrorHandlingExample - A sample error handling implementation for testing the capability checker
 */

// Include gCore
require_once __DIR__ . '/../../gcore-standalone.php';

use gCore\Modules\Core\Exceptions\ErrorException;

/**
 * Error Handling Example Class
 */
class ErrorHandlingExample {
    /** @var \gCore\Modules\Core\gCore */
    private $gCore;
    
    /** @var \gCore\Modules\Managers\Base\ErrorManager\ErrorManager */
    private $errorManager;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Initialize gCore
        $this->gCore = gcore_init([
            'core' => [
                'environment' => 'development',
                'debug' => true
            ],
            'site_id' => 'error_example',
            'storage' => [
                'host' => '127.0.0.1',
                'port' => 6379
            ]
        ]);
        
        // Get ErrorManager
        $this->errorManager = $this->gCore->getService('ErrorManager');
    }
    
    /**
     * Run the example
     */
    public function run() {
        echo "Starting ErrorHandlingExample...\n";
        
        try {
            // Log a simple message
            $this->errorManager->logMessage("Application started", "INFO", "SYSTEM");
            
            // Test different error levels
            $this->testErrorLevels();
            
            // Test error with context
            $this->testErrorWithContext();
            
            // Test notification
            $this->testNotification();
            
            // Test custom error UI
            $this->testCustomErrorUI();
            
            // Test advanced logging
            $this->testAdvancedLogging();
            
        } catch (ErrorException $e) {
            echo "Error occurred: " . $e->getMessage() . "\n";
        }
        
        echo "ErrorHandlingExample completed\n";
    }
    
    /**
     * Test different error levels
     */
    private function testErrorLevels() {
        // Debug level
        $this->errorManager->logMessage("This is a debug message", "DEBUG", "SYSTEM");
        
        // Info level
        $this->errorManager->logMessage("This is an info message", "INFO", "SYSTEM");
        
        // Warning level
        $this->errorManager->logMessage("This is a warning message", "WARNING", "SYSTEM");
        
        // Error level
        $this->errorManager->logError("This is an error message", [], "ERROR", "SYSTEM");
        
        // Critical level
        $this->errorManager->logError("This is a critical message", [], "CRITICAL", "SYSTEM");
    }
    
    /**
     * Test error with context
     */
    private function testErrorWithContext() {
        $context = [
            'user_id' => 123,
            'action' => 'login',
            'ip_address' => '127.0.0.1',
            'timestamp' => time(),
            'details' => [
                'browser' => 'Chrome',
                'os' => 'Linux'
            ]
        ];
        
        $this->errorManager->logError(
            "Failed login attempt",
            $context,
            "WARNING",
            "SECURITY"
        );
    }
    
    /**
     * Test notification
     */
    private function testNotification() {
        $this->errorManager->notifyAdmin(
            "Critical System Error",
            "Database connection failed",
            ['email', 'dashboard']
        );
    }
    
    /**
     * Test custom error UI
     */
    private function testCustomErrorUI() {
        // Get UI
        $ui = $this->errorManager->getErrorUI();
        
        // Create a user-friendly error message
        $userMessage = $ui->formatUserMessage(
            "An error occurred while processing your request",
            "500",
            "Please try again later"
        );
        
        echo "User message: $userMessage\n";
    }
    
    /**
     * Test advanced logging
     */
    private function testAdvancedLogging() {
        // Test log aggregation
        $this->errorManager->aggregateErrors([
            [
                'message' => 'Connection timeout',
                'context' => ['service' => 'database'],
                'level' => 'ERROR',
                'category' => 'INFRASTRUCTURE'
            ],
            [
                'message' => 'Cache miss',
                'context' => ['key' => 'user:123'],
                'level' => 'INFO',
                'category' => 'CACHE'
            ]
        ]);
        
        // Test error tracking
        $trackingId = $this->errorManager->startErrorTracking('API Request');
        
        // Log some events in the tracking session
        $this->errorManager->logTrackedEvent($trackingId, 'Request received', 'INFO');
        $this->errorManager->logTrackedEvent($trackingId, 'Processing request', 'INFO');
        $this->errorManager->logTrackedEvent($trackingId, 'Database query executed', 'DEBUG');
        $this->errorManager->logTrackedEvent($trackingId, 'Response sent', 'INFO');
        
        // End tracking
        $this->errorManager->endErrorTracking($trackingId);
    }
}

// Create and run if executed directly
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $example = new ErrorHandlingExample();
    $example->run();
}