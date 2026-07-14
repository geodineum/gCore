<?php
declare(strict_types=1);
namespace gCore\Modules\Managers\Base\APIManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\Utils\SelfContainedErrorHandler;
use gCore\Modules\Core\Exceptions\{
    InitializationException,
    ValidationException
};

require_once dirname(__DIR__, 2) . '/Traits/StateManagerAware.php';
use gCore\Modules\Managers\Traits\StateManagerAware;

/**
 * API Manager Implementation
 *
 * Provides routing, middleware, and API management capabilities.
 */
class APIManager implements ModuleInterface {
    use StateManagerAware;
    use ManagerConfigTrait;

    private const DEFAULTS = [
        'cors' => [
            'enabled' => true,
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
            'expose_headers' => [],
            'max_age' => 3600,
        ],
        'rate_limit' => [
            'enabled' => true,
            'requests' => 100,
            'period' => 60,
        ],
        'cache' => [
            'enabled' => true,
            'ttl' => 300,
        ],
        'auth' => [
            'enabled' => true,
            'methods' => ['api_key', 'jwt'],
        ],
        'server' => [
            'mode' => 'auto',
            'port' => 8000,
            'host' => '0.0.0.0',
        ],
        'site_id' => 'default',
        'node_id' => 'node1',
    ];

    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Configuration
     */
    private $config = [];
    
    /**
     * Initialization state
     */
    private $initialized = false;
    
    /**
     * Registered endpoints
     */
    private $endpoints = [];
    
    /**
     * Registered middleware
     */
    private $middleware = [];

    /**
     * gNode-Client instance for topology registration
     */
    private $gNodeClient = null;

    /**
     * Get singleton instance
     * 
     * @return ModuleInterface APIManager instance
     */
    public static function getInstance(): ModuleInterface {
        if (self::$instance === null) {
            self::$instance = new self();
            // No auto-initialization to prevent circular dependencies
            // The gCore framework will handle initialization with proper config
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
    }
    
    /**
     * Capability vector for GeometricTopology integration
     * 
     * @var array
     */
    private $capabilityVector = [
        'auth' => 0.7,
        'security' => 0.6,
        'cache' => 0.5,
        'errors' => 0.4
    ];
    
    /**
     * Initialize APIManager with configuration
     * 
     * @param array $config Configuration options
     * @return void
     * @throws InitializationException If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }
        
        try {
            // Layered config: DEFAULTS → ValKey (defaults + per-site) → $config arg
            $siteId = (string)($config['site_id'] ?? self::DEFAULTS['site_id']);
            $valkeyConfig = [];
            $storage = $this->gcoreResolveStorage($config);
            if ($storage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'APIManager');
            }
            $this->config = array_merge(self::DEFAULTS, $valkeyConfig, $config);

            // Set site ID for StateManagerAware trait
            $this->siteId = $this->config['site_id'];

            // Check for gNode-Client integration (standardized pattern)
            if (isset($config['gnode_client']) &&
                $config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface) {
                $this->gNodeClient = $config['gnode_client'];
                SelfContainedErrorHandler::logInfo(
                    'APIManager',
                    'initialize',
                    'APIManager using gNode-Client integration',
                    [
                        'site_id' => $this->config['site_id'],
                        'node_id' => $this->config['node_id']
                    ]
                );
            }

            // Initialize base middleware
            $this->registerBaseMiddleware();

            // Enhance capability vector when StateManager provides distributed rate limiting
            if ($this->hasStateManager()) {
                $this->capabilityVector['rate_limiting'] = 0.9;
            }

            $this->initialized = true;
            
            // Log successful initialization
            SelfContainedErrorHandler::logInfo(
                'APIManager',
                'initialize',
                'Successfully initialized APIManager',
                [
                    'site_id' => $this->config['site_id'],
                    'node_id' => $this->config['node_id'],
                    'server_mode' => $this->config['server']['mode']
                ]
            );
            
        } catch (\Exception $e) {
            // Log error using SelfContainedErrorHandler
            SelfContainedErrorHandler::logError(
                'APIManager',
                'initialize',
                $e,
                [
                    'site_id' => $this->config['site_id'] ?? 'default',
                    'node_id' => $this->config['node_id'] ?? 'node1',
                    'config' => SelfContainedErrorHandler::safeJsonEncode($this->config ?? [])
                ]
            );
            
            throw new InitializationException(
                'Failed to initialize APIManager: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Register base middleware
     * 
     * @return void
     */
    private function registerBaseMiddleware(): void {
        // Register CORS middleware if enabled
        if ($this->config['cors']['enabled']) {
            $this->addMiddleware('cors', function($request, $response, $next) {
                // Add CORS headers
                $response->header('Access-Control-Allow-Origin', implode(', ', $this->config['cors']['allowed_origins']));
                $response->header('Access-Control-Allow-Methods', implode(', ', $this->config['cors']['allowed_methods']));
                $response->header('Access-Control-Allow-Headers', implode(', ', $this->config['cors']['allowed_headers']));
                $response->header('Access-Control-Max-Age', $this->config['cors']['max_age']);
                
                if (!empty($this->config['cors']['expose_headers'])) {
                    $response->header('Access-Control-Expose-Headers', implode(', ', $this->config['cors']['expose_headers']));
                }
                
                // Handle preflight request
                if ($request->method === 'OPTIONS') {
                    return $response->send(204);
                }
                
                return $next($request, $response);
            });
        }
        
        // Register rate limiting middleware if enabled
        // Uses StateManagerAware::checkRateLimit() for distributed atomic counters
        // across all PHP-FPM workers via ValKey. Gracefully degrades to no rate
        // limiting when StateManager is unavailable (identical to previous behavior).
        if ($this->config['rate_limit']['enabled']) {
            $this->addMiddleware('rate_limit', function($request, $response, $next) {
                // Get client identifier (IP address or API key)
                $clientId = $request->header('X-API-Key') ?? $request->ip ?? '0.0.0.0';

                $limit = $this->config['rate_limit']['requests'];
                $window = $this->config['rate_limit']['period'];

                // Distributed sliding-window rate limit via StateManagerAware
                $result = $this->checkRateLimit("api_rate_limit:{$clientId}", $limit, $window);

                // Calculate window reset time for headers
                $resetTime = (floor(time() / $window) + 1) * $window;

                if (!$result['allowed']) {
                    $retryAfter = max(0, $resetTime - time());

                    $response->header('X-RateLimit-Limit', $limit);
                    $response->header('X-RateLimit-Remaining', 0);
                    $response->header('X-RateLimit-Reset', $resetTime);
                    $response->header('Retry-After', $retryAfter);

                    return $response->json([
                        'error' => 'Rate limit exceeded',
                        'retry_after' => $retryAfter,
                    ], 429);
                }

                $response->header('X-RateLimit-Limit', $limit);
                $response->header('X-RateLimit-Remaining', $result['remaining']);
                $response->header('X-RateLimit-Reset', $resetTime);

                return $next($request, $response);
            });
        }
        
        // Register authentication middleware if enabled
        if ($this->config['auth']['enabled']) {
            $this->addMiddleware('auth', function($request, $response, $next) {
                // Check if authentication is required for this endpoint
                $requiresAuth = true;

                // Get SecurityManager for validation
                $securityManager = $this->getSecurityManager();

                // Get authentication method from request
                $authMethod = null;

                // Check for API key in headers
                if (in_array('api_key', $this->config['auth']['methods']) && $request->header('X-API-Key')) {
                    $apiKey = $request->header('X-API-Key');

                    if ($securityManager) {
                        $result = $securityManager->validateAPIKey($apiKey);
                        if (!$result['valid']) {
                            return $response->json([
                                'error' => $result['error'],
                                'code' => $result['error_code']
                            ], 401);
                        }
                        $authMethod = 'api_key';
                        $request->user = $result['user'];
                    } else {
                        // Fallback: SecurityManager unavailable, reject for security
                        return $response->json([
                            'error' => 'Authentication service unavailable',
                            'code' => 'auth_service_unavailable'
                        ], 503);
                    }
                }
                // Check for JWT in Authorization header
                else if (in_array('jwt', $this->config['auth']['methods']) && $request->header('Authorization')) {
                    $authHeader = $request->header('Authorization');
                    if (preg_match('/^Bearer\s+(.*)$/', $authHeader, $matches)) {
                        $jwt = $matches[1];

                        if ($securityManager) {
                            $result = $securityManager->validateJWT($jwt);
                            if (!$result['valid']) {
                                return $response->json([
                                    'error' => $result['error'],
                                    'code' => $result['error_code']
                                ], 401);
                            }
                            $authMethod = 'jwt';
                            $request->user = [
                                'auth_method' => 'jwt',
                                'token' => $jwt,
                                'payload' => $result['payload'],
                                'roles' => $result['payload']['roles'] ?? ['user'],
                                'sub' => $result['payload']['sub'] ?? null
                            ];
                        } else {
                            return $response->json([
                                'error' => 'Authentication service unavailable',
                                'code' => 'auth_service_unavailable'
                            ], 503);
                        }
                    }
                }

                // If authentication is required but no valid auth method was found
                if ($requiresAuth && !$authMethod) {
                    return $response->json([
                        'error' => 'Authentication required',
                        'code' => 'auth_required',
                        'supported_methods' => $this->config['auth']['methods']
                    ], 401);
                }

                return $next($request, $response);
            });
        }
    }

    /**
     * Get SecurityManager instance for authentication
     *
     * @return \gCore\Modules\Managers\Base\SecurityManager\SecurityManager|null
     */
    private function getSecurityManager() {
        // Try gCore service locator first
        try {
            $gCore = \gCore\Modules\Core\gCore::getInstance();
            if ($gCore && method_exists($gCore, 'getService')) {
                $securityManager = $gCore->getService('SecurityManager');
                if ($securityManager) {
                    return $securityManager;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to direct instantiation
        }

        // Fallback to direct singleton access
        try {
            return \gCore\Modules\Managers\Base\SecurityManager\SecurityManager::getInstance();
        } catch (\Throwable $e) {
            SelfContainedErrorHandler::logError(
                'APIManager',
                'getSecurityManager',
                $e,
                ['context' => 'Failed to get SecurityManager instance']
            );
            return null;
        }
    }
    
    /**
     * Add middleware
     * 
     * @param string $name Middleware name
     * @param callable $handler Middleware handler
     * @return bool Success status
     * @api
     */
    public function addMiddleware(string $name, callable $handler): bool {
        $this->middleware[$name] = $handler;
        return true;
    }
    
    /**
     * Register API endpoint
     * 
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $path URL path
     * @param callable $handler Endpoint handler
     * @param array $options Additional options
     * @return bool Success status
     * @throws ValidationException If path or method is invalid
     * @api
     */
    public function registerEndpoint(string $method, string $path, callable $handler, array $options = []): bool {
        // Validate method
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH', 'HEAD'])) {
            throw new ValidationException("Invalid HTTP method: {$method}");
        }
        
        // Validate path
        if (empty($path) || $path[0] !== '/') {
            throw new ValidationException("Path must start with a slash: {$path}");
        }
        
        // Register endpoint
        $endpoint = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $options['middleware'] ?? [],
            'cache' => $options['cache'] ?? $this->config['cache']['enabled'],
            'auth' => $options['auth'] ?? $this->config['auth']['enabled']
        ];
        
        $key = "{$method} {$path}";
        $this->endpoints[$key] = $endpoint;
        
        return true;
    }
    
    /**
     * Start the API server
     * 
     * @param int $port Port to listen on
     * @param string $host Host to listen on
     * @return bool Success status
     * @api
     */
    public function start(int $port = null, string $host = null): bool {
        try {
            // Use provided values or fall back to configuration
            $port = $port ?? $this->config['server']['port'] ?? 8000;
            $host = $host ?? $this->config['server']['host'] ?? '0.0.0.0';
            $serverMode = $this->config['server']['mode'] ?? 'auto';
            
            // Check if we're in test mode
            $isTestMode = defined('TESTING') || 
                          strpos($_SERVER['SCRIPT_FILENAME'] ?? '', 'test') !== false;
            
            // Handle different server modes
            if ($isTestMode || $serverMode === 'disabled') {
                // In test mode or if server is disabled, don't actually start a server
                return true;
            }
            
            // If mode is set to standalone, force PHP built-in server
            // If mode is set to integrated, force web server integration
            // If mode is auto (default), detect environment
            
            $useBuiltInServer = ($serverMode === 'standalone') || 
                                ($serverMode === 'auto' && php_sapi_name() === 'cli');
                                
            if ($useBuiltInServer) {
                // In CLI mode, we can start a development server
                echo "Starting API server on {$host}:{$port}\n";
                echo "Press Ctrl+C to stop\n";

                // Resolve bootstrap path — each request in the child process
                // bootstraps gCore fresh and uses processRequest(), so handlers
                // are real callables, never serialized strings.
                $basePath = defined('GCORE_BASE_PATH') ? GCORE_BASE_PATH : dirname(__DIR__, 4);
                $bootstrapPath = $basePath . '/bootstrap.php';

                if (!file_exists($bootstrapPath)) {
                    error_log("APIManager: bootstrap.php not found at {$bootstrapPath}");
                    return false;
                }

                // Write a minimal router that bootstraps gCore per-request
                $routerFile = sys_get_temp_dir() . '/gcore_api_router.php';
                $escapedBootstrap = addslashes($bootstrapPath);
                file_put_contents($routerFile, <<<ROUTER
<?php
// gCore APIManager standalone router — bootstraps gCore per request
// Serves static files directly when they exist
if (file_exists(\$_SERVER["DOCUMENT_ROOT"] . \$_SERVER["REQUEST_URI"]) &&
    !is_dir(\$_SERVER["DOCUMENT_ROOT"] . \$_SERVER["REQUEST_URI"])) {
    return false;
}

header("X-Powered-By: gCore APIManager");

require_once '{$escapedBootstrap}';

\$gCore = \\gCore\\Modules\\Core\\gCore::getInstance();
if (!\$gCore->isInitialized()) {
    \$gCore->initialize();
}
\$apiManager = \$gCore->getService('APIManager');
\$apiManager->processRequest();
ROUTER
                );

                // Start the PHP built-in web server
                $command = sprintf(
                    'php -S %s:%d %s',
                    escapeshellarg($host),
                    (int)$port,
                    escapeshellarg($routerFile)
                );
                passthru($command);

                return true;
            } else {
                // Integrated mode - we're running under an existing web server
                // Just make the router available for the web server
                $this->loadRequestHandler();
                
                // Indicate that the API server is running
                $mode = $serverMode === 'integrated' ? 'integrated' : 'auto-detected';
                echo "API server initialized in {$mode} web server mode\n";
                return true;
            }
        } catch (\Exception $e) {
            error_log("Failed to start API server: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process the current HTTP request
     * 
     * @return void
     * @api
     */
    public function processRequest(): void {
        // Create request object from current HTTP request
        $request = $this->createRequestFromGlobals();
        
        // Create response object
        $response = (object) [
            'status' => 200,
            'headers' => [],
            'body' => '',
            'header' => function($name, $value) use (&$response) {
                $response->headers[$name] = $value;
                return $response;
            },
            'json' => function($data, $status = 200) use (&$response) {
                $response->status = $status;
                $response->headers['Content-Type'] = 'application/json';
                $response->body = json_encode($data);
                return $response;
            },
            'send' => function($status = 200, $body = '') use (&$response) {
                $response->status = $status;
                $response->body = $body;
                return $response;
            }
        ];
        
        // Find endpoint for this request
        $endpoint = $this->findEndpoint($request);
        
        // If no endpoint found, return 404
        if (!$endpoint) {
            $response->json([
                'error' => 'Not Found',
                'message' => "No endpoint found for {$request->method} {$request->path}"
            ], 404);
            $this->sendResponse($response);
            return;
        }
        
        // Build middleware stack
        $middlewareStack = [];
        
        // Add endpoint middleware
        if (!empty($endpoint['middleware'])) {
            foreach ($endpoint['middleware'] as $name) {
                if (isset($this->middleware[$name])) {
                    $middlewareStack[] = $this->middleware[$name];
                }
            }
        }
        
        // Add endpoint handler
        $middlewareStack[] = $endpoint['handler'];
        
        // Process middleware stack
        $this->processMiddlewareStack($request, $response, $middlewareStack);
    }
    
    /**
     * Load the request handler for web mode
     * 
     * @return void
     */
    private function loadRequestHandler(): void {
        // Register a shutdown function to process the request at the end of script execution
        register_shutdown_function(function() {
            $this->processRequest();
        });
    }
    
    /**
     * Create a request object from PHP globals
     * 
     * @return object Request object
     */
    private function createRequestFromGlobals(): object {
        $request = (object) [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
            'query' => $_GET,
            'body' => json_decode(file_get_contents('php://input'), true) ?? [],
            'headers' => function_exists('getallheaders') ? getallheaders() : [],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'params' => []
        ];
        
        // Helper method to get a header
        $request->header = function($name) use ($request) {
            foreach ($request->headers as $key => $value) {
                if (strtolower($key) === strtolower($name)) {
                    return $value;
                }
            }
            return null;
        };
        
        // Helper method to get a path parameter
        $request->param = function($name) use ($request) {
            return $request->params[$name] ?? null;
        };
        
        return $request;
    }
    
    /**
     * Find endpoint for a request
     * 
     * @param object $request Request object
     * @return array|null Endpoint configuration or null if not found
     */
    private function findEndpoint(object $request): ?array {
        // Try exact match first
        $key = "{$request->method} {$request->path}";
        if (isset($this->endpoints[$key])) {
            return $this->endpoints[$key];
        }
        
        // Try pattern matching
        foreach ($this->endpoints as $route => $endpoint) {
            $parts = explode(' ', $route, 2);
            if (count($parts) !== 2) {
                continue;
            }
            
            $method = $parts[0];
            $pattern = $parts[1];
            
            // Skip if method doesn't match
            if ($method !== $request->method) {
                continue;
            }
            
            // Convert route pattern to regex
            $regex = preg_replace('/:([^\/]+)/', '([^/]+)', $pattern);
            $regex = '@^' . $regex . '$@';
            
            if (preg_match($regex, $request->path, $matches)) {
                // Extract path parameters
                $paramNames = [];
                preg_match_all('/:([^\/]+)/', $pattern, $paramMatches);
                if (!empty($paramMatches[1])) {
                    $paramNames = $paramMatches[1];
                }
                
                // Skip first match (full string)
                array_shift($matches);
                
                // Add path parameters to request
                foreach ($paramNames as $index => $name) {
                    if (isset($matches[$index])) {
                        $request->params[$name] = $matches[$index];
                    }
                }
                
                return $endpoint;
            }
        }
        
        return null;
    }
    
    /**
     * Process middleware stack
     * 
     * @param object $request Request object
     * @param object $response Response object
     * @param array $middlewareStack Middleware stack
     * @return void
     */
    private function processMiddlewareStack(object $request, object $response, array $middlewareStack): void {
        // Helper function to process middleware stack
        $next = function($index) use (&$next, $middlewareStack, $request, $response) {
            if ($index >= count($middlewareStack)) {
                return $response;
            }
            
            $middleware = $middlewareStack[$index];
            return $middleware($request, $response, function($req, $res) use ($index, $next) {
                return $next($index + 1);
            });
        };
        
        // Start processing
        $result = $next(0);
        
        // Send response
        $this->sendResponse($result);
    }
    
    /**
     * Send HTTP response
     * 
     * @param object $response Response object
     * @return void
     */
    private function sendResponse(object $response): void {
        // Set status code
        http_response_code($response->status);
        
        // Set headers
        foreach ($response->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        // Output body
        echo $response->body;
    }
    
    /**
     * Get module configuration
     * 
     * @return array Configuration
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * Update module configuration
     * 
     * @param array $config New configuration
     * @return void
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);

        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'APIManager', (string)$key, $value);
            }
        }
    }

    /**
     * Check if module is initialized
     * 
     * @return bool Initialization status
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }
    
    /**
     * Get module status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'endpoints' => count($this->endpoints),
            'middleware' => array_keys($this->middleware),
            'capabilities' => $this->capabilityVector
        ];
    }
    
    /**
     * Get capability vector for service discovery
     * 
     * @return array Capability vector
     */
    public function getCapabilityVector(): array {
        return $this->capabilityVector;
    }
    
}
