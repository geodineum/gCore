<?php
/**
 * NiertoCube Error Management System
 * 
 * Provides comprehensive error handling, logging, and reporting functionality for
 * the NiertoCube theme. This is a critical system component that other modules
 * depend on for error management and reporting.
 * 
 * @package     NiertoCube
 * @subpackage  Modules
 * @since       2.0.0
 * 
 * Architecture & Integration:
 * -------------------------
 * - Implements ModuleInterface for NiertoCube core integration
 * - Uses singleton pattern for global error handling
 * - Provides PSR-3 compliant logging interface
 * - Integrates with WordPress error handling
 * 
 * Key Components:
 * -------------
 * 1. Error Logging System
 *    - File-based logging with rotation
 *    - Severity-based filtering
 *    - Context preservation
 *    - Stack trace capture
 * 
 * 2. Error Reporting
 *    - Admin notifications
 *    - Email alerts for critical errors
 *    - Dashboard widgets
 *    - Debug mode support
 * 
 * 3. Error Categories
 *    - System errors
 *    - Security issues
 *    - Performance problems
 *    - API failures
 *    - Cache errors
 * 
 * Dependencies:
 * -----------
 * - WordPress core system
 * - PHP error handling
 * 
 * Security Features:
 * ----------------
 * - Sanitized error output
 * - Protected log files
 * - Rate limiting for notifications
 * - Capability checking
 * 
 * @author    Niels Erik Toren
 * @version   2.0.0
 */

namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ErrorManager implements ModuleInterface {
    /** @var ErrorManager Singleton instance */
    private static $instance = null;

    /** @var array Error severity levels */
    private const SEVERITY_LEVELS = [
        'DEBUG'     => 100,
        'INFO'      => 200,
        'NOTICE'    => 250,
        'WARNING'   => 300,
        'ERROR'     => 400,
        'CRITICAL'  => 500,
        'ALERT'     => 550,
        'EMERGENCY' => 600
    ];

    /** @var array Error categories */
    private const ERROR_CATEGORIES = [
        'SYSTEM'     => 'system',
        'SECURITY'   => 'security',
        'CACHE'      => 'cache',
        'API'        => 'api',
        'DATABASE'   => 'database',
        'PERFORMANCE'=> 'performance',
        'USER'       => 'user',
        'CONTENT'    => 'content'
    ];

    /** @var array Configuration */
    private $config = [];
    
    /** @var bool Initialization state */
    private $initialized = false;
    
    /** @var string Log directory path */
    private $log_dir;
    
    /** @var array Error queue */
    private $error_queue = [];
    
    /** @var int Queue rotation size */
    private const MAX_QUEUE_SIZE = 1000;

    /** @var array Rate limiting tracker */
    private $rate_limits = [];

    /** @var int Maximum rate limit window */
    private const RATE_LIMIT_WINDOW = 3600; // 1 hour

    /** @var int Maximum rate limit threshold */
    private const RATE_LIMIT_THRESHOLD = 50;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize error management system
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Initialize configuration
            $this->config = array_merge([
                'enabled' => true,
                'debug' => WP_DEBUG,
                'log_rotation_size' => 5 * 1024 * 1024, // 5MB
                'log_retention_days' => 30,
                'notify_admin' => true,
                'error_categories' => array_values(self::ERROR_CATEGORIES),
                'admin_capability' => 'manage_options',
                'option_name' => 'nierto_cube_error_log',
                'email_critical' => true,
                'email_threshold' => self::SEVERITY_LEVELS['CRITICAL'],
                'compression_level' => 9,
                'rate_limit_window' => self::RATE_LIMIT_WINDOW,
                'rate_limit_threshold' => self::RATE_LIMIT_THRESHOLD
            ], $config);

            $this->log_dir = get_template_directory() . '/logs';
            $this->setupLogDirectory();
            $this->registerHooks();
            $this->setupErrorHandlers();
            $this->initializeRateLimiting();
            
            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('ErrorManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        add_action('admin_init', [$this, 'checkLogRotation']);
        add_action('admin_menu', [$this, 'addErrorReportingPage']);
        add_action('customize_register', [$this, 'registerCustomizerSettings']);
        add_action('admin_notices', [$this, 'displayErrorNotifications']);
        add_action('shutdown', [$this, 'processErrorQueue'], 999);
        add_action('wp_ajax_nierto_cube_dismiss_error', [$this, 'dismissError']);
    }

    /**
     * Setup error handlers
     */
    private function setupErrorHandlers(): void {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }

    /**
     * Initialize rate limiting
     */
    private function initializeRateLimiting(): void {
        $this->rate_limits = [
            'default' => ['timestamp' => time(), 'count' => 0],
            'email' => ['timestamp' => time(), 'count' => 0]
        ];
    }

    /**
     * Setup log directory
     */
    private function setupLogDirectory(): void {
        if (!file_exists($this->log_dir)) {
            mkdir($this->log_dir, 0755, true);
        }
        
        // Protect directory
        $htaccess = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, 'Deny from all');
        }

        // Add empty index.php
        $index = $this->log_dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, '<?php // Silence is golden');
        }
    }

    /**
     * Log error message
     */
    public function logError(
        string $message,
        array $context = [],
        string $severity = 'ERROR',
        string $category = 'SYSTEM'
    ): void {
        if (!$this->config['enabled'] || !$this->shouldLog($severity)) {
            return;
        }

        $severity_level = self::SEVERITY_LEVELS[strtoupper($severity)] ?? self::SEVERITY_LEVELS['ERROR'];
        $category = self::ERROR_CATEGORIES[strtoupper($category)] ?? self::ERROR_CATEGORIES['SYSTEM'];

        $error_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'severity' => $severity,
            'severity_level' => $severity_level,
            'category' => $category,
            'message' => $message,
            'context' => $context,
            'user_id' => get_current_user_id(),
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ];

        $this->error_queue[] = $error_entry;

        // Ensure queue doesn't exceed max size
        if (count($this->error_queue) > self::MAX_QUEUE_SIZE) {
            array_shift($this->error_queue);
        }

        // Handle critical errors immediately
        if ($severity_level >= self::SEVERITY_LEVELS['CRITICAL']) {
            $this->handleHighSeverityError($error_entry);
        }
    }

    /**
     * Process error queue on shutdown
     */
    public function processErrorQueue(): void {
        if (empty($this->error_queue)) {
            return;
        }

        $stored_errors = get_option($this->config['option_name'], []);
        $stored_errors = array_merge($stored_errors, $this->error_queue);

        // Maintain maximum size
        if (count($stored_errors) > self::MAX_QUEUE_SIZE) {
            $stored_errors = array_slice($stored_errors, -self::MAX_QUEUE_SIZE);
        }

        update_option($this->config['option_name'], $stored_errors);

        foreach ($this->error_queue as $error) {
            $this->writeErrorToLog($error);
        }

        $this->error_queue = [];
    }

    /**
     * Write error to log file
     */
    private function writeErrorToLog(array $error): void {
        $log_file = $this->log_dir . '/error.log';
        $formatted_entry = $this->formatLogEntry($error);

        if (!error_log($formatted_entry . PHP_EOL, 3, $log_file)) {
            error_log('NiertoCube ErrorManager: Failed to write to log file');
        }
    }

    /**
     * Format log entry
     */
    private function formatLogEntry(array $error): string {
        return sprintf(
            "[%s] %s.%s: %s | Context: %s | User: %d | URL: %s | IP: %s",
            $error['timestamp'],
            $error['severity'],
            $error['category'],
            $error['message'],
            json_encode($error['context']),
            $error['user_id'],
            $error['url'],
            $error['ip']
        );
    }

    /**
     * Handle high severity errors
     */
    private function handleHighSeverityError(array $error): void {
        if ($this->config['notify_admin']) {
            $this->notifyAdmin($error);
        }

        if ($this->config['email_critical'] && 
            $error['severity_level'] >= $this->config['email_threshold']) {
            $this->emailAlert($error);
        }

        $this->writeErrorToLog($error);
    }

    /**
     * Notify admin of error
     */
    private function notifyAdmin(array $error): void {
        if (!$this->checkRateLimit('default')) {
            return;
        }

        $notification = [
            'type' => 'error',
            'message' => esc_html($error['message']),
            'dismissible' => true,
            'timestamp' => time()
        ];

        $notifications = get_option('nierto_cube_error_notifications', []);
        $notifications[] = $notification;
        update_option('nierto_cube_error_notifications', $notifications);
    }

    /**
     * Send email alert
     */
    private function emailAlert(array $error): void {
        if (!$this->checkRateLimit('email')) {
            return;
        }

        $to = get_option('admin_email');
        $subject = sprintf(
            '[%s] Critical Error Alert',
            get_bloginfo('name')
        );
        
        $message = $this->formatEmailMessage($error);
        wp_mail($to, $subject, $message);
    }

    /**
     * Format email message
     */
    private function formatEmailMessage(array $error): string {
        ob_start();
        ?>
Critical Error Detected
----------------------
Time: <?php echo $error['timestamp']; ?>
Severity: <?php echo $error['severity']; ?>
Category: <?php echo $error['category']; ?>

Message:
<?php echo $error['message']; ?>

Context:
<?php echo json_encode($error['context'], JSON_PRETTY_PRINT); ?>

Stack Trace:
<?php echo $this->formatStackTrace($error['trace']); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Format stack trace
     */
    private function formatStackTrace(array $trace): string {
        $output = [];
        foreach ($trace as $i => $call) {
            $output[] = sprintf(
                "#%d %s(%d): %s%s%s()",
                $i,
                $call['file'] ?? '',
                $call['line'] ?? 0,
                $call['class'] ?? '',
                $call['type'] ?? '',
                $call['function'] ?? ''
            );
        }
        return implode("\n", $output);
    }

    /**
     * Check rate limiting
     */
    private function checkRateLimit(string $type = 'default'): bool {
        $now = time();
        
        if (!isset($this->rate_limits[$type])) {
            $this->rate_limits[$type] = ['timestamp' => $now, 'count' => 0];
        }

        // Reset counter if window has passed
        if ($now - $this->rate_limits[$type]['timestamp'] >= $this->config['rate_limit_window']) {
            $this->rate_limits[$type] = ['timestamp' => $now, 'count' => 1];
            return true;
        }

        // Check threshold
        if ($this->rate_limits[$type]['count'] >= $this->config['rate_limit_threshold']) {
            return false;
        }

        $this->rate_limits[$type]['count']++;
        return true;
    }

    /**
     * Handle PHP errors
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $severity = $this->getErrorSeverity($errno);
        $this->logError(
            $errstr,
            [
                'file' => $errfile,
                'line' => $errline,
                'errno' => $errno
            ],
            $severity,
            'SYSTEM'
        );

        return true;
    }

    /**
     * Handle uncaught exceptions
     */
    public function handleException(\Throwable $exception): void {
        $this->logError(
            $exception->getMessage(),
            [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ],
            'ERROR',
            'SYSTEM'
        );
    }

    /**
     * Handle fatal errors
     */
    public function handleFatalError(): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $this->logError(
            $error['message'],
            [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ],
            'CRITICAL',
            'SYSTEM'
        );
    }
}

    /**
     * Display error notifications in admin
     */
    public function displayErrorNotifications(): void {
        if (!current_user_can($this->config['admin_capability'])) {
            return;
        }

        $notifications = get_option('nierto_cube_error_notifications', []);
        foreach ($notifications as $index => $notification) {
            printf(
                '<div class="notice notice-%s %s"><p>%s</p>%s</div>',
                esc_attr($notification['type']),
                $notification['dismissible'] ? 'is-dismissible' : '',
                $notification['message'],
                $notification['dismissible'] ? sprintf(
                    '<button type="button" class="notice-dismiss" data-notice-id="%d"></button>',
                    $index
                ) : ''
            );
        }
    }

    /**
     * Dismiss error notification
     */
    public function dismissError(): void {
        check_ajax_referer('nierto_cube_error_dismiss', 'nonce');
        
        if (!current_user_can($this->config['admin_capability'])) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $notice_id = intval($_POST['notice_id']);
        $notifications = get_option('nierto_cube_error_notifications', []);
        
        if (isset($notifications[$notice_id])) {
            unset($notifications[$notice_id]);
            update_option('nierto_cube_error_notifications', $notifications);
            wp_send_json_success('Notice dismissed');
        } else {
            wp_send_json_error('Invalid notice ID');
        }
    }

    /**
     * Check and perform log rotation
     */
    public function checkLogRotation(): void {
        $log_file = $this->log_dir . '/error.log';
        if (!file_exists($log_file)) {
            return;
        }

        if (filesize($log_file) > $this->config['log_rotation_size']) {
            $this->rotateLog($log_file);
        }

        $this->cleanOldLogs();
    }

    /**
     * Rotate log file
     */
    private function rotateLog(string $log_file): void {
        $timestamp = date('Y-m-d-His');
        $rotated_file = "{$this->log_dir}/error-{$timestamp}.log";
        
        rename($log_file, $rotated_file);
        if (file_exists($rotated_file)) {
            $this->compressLog($rotated_file);
        }
    }

    /**
     * Compress log file
     */
    private function compressLog(string $file): void {
        $gz_file = $file . '.gz';
        $mode = 'wb' . $this->config['compression_level'];
        
        $fp_out = gzopen($gz_file, $mode);
        $fp_in = fopen($file, 'rb');
        
        while (!feof($fp_in)) {
            gzwrite($fp_out, fread($fp_in, 1024 * 512));
        }
        
        fclose($fp_in);
        gzclose($fp_out);
        unlink($file);
    }

    /**
     * Clean old logs
     */
    private function cleanOldLogs(): void {
        $files = glob($this->log_dir . '/error-*.log.gz');
        $retention_time = time() - ($this->config['log_retention_days'] * DAY_IN_SECONDS);

        foreach ($files as $file) {
            if (filemtime($file) < $retention_time) {
                unlink($file);
            }
        }
    }

    /**
     * Get error severity level string
     */
    private function getErrorSeverity(int $errno): string {
        switch ($errno) {
            case E_ERROR:
            case E_USER_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
                return 'ERROR';
            case E_WARNING:
            case E_USER_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
                return 'WARNING';
            case E_NOTICE:
            case E_USER_NOTICE:
                return 'NOTICE';
            case E_STRICT:
                return 'DEBUG';
            case E_RECOVERABLE_ERROR:
                return 'ERROR';
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'NOTICE';
            default:
                return 'ERROR';
        }
    }

    /**
     * Check if severity level should be logged
     */
    private function shouldLog(string $severity): bool {
        $severity_level = self::SEVERITY_LEVELS[strtoupper($severity)] ?? self::SEVERITY_LEVELS['ERROR'];
        return $severity_level >= $this->config['debug_level'];
    }

    /**
     * Get error statistics
     */
    public function getErrorStats(): array {
        $stored_errors = get_option($this->config['option_name'], []);
        
        $stats = [
            'total_errors' => count($stored_errors),
            'severity_counts' => [],
            'category_counts' => [],
            'recent_errors' => array_slice($stored_errors, -10)
        ];

        foreach ($stored_errors as $error) {
            $severity = $error['severity'];
            $category = $error['category'];
            
            $stats['severity_counts'][$severity] = ($stats['severity_counts'][$severity] ?? 0) + 1;
            $stats['category_counts'][$category] = ($stats['category_counts'][$category] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if initialized
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get status information
     */
    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'enabled' => $this->config['enabled'],
            'debug' => $this->config['debug'],
            'notify_admin' => $this->config['notify_admin'],
            'queue_size' => count($this->error_queue),
            'log_dir' => $this->log_dir,
            'stats' => $this->getErrorStats()
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}