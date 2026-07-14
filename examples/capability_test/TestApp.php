<?php
/**
 * TestApp - A sample application for testing the capability checker
 */

// Include gCore
require_once __DIR__ . '/../../gcore-standalone.php';

/**
 * Test Application Class
 */
class TestApp {
    /** @var \gCore\Modules\Core\gCore */
    private $gCore;
    
    /** @var \gCore\Modules\Managers\Base\SecurityManager\SecurityManager */
    private $securityManager;
    
    /** @var \gCore\Modules\Managers\Base\CacheManager\CacheManager */
    private $cacheManager;
    
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
            'site_id' => 'test_app',
            'storage' => [
                'host' => '127.0.0.1',
                'port' => 6379
            ]
        ]);
        
        // Get service managers
        $this->securityManager = $this->gCore->getService('SecurityManager');
        $this->cacheManager = $this->gCore->getService('CacheManager');
    }
    
    /**
     * Run the application
     */
    public function run() {
        echo "Starting TestApp...\n";
        
        // Use security features
        if ($this->securityManager->hasCapability('manage_security')) {
            echo "Security check passed\n";
            
            // Sanitize input
            $userInput = '<script>alert("XSS")</script>Hello';
            $cleanInput = $this->securityManager->sanitizeHTML($userInput);
            echo "Sanitized input: $cleanInput\n";
        }
        
        // Use caching features
        $this->cacheManager->set('test_key', 'Hello from TestApp', 3600);
        $value = $this->cacheManager->get('test_key');
        echo "Cached value: $value\n";
        
        // Test stream capabilities
        $this->cacheManager->streamAdd('test_stream', [
            'message' => 'Test message',
            'timestamp' => time()
        ]);
        
        // Discover service with specific capabilities
        $service = $this->gCore->discoverServiceByCapabilities([
            'caching' => 3.0,
            'security' => 1.0
        ]);
        
        if ($service) {
            echo "Discovered service: " . get_class($service) . "\n";
        }
        
        echo "TestApp completed\n";
    }
}

// Create and run if executed directly
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $app = new TestApp();
    $app->run();
}