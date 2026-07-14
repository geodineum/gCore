<?php
declare(strict_types=1);
namespace gCore\Modules\Managers\Base\ErrorManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\Interfaces\Error\ErrorLoggerInterface;
use gCore\Modules\Core\Interfaces\Error\ErrorNotificationInterface;
use gCore\Modules\Core\Interfaces\Error\ErrorUIInterface;
use gCore\Modules\Core\Exceptions\{
    ErrorException,
    InitializationException,
    LoggingException,
    NotificationException,
    StorageException,
    RateLimitException,
    ValidationException
};
use gCore\Modules\Core\Shared\CacheScripts;
use gCore\Modules\Storage\Interfaces\StorageInterface;
use gCore\Modules\Storage\StorageFactory;
use gCore\Modules\Storage\gNodeDetector;

/**
 * Site-Isolated Error Management System
 * 
 * Each ErrorManager instance handles errors for its specific gCore instance,
 * maintaining complete isolation through site_id/node_id prefixing.
 */
class ErrorManager implements ModuleInterface {
    use ManagerConfigTrait;

    private const DEFAULTS = [
        'storage' => [
            'host' => null,
            'port' => null,
            'timeout' => 2.0,
            'prefix' => 'error_',
            'auth' => null,
        ],
        'default_ttl' => 604800,
        'ttl_by_level' => [
            'debug'     => 3600,
            'info'      => 3600,
            'notice'    => 21600,
            'warning'   => 86400,
            'error'     => 604800,
            'critical'  => 2592000,
            'alert'     => 2592000,
            'emergency' => 2592000,
        ],
        'skip_info_storage' => true,
        'max_errors' => 1000,
        'error_threshold' => 50,
        'capture_backtraces' => true,
        'notification_channels' => ['email'],
        'notification_rate_limit' => 60,
        'site_id' => 'default',
        'node_id' => 'node1',
    ];

    private $storage;
    private static $instance = null;
    private $config = [];
    private $initialized = false;
    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];
    private $gNodeClient = null;
    private $useGNode = false;
    
    /**
     * Get singleton instance
     * 
     * @return ModuleInterface ErrorManager instance
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
        'errors' => 1.0,
        'logging' => 0.9,
        'security' => 0.2,
        'cache' => 0.1
    ];
    
    /**
     * Initialize ErrorManager with configuration
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
            $cfgStorage = $this->gcoreResolveStorage($config);
            if ($cfgStorage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($cfgStorage, $siteId, 'ErrorManager');
            }
            $this->config = array_merge(self::DEFAULTS, $valkeyConfig, $config);

            // Sensitive: storage.auth reads from secrets keyspace with
            // fallback to $config passthrough (legacy direct-injection path).
            if (empty($this->config['storage']['auth']) && $cfgStorage !== null) {
                $secret = $this->gcoreGetSecret($cfgStorage, $siteId, 'ErrorManager', 'storage.auth');
                if ($secret !== null) {
                    $this->config['storage']['auth'] = $secret;
                }
            }
            
            // Set node metadata for multi-tenant isolation
            $this->nodeMetadata = [
                'site_id' => $this->config['site_id'],
                'node_id' => $this->config['node_id']
            ];

            // Check for gNode-Client integration (supports gNodeClient, KeyBasedClientLuaEnabled, etc.)
            if (isset($config['gnode_client']) &&
                $config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface &&
                ($config['use_gnode'] ?? true)) {
                $this->gNodeClient = $config['gnode_client'];
                $this->useGNode = true;
            } else {
                $this->useGNode = false;
            }

            // Initialize storage
            $this->initializeStorage();

            // Set up error handlers
            $this->initializeErrorHandling();
            
            // Initialize WordPress integration if in WordPress environment
            $this->initializeWordPress();
            
            $this->initialized = true;
            
            // Track initialization
            $this->trackSystemEvent('info', 'ErrorManager initialized', [
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id']
            ]);
            
        } catch (\Exception $e) {
            throw new InitializationException(
                'Failed to initialize ErrorManager: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Initialize storage for error data
     *
     * Uses shared gNode storage when available (eliminates duplicate connections),
     * falls back to StorageFactory adapters when gNode is not available.
     *
     * @return void
     * @throws StorageException If storage initialization fails
     */
    private function initializeStorage(): void {
        try {
            // SHARED ADAPTER PATH: Use pre-created gNodeStorageAdapter (maximum efficiency)
            // This adapter is created ONCE in gCore and shared by ALL managers
            if (isset($this->config['gnode_storage_adapter']) && $this->config['gnode_storage_adapter'] !== null) {
                // Use the SINGLE shared adapter directly - no wrapping needed
                $this->storage = $this->config['gnode_storage_adapter'];

                // Notify gNodeDetector that client is available (if gnode_client was injected)
                if ($this->useGNode && $this->gNodeClient !== null) {
                    gNodeDetector::setClient($this->gNodeClient);
                }
            } elseif ($this->useGNode && $this->gNodeClient !== null) {
                // gNode PATH: Create gNodeStorageAdapter wrapping gNode-Client (FCALL-only)
                // This ensures all storage operations go through Lua functions
                $this->storage = new \gCore\Modules\Storage\gNodeStorageAdapter(
                    $this->gNodeClient,
                    $this->nodeMetadata['site_id'] ?? 'default',
                    ['debug' => $this->config['debug'] ?? false]
                );

                // Notify gNodeDetector that client is available
                gNodeDetector::setClient($this->gNodeClient);
            } else {
                // FREE TIER PATH: Use StorageFactory to get best available adapter
                $this->storage = StorageFactory::create([
                    'prefix' => $this->config['storage']['prefix'] ?? 'gcore_error_',
                    'site_id' => $this->nodeMetadata['site_id']
                ]);
            }
        } catch (\Exception $e) {
            throw new StorageException(
                'Failed to initialize storage: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Initialize error handling
     * 
     * @return void
     */
    private function initializeErrorHandling(): void {
        // Register error handler
        set_error_handler([$this, 'handleError']);
        
        // Register exception handler if backtraces enabled
        if ($this->config['capture_backtraces']) {
            set_exception_handler([$this, 'handleException']);
        }
        
        // Register shutdown function to capture fatal errors
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    /**
     * Initialize WordPress integration
     * 
     * @return void
     */
    private function initializeWordPress(): void {
        if (!$this->isWordPressEnvironment()) {
            return;
        }
        
        // Use domain-specific action hooks
        $domain = $this->nodeMetadata['site_id'];
        
        add_action("{$domain}_init", [$this, 'initializeErrorHandling']);
        add_action('admin_init', [$this, 'registerSettings']);
    }
    
    /**
     * Check if running in WordPress environment
     * 
     * @return bool True if in WordPress environment
     */
    private function isWordPressEnvironment(): bool {
        return defined('WPINC');
    }
    
    /**
     * Handle PHP errors
     * 
     * @param int $level Error level
     * @param string $message Error message
     * @param string $file File where error occurred
     * @param int $line Line where error occurred
     * @param array $context Error context
     * @return bool True if error was handled
     * @api
     */
    public function handleError(
        int $level,
        string $message,
        string $file,
        int $line,
        array $context = []
    ): bool {
        // Don't handle errors if error reporting is disabled
        if (!(error_reporting() & $level)) {
            return false;
        }
        
        // Map PHP error level to log level
        $logLevel = $this->mapErrorLevel($level);
        
        // Track error
        $this->trackError($logLevel, $message, [
            'file' => $file,
            'line' => $line,
            'context' => $context
        ]);
        
        // Don't execute PHP's internal error handler
        return true;
    }
    
    /**
     * Handle exceptions
     * 
     * @param \Throwable $exception The exception
     * @return void
     * @api
     */
    public function handleException(\Throwable $exception): void {
        $logLevel = 'error';
        
        // Use warning level for common validation exceptions
        if ($exception instanceof ValidationException) {
            $logLevel = 'warning';
        }
        
        // Track exception
        $this->trackError($logLevel, $exception->getMessage(), [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
    
    /**
     * Handle script shutdown (capture fatal errors)
     * 
     * @return void
     * @api
     */
    public function handleShutdown(): void {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->trackError('critical', $error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]);
        }
    }
    
    /**
     * Map PHP error level to log level
     * 
     * @param int $level PHP error level
     * @return string Log level
     */
    private function mapErrorLevel(int $level): string {
        switch ($level) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                return 'error';
                
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return 'warning';
                
            case E_NOTICE:
            case E_USER_NOTICE:
                return 'notice';
                
            case E_STRICT:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'info';
                
            default:
                return 'warning';
        }
    }
    
    /**
     * Track error with context
     * 
     * @param string $level Error level
     * @param string $message Error message
     * @param array $context Error context
     * @return bool True if error was tracked
     * @throws LoggingException If error tracking fails
     * @api
     */
    public function trackError(string $level, string $message, array $context = []): bool {
        try {
            // Normalize level
            $level = strtolower($level);

            // Skip INFO-level system events to prevent key avalanche
            // These are initialization messages that don't need persistent storage
            if ($level === 'info' && ($context['_system'] ?? false) && ($this->config['skip_info_storage'] ?? true)) {
                // Just log to error_log for debugging if needed, don't persist
                if ($this->config['debug'] ?? false) {
                    error_log("[{$this->nodeMetadata['site_id']}] [{$level}] {$message}");
                }
                return true;
            }

            $now = time();

            // Prepare error data (without timestamp initially for stable hash)
            $errorData = [
                'level' => $level,
                'message' => $message,
                'context' => $context,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id']
            ];

            // Generate stable error ID (hash only - no timestamp for deduplication)
            $errorId = $this->generateErrorId($errorData);

            // Get TTL based on level (shorter for info/debug, longer for errors)
            $ttl = $this->config['ttl_by_level'][$level] ?? $this->config['default_ttl'];

            // Rate limit: minimum 60 seconds between updates for same error
            $rateLimitSeconds = $this->config['error_rate_limit_seconds'] ?? 60;
            $maxErrors = $this->config['max_errors'] ?? 1000;

            // Store error (wrapped in try-catch to handle ACL permission errors gracefully)
            try {
                $key = $this->getErrorKey($errorId);

                if ($this->useGNode && method_exists($this->storage, 'zAdd')) {
                    // PREMIUM PATH: Use ValKey sorted sets with deduplication

                    // Rate limit check
                    $rateLimitKey = "error_rate:{$this->nodeMetadata['site_id']}:{$this->nodeMetadata['node_id']}:{$errorId}";
                    $rateLimitTtl = $this->storage->ttl($rateLimitKey);
                    if ($rateLimitTtl > 0) {
                        // Rate limited - skip but not an error
                        return true;
                    }

                    // Set rate limit
                    $this->storage->set($rateLimitKey, '1', $rateLimitSeconds);

                    // Check if error already exists (deduplication)
                    $existingData = $this->storage->get($key);

                    if ($existingData) {
                        // Update existing error - increment occurrence count, update last_seen
                        $existing = json_decode($existingData, true);
                        if (is_array($existing)) {
                            $existing['occurrence_count'] = ($existing['occurrence_count'] ?? 1) + 1;
                            $existing['last_seen'] = $now;
                            if (!empty($context)) {
                                $existing['context'] = $context;
                            }
                            $this->storage->set($key, json_encode($existing), $ttl);

                            // Update sorted set score to latest timestamp
                            $listKey = $this->getErrorListKey();
                            $this->storage->zAdd($listKey, $now, $errorId);

                            return true;
                        }
                    }

                    // New error - create full record with tracking fields
                    $errorData['first_seen'] = $now;
                    $errorData['last_seen'] = $now;
                    $errorData['occurrence_count'] = 1;

                    $this->storage->set($key, json_encode($errorData), $ttl);

                    // Add to error list (sorted by timestamp)
                    $listKey = $this->getErrorListKey();
                    $this->storage->zAdd($listKey, $now, $errorId);

                    // Increment error count atomically
                    $countKey = $this->getErrorCountKey($level);
                    $this->storage->incr($countKey);

                    // Auto-trim: remove oldest entries if over limit
                    $listSize = $this->storage->zCard($listKey);
                    if ($listSize > $maxErrors) {
                        $removeCount = $listSize - $maxErrors;
                        $this->storage->zRemRangeByRank($listKey, 0, $removeCount - 1);
                    }
                } else {
                    // FREE TIER PATH: Use StorageInterface methods with deduplication
                    $existingData = $this->storage->get($key);

                    if ($existingData && is_array($existingData)) {
                        // Update existing error
                        $existingData['occurrence_count'] = ($existingData['occurrence_count'] ?? 1) + 1;
                        $existingData['last_seen'] = $now;
                        if (!empty($context)) {
                            $existingData['context'] = $context;
                        }
                        $this->storage->set($key, $existingData, $ttl);
                        return true;
                    }

                    // New error
                    $errorData['first_seen'] = $now;
                    $errorData['last_seen'] = $now;
                    $errorData['occurrence_count'] = 1;

                    $this->storage->set($key, $errorData, $ttl);

                    // Maintain error list as simple array (limited to max_errors)
                    $listKey = $this->getErrorListKey();
                    $errorList = $this->storage->get($listKey) ?? [];
                    if (!is_array($errorList)) {
                        $errorList = [];
                    }

                    // Add error to list with timestamp for sorting
                    $errorList[] = ['id' => $errorId, 'timestamp' => $now];

                    // Sort by timestamp descending and limit size
                    usort($errorList, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
                    $errorList = array_slice($errorList, 0, $maxErrors);

                    $this->storage->set($listKey, $errorList, 0);

                    // Increment error count using get/set
                    $countKey = $this->getErrorCountKey($level);
                    $count = (int)($this->storage->get($countKey) ?? 0);
                    $this->storage->set($countKey, $count + 1, 0);
                }
            } catch (\Exception $storageException) {
                // Log storage failure (prevents infinite loops by using error_log, not self)
                error_log("[gCore] ErrorManager: storage failure during trackError — {$storageException->getMessage()}");
                return false;
            }

            // Check threshold for notifications
            $this->checkErrorThreshold($level);

            return true;
        } catch (\Exception $e) {
            // Use basic error logging as fallback
            error_log("Error tracking failed: " . $e->getMessage());
            error_log("Original error: [{$level}] {$message}");

            // Throw exception in debug mode
            if ($this->config['debug'] ?? false) {
                throw new LoggingException(
                    'Error tracking failed: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            return false;
        }
    }
    
    /**
     * Track system event
     * 
     * @param string $level Event level
     * @param string $message Event message
     * @param array $context Event context
     * @return bool True if event was tracked
     * @api
     */
    public function trackSystemEvent(string $level, string $message, array $context = []): bool {
        $context['_system'] = true;
        return $this->trackError($level, $message, $context);
    }
    
    /**
     * Generate stable error ID for deduplication
     *
     * Uses hash-only ID (no timestamp) so the same error at different times
     * gets the same ID, enabling occurrence tracking instead of key accumulation.
     *
     * @param array $errorData Error data
     * @return string Error ID (MD5 hash of message + context)
     */
    private function generateErrorId(array $errorData): string {
        // Create hash based on error content only - no timestamp for deduplication
        return md5($errorData['message'] . json_encode($errorData['context']));
    }
    
    /**
     * Get error storage key
     * 
     * @param string $errorId Error ID
     * @return string Storage key
     */
    private function getErrorKey(string $errorId): string {
        return "error:{$this->nodeMetadata['site_id']}:{$this->nodeMetadata['node_id']}:{$errorId}";
    }
    
    /**
     * Get error list key
     * 
     * @return string Storage key
     */
    private function getErrorListKey(): string {
        return "errors:{$this->nodeMetadata['site_id']}:{$this->nodeMetadata['node_id']}";
    }
    
    /**
     * Get error count key
     * 
     * @param string $level Error level
     * @return string Storage key
     */
    private function getErrorCountKey(string $level): string {
        return "error_count:{$this->nodeMetadata['site_id']}:{$this->nodeMetadata['node_id']}:{$level}";
    }
    
    /**
     * Check if error threshold has been reached
     * 
     * @param string $level Error level
     * @return void
     */
    private function checkErrorThreshold(string $level): void {
        if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            $countKey = $this->getErrorCountKey($level);
            $count = (int)$this->storage->get($countKey);
            
            if ($count >= $this->config['error_threshold']) {
                $this->notifyAdmin(
                    "Error threshold reached",
                    "The {$level} error threshold ({$this->config['error_threshold']}) has been reached.",
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'node_id' => $this->nodeMetadata['node_id'],
                        'level' => $level,
                        'count' => $count
                    ]
                );
                
                // Reset counter
                $this->storage->set($countKey, 0);
            }
        }
    }
    
    /**
     * Send notification to admin
     * 
     * @param string $subject Notification subject
     * @param string $message Notification message
     * @param array $details Additional details
     * @return bool True if notification was sent
     * @throws NotificationException If notification fails
     * @api
     */
    public function notifyAdmin(string $subject, string $message, array $details = []): bool {
        try {
            // Check rate limit
            $rateLimitKey = "notification_rate_limit:{$this->nodeMetadata['site_id']}:{$this->nodeMetadata['node_id']}";
            $lastNotification = (int)$this->storage->get($rateLimitKey);
            
            if ($lastNotification && (time() - $lastNotification) < $this->config['notification_rate_limit']) {
                // Rate limited
                return false;
            }
            
            // Update rate limit
            $this->storage->set($rateLimitKey, time(), $this->config['notification_rate_limit'] * 2);
            
            // Default notification channel is email
            $channel = $this->config['notification_channels'][0] ?? 'email';
            
            // Basic notification implementation
            
            // Basic email notification
            if ($channel === 'email') {
                $to = $this->config['admin_email'] ?? ini_get('sendmail_from');
                if (!$to) {
                    return false;
                }
                
                $headers = "From: gCore Error Manager <{$to}>\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                
                $body = "<h2>{$subject}</h2>";
                $body .= "<p>{$message}</p>";
                
                if (!empty($details)) {
                    $body .= "<h3>Details</h3><ul>";
                    foreach ($details as $key => $value) {
                        $body .= "<li><strong>{$key}:</strong> " . 
                                 (is_scalar($value) ? htmlspecialchars($value) : json_encode($value)) . 
                                 "</li>";
                    }
                    $body .= "</ul>";
                }
                
                return mail($to, "[gCore] {$subject}", $body, $headers);
            }
            
            return false;
        } catch (\Exception $e) {
            // Basic error logging as fallback
            error_log("Notification failed: " . $e->getMessage());
            
            // Throw exception in debug mode
            if ($this->config['debug'] ?? false) {
                throw new NotificationException(
                    'Notification failed: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            
            return false;
        }
    }
    
    /**
     * Get recent errors
     *
     * @param int $limit Maximum number of errors to return
     * @param int $offset Offset for pagination
     * @return array Recent errors
     * @api
     */
    public function getRecentErrors(int $limit = 10, int $offset = 0): array {
        $listKey = $this->getErrorListKey();

        if ($this->useGNode && method_exists($this->storage, 'zRevRange')) {
            // PREMIUM PATH: Use ValKey sorted set range
            $errorIds = $this->storage->zRevRange($listKey, $offset, $offset + $limit - 1);

            if (empty($errorIds)) {
                return [];
            }

            $errors = [];
            foreach ($errorIds as $errorId) {
                $key = $this->getErrorKey($errorId);
                $errorData = $this->storage->get($key);

                if ($errorData) {
                    $errors[] = json_decode($errorData, true);
                }
            }

            return $errors;
        } else {
            // FREE TIER PATH: Use simple array list
            $errorList = $this->storage->get($listKey) ?? [];

            if (empty($errorList) || !is_array($errorList)) {
                return [];
            }

            // Apply pagination
            $pagedList = array_slice($errorList, $offset, $limit);

            $errors = [];
            foreach ($pagedList as $item) {
                $errorId = is_array($item) ? ($item['id'] ?? null) : $item;
                if ($errorId) {
                    $key = $this->getErrorKey($errorId);
                    $errorData = $this->storage->get($key);

                    if ($errorData) {
                        // Handle both JSON string and array
                        $errors[] = is_string($errorData) ? json_decode($errorData, true) : $errorData;
                    }
                }
            }

            return $errors;
        }
    }
    
    /**
     * Get error statistics
     * 
     * @return array Error statistics
     * @api
     */
    public function getErrorStats(): array {
        $levels = ['error', 'warning', 'notice', 'info', 'debug'];
        $stats = [
            'total' => 0,
            'by_level' => []
        ];
        
        foreach ($levels as $level) {
            $countKey = $this->getErrorCountKey($level);
            $count = (int)$this->storage->get($countKey);
            
            $stats['by_level'][$level] = $count;
            $stats['total'] += $count;
        }
        
        return $stats;
    }
    
    /**
     * Clear error history
     *
     * @return bool True if clearing was successful
     * @api
     */
    public function clearErrorHistory(): bool {
        try {
            $listKey = $this->getErrorListKey();

            if ($this->useGNode && method_exists($this->storage, 'zRange')) {
                // PREMIUM PATH: Use ValKey sorted set operations
                $errorIds = $this->storage->zRange($listKey, 0, -1);

                // Delete each error
                foreach ($errorIds as $errorId) {
                    $key = $this->getErrorKey($errorId);
                    $this->storage->del($key);
                }

                // Delete the error list
                $this->storage->del($listKey);
            } else {
                // FREE TIER PATH: Use StorageInterface methods
                $errorList = $this->storage->get($listKey) ?? [];

                if (is_array($errorList)) {
                    // Delete each error
                    foreach ($errorList as $item) {
                        $errorId = is_array($item) ? ($item['id'] ?? null) : $item;
                        if ($errorId) {
                            $key = $this->getErrorKey($errorId);
                            $this->storage->delete($key);
                        }
                    }
                }

                // Delete the error list
                $this->storage->delete($listKey);
            }

            // Reset counters
            $levels = ['error', 'warning', 'notice', 'info', 'debug'];
            foreach ($levels as $level) {
                $countKey = $this->getErrorCountKey($level);
                $this->storage->set($countKey, 0, 0);
            }

            return true;
        } catch (\Exception $e) {
            $this->trackError('error', 'Failed to clear error history', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
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

        $cfgStorage = $this->gcoreResolveStorage($this->config);
        if ($cfgStorage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($cfgStorage, $siteId, 'ErrorManager', (string)$key, $value);
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
        $stats = $this->getErrorStats();

        // Determine storage status based on storage type
        $storageStatus = 'unknown';
        $storageType = 'unknown';
        $mode = 'unknown';

        if ($this->useGNode && method_exists($this->storage, 'isConnected')) {
            // PREMIUM PATH: ValKeyStorage has isConnected()
            $storageStatus = $this->storage->isConnected() ? 'connected' : 'disconnected';
            $storageType = 'valkey';
            $mode = 'full';
        } elseif ($this->storage instanceof StorageInterface) {
            // FREE TIER PATH: StorageInterface has isAvailable()
            $storageStatus = $this->storage->isAvailable() ? 'available' : 'unavailable';
            $storageType = $this->storage->getType();
            $mode = 'free_tier';
        }

        return [
            'initialized' => $this->initialized,
            'site_id' => $this->nodeMetadata['site_id'],
            'node_id' => $this->nodeMetadata['node_id'],
            'mode' => $mode,
            'storage_type' => $storageType,
            'storage' => $storageStatus,
            'error_stats' => $stats,
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
    
    /**
     * Write error to log file
     * 
     * @param string $level Error level
     * @param string $message Error message
     * @param array $context Error context
     * @return bool True if error was written
     */
    public function writeErrorToLog(string $level, string $message, array $context = []): bool {
        // Basic PHP error_log implementation
        $formattedMessage = "[{$level}] {$message}";
        
        if (!empty($context)) {
            $formattedMessage .= ' ' . json_encode($context);
        }
        
        return error_log($formattedMessage);
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context
     * @return bool Success status
     * @api
     */
    public function log(string $level, string $message, array $context = []): bool {
        return $this->writeErrorToLog($level, $message, $context);
    }

    // ========================================================================
    // BROADCAST ERROR ALERTS - Cross-Node Error Distribution
    // ========================================================================

    /**
     * Broadcast error alert to all nodes
     * Distributes critical error information across the cluster
     *
     * @param string $severity Error severity (critical, error, warning)
     * @param string $message Error message
     * @param array $context Error context
     * @return string|false Message ID on success, false on failure
     */
    public function broadcastErrorAlert(string $severity, string $message, array $context = []): string|false {
        if (!$this->useGNode) {
            return false;
        }

        try {
            $messageType = "error_{$severity}";
            $fields = array_merge($context, [
                'severity' => $severity,
                'message' => $message,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'timestamp' => time(),
                'hostname' => gethostname()
            ]);

            $messageId = $this->gNodeClient->writeBroadcastMessage($messageType, $fields);

            $this->trackSystemEvent('info', "Broadcast error alert: {$severity}", [
                'message' => $message,
                'message_id' => $messageId
            ]);

            return $messageId;

        } catch (\Throwable $e) {
            $this->trackError('warning', 'Failed to broadcast error alert', [
                'error' => $e->getMessage(),
                'severity' => $severity
            ]);
            return false;
        }
    }

    /**
     * Listen for error alerts from other nodes
     * Processes error alerts and logs them locally
     *
     * @param int $count Number of messages to read (default: 10)
     * @param int $blockMs Time to block waiting for messages in milliseconds (default: 100)
     * @return array Array of processed error alerts
     */
    public function listenForErrorAlerts(int $count = 10, int $blockMs = 100): array {
        if (!$this->useGNode) {
            return [];
        }

        try {
            $broadcastReader = $this->gNodeClient->getBroadcastReader();
            $messages = $broadcastReader->readBroadcastMessages($count, $blockMs, 'error_*');

            $processed = [];

            foreach ($messages as $message) {
                $messageType = $message['type'] ?? '';
                $fields = $message['fields'] ?? [];

                // Skip messages from our own node
                if (isset($fields['site_id'], $fields['node_id']) &&
                    $fields['site_id'] === $this->nodeMetadata['site_id'] &&
                    $fields['node_id'] === $this->nodeMetadata['node_id']) {
                    continue;
                }

                // Extract severity from message type (error_critical, error_warning, etc.)
                $severity = str_replace('error_', '', $messageType);
                $alertMessage = $fields['message'] ?? 'Unknown error';

                // Log the remote error locally
                $this->trackError($severity, "Remote error from {$fields['node_id']}: {$alertMessage}", [
                    'source_site' => $fields['site_id'] ?? 'unknown',
                    'source_node' => $fields['node_id'] ?? 'unknown',
                    'source_hostname' => $fields['hostname'] ?? 'unknown',
                    'remote_timestamp' => $fields['timestamp'] ?? null
                ]);

                $processed[] = [
                    'severity' => $severity,
                    'message' => $alertMessage,
                    'source_node' => $fields['node_id'] ?? 'unknown',
                    'timestamp' => $fields['timestamp'] ?? null
                ];
            }

            if (!empty($processed)) {
                $this->trackSystemEvent('info', "Processed remote error alerts", [
                    'count' => count($processed)
                ]);
            }

            return $processed;

        } catch (\Throwable $e) {
            $this->trackError('warning', 'Failed to listen for error alerts', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Broadcast critical error and log locally
     * Convenience method combining local logging and broadcast
     *
     * @param string $message Error message
     * @param array $context Error context
     * @param bool $broadcast Whether to broadcast (default: true)
     * @return bool Success status
     * @api
     */
    public function logCriticalError(string $message, array $context = [], bool $broadcast = true): bool {
        // Log locally first
        $success = $this->trackError('critical', $message, $context);

        // Broadcast to other nodes if enabled
        if ($broadcast && $this->useGNode) {
            $this->broadcastErrorAlert('critical', $message, $context);
        }

        return $success;
    }

    /**
     * Broadcast system health issue
     *
     * @param string $issueType Type of health issue
     * @param array $metrics Health metrics
     * @return string|false Message ID on success, false on failure
     */
    public function broadcastHealthIssue(string $issueType, array $metrics): string|false {
        if (!$this->useGNode) {
            return false;
        }

        try {
            $messageType = "health_issue_{$issueType}";
            $fields = array_merge($metrics, [
                'issue_type' => $issueType,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'timestamp' => time()
            ]);

            $messageId = $this->gNodeClient->writeBroadcastMessage($messageType, $fields);

            $this->trackSystemEvent('warning', "Broadcast health issue: {$issueType}", [
                'metrics' => $metrics,
                'message_id' => $messageId
            ]);

            return $messageId;

        } catch (\Throwable $e) {
            $this->trackError('warning', 'Failed to broadcast health issue', [
                'error' => $e->getMessage(),
                'issue_type' => $issueType
            ]);
            return false;
        }
    }

    /**
     * Broadcast node recovery notification
     *
     * @param string $recoveryType Type of recovery
     * @param array $details Recovery details
     * @return string|false Message ID on success, false on failure
     */
    public function broadcastRecovery(string $recoveryType, array $details): string|false {
        if (!$this->useGNode) {
            return false;
        }

        try {
            $messageType = "recovery_{$recoveryType}";
            $fields = array_merge($details, [
                'recovery_type' => $recoveryType,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'timestamp' => time()
            ]);

            $messageId = $this->gNodeClient->writeBroadcastMessage($messageType, $fields);

            $this->trackSystemEvent('info', "Broadcast recovery: {$recoveryType}", [
                'details' => $details,
                'message_id' => $messageId
            ]);

            return $messageId;

        } catch (\Throwable $e) {
            $this->trackError('warning', 'Failed to broadcast recovery', [
                'error' => $e->getMessage(),
                'recovery_type' => $recoveryType
            ]);
            return false;
        }
    }

    /**
     * Get error alert statistics
     *
     * @return array Statistics about error broadcasts
     */
    public function getErrorAlertStatistics(): array {
        if (!$this->useGNode) {
            return ['error' => 'gNode integration not available'];
        }

        try {
            $broadcastReader = $this->gNodeClient->getBroadcastReader();

            // Get statistics about error broadcasts
            return [
                'gnode_enabled' => $this->useGNode,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'broadcast_available' => $broadcastReader !== null
            ];

        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Set up automatic error alert monitoring
     * Starts background monitoring of error alerts
     *
     * @param callable $callback Callback to execute when error alerts received
     * @param int $interval Polling interval in seconds (default: 5)
     * @return bool Success status
     */
    public function startErrorAlertMonitoring(callable $callback, int $interval = 5): bool {
        if (!$this->useGNode) {
            return false;
        }

        try {
            // This would typically be implemented with a background process
            // For now, we just validate that we can listen
            $this->listenForErrorAlerts(1, 10);

            $this->trackSystemEvent('info', 'Error alert monitoring started', [
                'interval' => $interval
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->trackError('warning', 'Failed to start error alert monitoring', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
