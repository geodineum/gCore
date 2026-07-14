<?php
/**
 * ApiExample - A sample API implementation for testing the capability checker
 */

// Include gCore
require_once __DIR__ . '/../../gcore-standalone.php';

/**
 * API Example Class
 */
class ApiExample {
    /** @var \gCore\Modules\Core\gCore */
    private $gCore;
    
    /** @var \gCore\Modules\Managers\Base\APIManager\APIManager */
    private $apiManager;
    
    /** @var \gCore\Modules\Managers\Base\SecurityManager\SecurityManager */
    private $securityManager;
    
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
            'site_id' => 'api_example',
            'storage' => [
                'host' => '127.0.0.1',
                'port' => 6379
            ]
        ]);
        
        // Get service managers
        $this->apiManager = $this->gCore->getService('APIManager');
        $this->securityManager = $this->gCore->getService('SecurityManager');
        
        // Register API endpoints
        $this->registerEndpoints();
    }
    
    /**
     * Register API endpoints
     */
    private function registerEndpoints() {
        // Users endpoint
        $this->apiManager->registerEndpoint('users/{id}', [
            'methods' => 'GET',
            'middleware' => ['auth', 'rate_limit'],
            'callback' => [$this, 'getUserData']
        ]);
        
        // Posts endpoint
        $this->apiManager->registerEndpoint('posts', [
            'methods' => 'GET',
            'middleware' => ['rate_limit'],
            'callback' => [$this, 'getPosts']
        ]);
        
        // Create post endpoint
        $this->apiManager->registerEndpoint('posts', [
            'methods' => 'POST',
            'middleware' => ['auth', 'validation'],
            'callback' => [$this, 'createPost']
        ]);
    }
    
    /**
     * Get user data endpoint
     * 
     * @param array $request Request data
     * @return array Response data
     */
    public function getUserData(array $request): array {
        $userId = $request['path_params']['id'] ?? null;
        
        // Check authorization
        if (!$this->securityManager->hasCapability('view_users')) {
            return [
                'error' => 'Unauthorized',
                'code' => 403
            ];
        }
        
        // Get user data (mock)
        return [
            'user' => [
                'id' => $userId,
                'name' => 'Test User',
                'email' => 'user@example.com'
            ]
        ];
    }
    
    /**
     * Get posts endpoint
     * 
     * @param array $request Request data
     * @return array Response data
     */
    public function getPosts(array $request): array {
        // Get posts (mock)
        return [
            'posts' => [
                [
                    'id' => 1,
                    'title' => 'First Post',
                    'content' => 'This is the first post'
                ],
                [
                    'id' => 2,
                    'title' => 'Second Post',
                    'content' => 'This is the second post'
                ]
            ]
        ];
    }
    
    /**
     * Create post endpoint
     * 
     * @param array $request Request data
     * @return array Response data
     */
    public function createPost(array $request): array {
        // Check authorization
        if (!$this->securityManager->hasCapability('create_posts')) {
            return [
                'error' => 'Unauthorized',
                'code' => 403
            ];
        }
        
        // Get post data
        $title = $request['body']['title'] ?? '';
        $content = $request['body']['content'] ?? '';
        
        // Sanitize input
        $title = $this->securityManager->sanitizeHTML($title);
        $content = $this->securityManager->sanitizeHTML($content);
        
        // Create post (mock)
        return [
            'post' => [
                'id' => 3,
                'title' => $title,
                'content' => $content,
                'created_at' => time()
            ]
        ];
    }
    
    /**
     * Start the API server
     * 
     * @param array $options Server options
     */
    public function start(array $options = []) {
        $this->apiManager->startServer(array_merge([
            'mode' => 'standalone',
            'port' => 8000
        ], $options));
    }
}

// Create if executed directly
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $api = new ApiExample();
    // Start server only when explicitly requested
    if (isset($argv[1]) && $argv[1] === 'server') {
        $api->start();
    }
}