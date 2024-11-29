<?php
/**
 * Enhanced InstallManager with comprehensive features
 * 
 * Handles theme installation, updates, file management, warranty tracking,
 * and deployment scenarios. Provides integrity verification and state management.
 * 
 * @package     NiertoCube
 * @subpackage  Modules
 * @version     2.1.0
 */

namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;

if (!defined('ABSPATH')) {
    exit;
}

class InstallManager implements ModuleInterface {
    /** @var InstallManager Singleton instance */
    private static $instance = null;

    /** @var ErrorManager Error handling system */
    private $error;

    /** @var MetricsManager Metrics system */
    private $metrics;

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Configuration settings */
    private $config = [];

    /** @var string Theme directory path */
    private $theme_dir;

    /** @var int Current installation state */
    private $current_state = 0;

    /** @var string Current warranty status */
    private $warranty_status = 'UNVERIFIED';

    /** @var array Current integrity status */
    private $integrity_status = [];

    /** @var array Hash registry for file verification */
    private $hash_registry = [];

    /** @var string Installation lock file path */
    private $lock_file;

    /** @var array Required directories */
    private const REQUIRED_DIRS = [
        '/logs' => 'Error and debug logs storage',
        '/cache' => 'Cache storage for optimizations',
        '/temp' => 'Temporary file storage',
        '/backups' => 'Backup storage for configuration files'
    ];

    /** @var array Required templates */
    private const REQUIRED_TEMPLATES = [
        'htaccess' => '/templates/htaccess-template.txt',
        'cookie_banner' => '/templates/cookie-banner.php'
    ];

    /** @var array Installation states */
    private const INSTALL_STATES = [
        'NONE' => 0,
        'STARTED' => 1,
        'FILES_CREATED' => 2,
        'HTACCESS_BACKUP' => 3,
        'HTACCESS_INSTALLED' => 4,
        'PERMISSIONS_SET' => 5,
        'COMPLETED' => 6
    ];

    /** @var array State markers */
    private const STATE_MARKERS = [
        'initial' => 'INITIAL',
        'in_progress' => 'IN_PROGRESS',
        'completed' => 'COMPLETED',
        'failed' => 'FAILED',
        'recovery' => 'RECOVERY'
    ];

    /** @var array Warranty status markers */
    private const WARRANTY_STATUS = [
        'valid' => 'VALID',
        'modified' => 'MODIFIED',
        'corrupted' => 'CORRUPTED',
        'unverified' => 'UNVERIFIED'
    ];

    /** @var array Hash sync configuration */
    private const HASH_SYNC = [
        'endpoint' => 'https://nierto.com/api/v1/integrity',
        'retry_delay' => 3600,
        'cache_key' => 'nierto_cube_hash_registry'
    ];

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
     * Private constructor
     */
    private function __construct() {
        $this->theme_dir = get_template_directory();
    }

    /**
     * Initialize installation manager
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            $this->verifyIntegrity();
            return;
        }

        try {
            // Core configuration with deployment awareness
            $this->config = array_merge([
                'backup_dir' => WP_CONTENT_DIR . '/backups/nierto-cube',
                'theme_dir' => get_template_directory(),
                'wp_root' => ABSPATH,
                'debug' => get_theme_mod('nierto_cube_debug_mode', WP_DEBUG),
                'deployment' => [
                    'auto_deploy' => false,
                    'git_integration' => false,
                    'environment' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : 'production',
                    'bypass_lock' => false
                ],
                'permissions' => [
                    'files' => 0644,
                    'directories' => 0755
                ]
            ], $config);

            // Initialize dependencies
            $this->error = ErrorManager::getInstance();
            $this->metrics = MetricsManager::getInstance();
            $this->lock_file = $this->config['theme_dir'] . '/temp/.installation.lock';

            // Detect deployment environment
            $this->detectDeploymentEnvironment();

            // Validate environment
            $this->validateEnvironment();

            // Register hooks
            $this->registerHooks();

            // Initialize integrity checking
            $this->initializeIntegrityChecking();

            $this->initialized = true;
            $this->logDebug('InstallManager initialized successfully');

        } catch (\Exception $e) {
            $this->logError('Initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Detect deployment environment
     */
    private function detectDeploymentEnvironment(): void {
        // Check for GitHub Actions
        if (getenv('GITHUB_ACTIONS') !== false) {
            $this->config['deployment']['auto_deploy'] = true;
            $this->config['deployment']['git_integration'] = true;
        }

        // Check for staging environment
        if (strpos($_SERVER['HTTP_HOST'] ?? '', 'staging.nierto.com') !== false) {
            $this->config['deployment']['environment'] = 'staging';
        }

        $this->logDebug('Deployment environment detected: ' . $this->config['deployment']['environment']);
    }

    /**
     * Register all hooks
     */
    private function registerHooks(): void {
        // Installation hooks
        if (!$this->isAutomaticDeployment()) {
            add_action('after_switch_theme', [$this, 'installTheme']);
            add_action('switch_theme', [$this, 'uninstallTheme']);
        }

        // Recovery hooks
        add_action('admin_init', [$this, 'checkInstallationState']);
        add_action('admin_notices', [$this, 'displayInstallationNotices']);

        // Integrity hooks
        add_action('nierto_cube_daily_sync', [$this, 'syncHashRegistry']);
        if (!wp_next_scheduled('nierto_cube_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'nierto_cube_daily_sync');
        }
    }

    /**
     * Initialize integrity checking
     */
    private function initializeIntegrityChecking(): void {
        // Load existing integrity status
        $saved_status = get_option('nierto_cube_integrity_check', []);
        if ($saved_status) {
            $this->integrity_status = $saved_status;
        }

        // Load warranty status
        $this->warranty_status = get_option(
            'nierto_cube_warranty_status',
            self::WARRANTY_STATUS['unverified']
        );

        // Initial integrity check
        $this->verifyIntegrity();
    }

/**
     * Install theme with comprehensive state management
     */
    public function installTheme(): void {
        if ($this->isInstallationLocked()) {
            $this->logDebug('Installation already in progress or completed');
            return;
        }

        try {
            // Acquire lock and set initial state
            $this->acquireInstallationLock();
            $this->setInstallationState(self::STATE_MARKERS['in_progress']);

            // Verify system requirements
            $this->verifySystemRequirements();

            // Create required directories with purpose tracking
            foreach (self::REQUIRED_DIRS as $dir => $purpose) {
                $this->createDirectory($dir, $purpose);
            }

            // Record directory creation metrics
            $this->metrics->recordMetric('installation', [
                'action' => 'directories_created',
                'count' => count(self::REQUIRED_DIRS),
                'timestamp' => time()
            ]);

            // Backup existing configuration with state tracking
            $backups = $this->backupConfigurations();
            $this->updateInstallationProgress('backups_created', $backups);

            // Install .htaccess rules with validation
            $this->installHtaccess();
            $this->validateHtaccessInstallation();

            // Set up initial options and state
            $this->setupInitialOptions();
            
            // Record installation metrics
            $this->recordInstallationMetrics();

            // Flush rewrite rules
            $this->flushRules();

            // Set final state and release lock
            $this->setInstallationState(self::STATE_MARKERS['completed']);
            $this->releaseInstallationLock();

            // Trigger post-installation hooks
            do_action('nierto_cube_after_install', $this->getInstallationReport());

        } catch (\Exception $e) {
            $this->handleInstallationFailure($e);
        }
    }

    /**
     * Create directory with purpose tracking and validation
     */
    private function createDirectory(string $dir, string $purpose): void {
        $full_path = $this->theme_dir . $dir;
        
        try {
            if (!file_exists($full_path)) {
                if (!wp_mkdir_p($full_path)) {
                    throw new \Exception("Failed to create directory: {$dir}");
                }

                // Create .htaccess to protect directory
                file_put_contents(
                    $full_path . '/.htaccess',
                    'Deny from all'
                );

                // Set directory metadata
                update_option("nierto_cube_dir_{$dir}", [
                    'purpose' => $purpose,
                    'created' => time(),
                    'permissions' => $this->config['permissions']['directories']
                ]);

                $this->logDebug("Created directory {$dir} for: {$purpose}");
            }

            // Verify directory permissions
            $this->verifyDirectoryPermissions($full_path);

        } catch (\Exception $e) {
            throw new \Exception("Directory creation failed for {$dir}: " . $e->getMessage());
        }
    }

    /**
     * Back up configurations with state tracking
     */
    private function backupConfigurations(): array {
        $backup_time = date('Y-m-d-His');
        $backups = [];

        try {
            // Define files to backup
            $files = [
                '.htaccess' => $this->config['wp_root'] . '.htaccess',
                'options' => [
                    'source' => 'options',
                    'type' => 'database'
                ]
            ];

            foreach ($files as $name => $info) {
                if (is_array($info)) {
                    // Handle database backups
                    $backup_data = $this->backupDatabaseData($info['source']);
                    $backup_path = sprintf(
                        '%s/%s-%s.json',
                        $this->config['backup_dir'],
                        $name,
                        $backup_time
                    );
                    file_put_contents($backup_path, json_encode($backup_data));
                } else {
                    // Handle file backups
                    if (file_exists($info)) {
                        $backup_path = sprintf(
                            '%s/%s-%s.bak',
                            $this->config['backup_dir'],
                            $name,
                            $backup_time
                        );
                        if (!copy($info, $backup_path)) {
                            throw new \Exception("Failed to backup {$name}");
                        }
                    }
                }

                $backups[$name] = [
                    'path' => $backup_path,
                    'time' => $backup_time,
                    'hash' => hash_file('sha256', $backup_path)
                ];

                $this->logDebug("Created backup: {$backup_path}");
            }

            // Clean old backups
            $this->cleanOldBackups();

            return $backups;

        } catch (\Exception $e) {
            $this->logError('Backup creation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update installation progress with state tracking
     */
    private function updateInstallationProgress(string $stage, array $data = []): void {
        $progress = get_option('nierto_cube_install_progress', []);
        
        $progress[$stage] = array_merge([
            'timestamp' => time(),
            'status' => 'completed'
        ], $data);

        update_option('nierto_cube_install_progress', $progress);

        // Record progress metric
        $this->metrics->recordMetric('installation_progress', [
            'stage' => $stage,
            'timestamp' => time(),
            'data' => $data
        ]);

        $this->logDebug("Installation progress updated: {$stage}");
    }

    /**
     * Handle installation failure with recovery
     */
    private function handleInstallationFailure(\Exception $e): void {
        $this->setInstallationState(self::STATE_MARKERS['failed']);
        $this->logError('Theme installation failed: ' . $e->getMessage());

        // Record failure metrics
        $this->metrics->recordMetric('installation_failure', [
            'error' => $e->getMessage(),
            'stage' => $this->getCurrentStage(),
            'timestamp' => time()
        ]);

        // Initiate automatic recovery if configured
        if ($this->config['auto_recovery']) {
            try {
                $this->recoverInstallation();
            } catch (\Exception $recovery_error) {
                $this->logError('Recovery failed: ' . $recovery_error->getMessage());
            }
        }

        $this->releaseInstallationLock();
        throw $e;
    }

    /**
     * Set up initial options with validation
     */
    private function setupInitialOptions(): void {
        $options = [
            'nierto_cube_version' => $this->config['version'],
            'nierto_cube_install_date' => time(),
            'nierto_cube_environment' => $this->config['deployment']['environment'],
            'nierto_cube_auto_recovery' => true,
            'nierto_cube_debug_mode' => $this->config['debug']
        ];

        foreach ($options as $key => $value) {
            update_option($key, $value);
        }

        // Validate options
        foreach ($options as $key => $value) {
            if (get_option($key) !== $value) {
                throw new \Exception("Failed to set option: {$key}");
            }
        }

        $this->logDebug('Initial options setup completed');
    }
    /**
     * Verify system integrity with warranty tracking
     * 
     * @param bool $force Force verification even if recently checked
     * @return bool Integrity status
     */
    public function verifyIntegrity(bool $force = false): bool {
        try {
            // Check if verification is needed
            if (!$force && $this->isRecentlyVerified()) {
                return $this->integrity_status['status'] ?? false;
            }

            // Start verification process
            $this->logDebug('Starting integrity verification');
            $verification_start = microtime(true);

            // Initialize verification state
            $this->integrity_status = [
                'timestamp' => time(),
                'violations' => [],
                'modified_files' => [],
                'status' => true
            ];

            // Sync hash registry if needed
            if ($force || $this->isHashRegistryStale()) {
                $this->syncHashRegistry();
            }

            // Verify core files
            $this->verifyCoreFiles();

            // Verify module files
            $this->verifyModuleFiles();

            // Verify configuration files
            $this->verifyConfigurationFiles();

            // Update warranty status
            $this->updateWarrantyStatus();

            // Record verification metrics
            $this->recordVerificationMetrics($verification_start);

            // Store verification results
            $this->storeVerificationResults();

            return $this->integrity_status['status'];

        } catch (\Exception $e) {
            $this->logError('Integrity verification failed: ' . $e->getMessage());
            $this->updateWarrantyStatus(self::WARRANTY_STATUS['corrupted']);
            throw $e;
        }
    }

    /**
     * Sync file hashes with central registry
     * 
     * @return bool Success status
     */
    private function syncHashRegistry(): bool {
        try {
            $this->logDebug('Starting hash registry sync');

            // Get current theme version
            $version = get_option('nierto_cube_version', '1.0.0');
            
            // Get metrics report for sync
            $metrics_report = $this->metrics->generateIntegrityReport();

            // Prepare sync data
            $sync_data = [
                'version' => $version,
                'site_url' => get_site_url(),
                'metrics' => $metrics_report,
                'current_hashes' => $this->calculateCurrentHashes(),
                'timestamp' => time(),
                'environment' => $this->config['deployment']['environment']
            ];

            // Add warranty information
            if ($this->warranty_status !== self::WARRANTY_STATUS['unverified']) {
                $sync_data['warranty_status'] = $this->warranty_status;
            }

            // Make API request
            $response = wp_remote_post(self::HASH_SYNC['endpoint'], [
                'body' => json_encode($sync_data),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Nierto-Version' => $version,
                    'X-Nierto-Site' => base64_encode(get_site_url())
                ],
                'timeout' => 15
            ]);

            if (is_wp_error($response)) {
                throw new \Exception('Hash sync request failed: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!$data || !isset($data['hash_registry'])) {
                throw new \Exception('Invalid hash registry response');
            }

            // Update local registry
            $this->hash_registry = $data['hash_registry'];
            
            // Cache the registry
            set_transient(
                self::HASH_SYNC['cache_key'], 
                $this->hash_registry, 
                DAY_IN_SECONDS
            );

            // Update last sync timestamp
            update_option('nierto_cube_last_hash_sync', time());

            $this->logDebug('Hash registry synchronized successfully');
            return true;

        } catch (\Exception $e) {
            $this->logError('Hash sync failed: ' . $e->getMessage());
            
            // Schedule retry
            wp_schedule_single_event(
                time() + self::HASH_SYNC['retry_delay'],
                'nierto_cube_retry_hash_sync'
            );
            
            return false;
        }
    }

    /**
     * Verify core system files
     */
    private function verifyCoreFiles(): void {
        $core_files = [
            'ModuleInterface.php',
            'NiertoCore.php'
        ];

        foreach ($core_files as $file) {
            $path = $this->getFilePath('core', $file);
            $current_hash = $this->calculateFileHash($path);
            
            $verification = $this->verifyAgainstRegistry(
                "/core/{$file}",
                $current_hash
            );

            if ($verification['status'] !== 'verified') {
                $this->addIntegrityViolation(
                    "/core/{$file}",
                    $verification['status'],
                    $verification['details'],
                    $verification['warranty_impact']
                );
            }
        }
    }

    /**
     * Update warranty status based on integrity check
     */
    private function updateWarrantyStatus(string $status = null): void {
        if ($status !== null) {
            $this->warranty_status = $status;
            update_option('nierto_cube_warranty_status', $status);
            return;
        }

        // Determine status based on integrity check
        if (empty($this->integrity_status)) {
            $this->warranty_status = self::WARRANTY_STATUS['unverified'];
        } elseif (empty($this->integrity_status['violations'])) {
            $this->warranty_status = self::WARRANTY_STATUS['valid'];
        } else {
            // Check for warranty-voiding violations
            foreach ($this->integrity_status['violations'] as $violation) {
                if ($violation['voids_warranty']) {
                    $this->warranty_status = self::WARRANTY_STATUS['modified'];
                    break;
                }
            }
        }

        update_option('nierto_cube_warranty_status', $this->warranty_status);

        // Record warranty change
        $this->metrics->recordMetric('warranty_status', [
            'status' => $this->warranty_status,
            'timestamp' => time(),
            'violations' => count($this->integrity_status['violations'] ?? [])
        ]);
    }

    /**
     * Add integrity violation to status
     */
    private function addIntegrityViolation(
        string $file,
        string $type,
        array $details,
        bool $voids_warranty
    ): void {
        $this->integrity_status['violations'][] = [
            'file' => $file,
            'type' => $type,
            'details' => $details,
            'timestamp' => time(),
            'voids_warranty' => $voids_warranty
        ];

        if ($voids_warranty) {
            $this->integrity_status['modified_files'][] = $file;
        }

        $this->integrity_status['status'] = false;

        // Log violation
        $this->logError(sprintf(
            'Integrity violation detected: [%s] %s - %s',
            $type,
            $file,
            implode(', ', $details)
        ));
    }

    /**
     * Record verification metrics
     */
    private function recordVerificationMetrics(float $start_time): void {
        $duration = microtime(true) - $start_time;
        
        $this->metrics->recordMetric('integrity_check', [
            'duration' => $duration,
            'files_checked' => $this->integrity_status['files_checked'] ?? 0,
            'violations_found' => count($this->integrity_status['violations']),
            'warranty_status' => $this->warranty_status,
            'timestamp' => time()
        ]);
    }

    /**
     * Store verification results
     */
    private function storeVerificationResults(): void {
        $results = array_merge($this->integrity_status, [
            'warranty_status' => $this->warranty_status,
            'last_check' => time()
        ]);

        update_option('nierto_cube_integrity_check', $results);
        
        // Notify admin if issues found
        if (!$this->integrity_status['status']) {
            $this->notifyIntegrityIssues();
        }
    }

    /**
     * Check if system was recently verified
     */
    private function isRecentlyVerified(): bool {
        $last_check = $this->integrity_status['timestamp'] ?? 0;
        return (time() - $last_check) < HOUR_IN_SECONDS;
    }

    /**
     * Notify admin of integrity issues
     */
    private function notifyIntegrityIssues(): void {
        $message = sprintf(
            'System integrity issues detected. Violations: %d. Warranty Status: %s',
            count($this->integrity_status['violations']),
            $this->warranty_status
        );

        // Add to admin notices
        add_action('admin_notices', function() use ($message) {
            echo '<div class="error"><p>' . esc_html($message) . '</p></div>';
        });

        // Send email if configured
        if ($this->config['notify_admin']) {
            wp_mail(
                get_option('admin_email'),
                'NiertoCube Integrity Alert',
                $message
            );
        }
    }

    /**
     * Get warranty information
     * 
     * @return array Warranty information
     */
    public function getWarrantyInfo(): array {
        return [
            'status' => $this->warranty_status,
            'last_verified' => $this->integrity_status['timestamp'] ?? null,
            'violations' => count($this->integrity_status['violations'] ?? []),
            'modified_files' => $this->integrity_status['modified_files'] ?? [],
            'environment' => $this->config['deployment']['environment']
        ];
    }
    /**
     * Validate environment requirements
     * 
     * @throws \Exception if requirements not met
     */
    private function validateEnvironment(): void {
        $requirements = [
            'php' => '7.4.0',
            'wordpress' => '5.9.0',
            'memory_limit' => '64M',
            'max_execution_time' => 30
        ];

        // PHP Version
        if (version_compare(PHP_VERSION, $requirements['php'], '<')) {
            throw new \Exception(
                "PHP version {$requirements['php']} or higher required. Current: " . PHP_VERSION
            );
        }

        // WordPress Version
        global $wp_version;
        if (version_compare($wp_version, $requirements['wordpress'], '<')) {
            throw new \Exception(
                "WordPress version {$requirements['wordpress']} or higher required. Current: {$wp_version}"
            );
        }

        // Memory Limit
        $memory_limit = ini_get('memory_limit');
        if ($this->convertToBytes($memory_limit) < $this->convertToBytes($requirements['memory_limit'])) {
            throw new \Exception(
                "Memory limit {$requirements['memory_limit']} or higher required. Current: {$memory_limit}"
            );
        }

        // Directory Permissions
        $this->validateDirectoryPermissions($this->theme_dir);

        $this->logDebug('Environment validation completed successfully');
    }

    /**
     * Validate directory permissions
     * 
     * @param string $dir Directory to validate
     * @throws \Exception if permissions invalid
     */
    private function validateDirectoryPermissions(string $dir): void {
        if (!is_writable($dir)) {
            throw new \Exception("Directory not writable: {$dir}");
        }

        $perms = fileperms($dir) & 0777;
        $required = $this->config['permissions']['directories'];

        if ($perms !== $required) {
            chmod($dir, $required);
            clearstatcache(true, $dir);
            
            if (($fileperms = fileperms($dir) & 0777) !== $required) {
                throw new \Exception(
                    "Failed to set directory permissions for {$dir}. " .
                    "Required: " . decoct($required) . ", Current: " . decoct($fileperms)
                );
            }
        }
    }

    /**
     * Calculate current file hashes
     * 
     * @return array File hashes with metadata
     */
    private function calculateCurrentHashes(): array {
        $hashes = [];
        $base_path = $this->config['theme_dir'];

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base_path),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if ($file->isFile() && !$this->shouldIgnoreFile($file)) {
                    $relative_path = str_replace($base_path, '', $file->getPathname());
                    $hashes[$relative_path] = [
                        'hash' => hash_file('sha256', $file->getPathname()),
                        'size' => $file->getSize(),
                        'modified' => $file->getMTime(),
                        'permissions' => fileperms($file->getPathname()) & 0777,
                        'type' => $this->getFileType($file->getPathname())
                    ];
                }
            }

            return $hashes;

        } catch (\Exception $e) {
            $this->logError('Hash calculation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Determine if file should be ignored in hash calculations
     */
    private function shouldIgnoreFile(\SplFileInfo $file): bool {
        // Ignore patterns
        $ignore_patterns = [
            '/\.(git|svn)/',
            '/node_modules/',
            '/vendor/',
            '/\.DS_Store$/',
            '/Thumbs\.db$/',
            '/\.lock$/',
            '/\.log$/'
        ];

        $path = $file->getPathname();

        foreach ($ignore_patterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get file type based on path and content
     */
    private function getFileType(string $path): string {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        $type_map = [
            'php' => 'code',
            'js' => 'script',
            'css' => 'style',
            'svg' => 'asset',
            'jpg' => 'asset',
            'jpeg' => 'asset',
            'png' => 'asset',
            'gif' => 'asset',
            'txt' => 'text',
            'md' => 'document',
            'json' => 'data'
        ];

        return $type_map[$extension] ?? 'unknown';
    }

    /**
     * Convert memory string to bytes
     */
    private function convertToBytes(string $memory_limit): int {
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int)substr($memory_limit, 0, -1);

        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Clean up old backup files
     */
    private function cleanOldBackups(): void {
        try {
            $backup_dir = $this->config['backup_dir'];
            if (!is_dir($backup_dir)) {
                return;
            }

            $retention_days = $this->config['backup_retention_days'] ?? 30;
            $cutoff = time() - ($retention_days * DAY_IN_SECONDS);

            $files = new \DirectoryIterator($backup_dir);
            foreach ($files as $file) {
                if ($file->isFile() && $file->getMTime() < $cutoff) {
                    unlink($file->getPathname());
                    $this->logDebug("Removed old backup: {$file->getFilename()}");
                }
            }

        } catch (\Exception $e) {
            $this->logError('Backup cleanup failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate installation report
     */
    private function getInstallationReport(): array {
        return [
            'version' => get_option('nierto_cube_version'),
            'install_date' => get_option('nierto_cube_install_date'),
            'environment' => $this->config['deployment']['environment'],
            'state' => $this->getInstallationState(),
            'warranty_status' => $this->warranty_status,
            'integrity' => [
                'status' => $this->integrity_status['status'] ?? false,
                'last_check' => $this->integrity_status['timestamp'] ?? null,
                'violations' => count($this->integrity_status['violations'] ?? [])
            ],
            'directories' => array_map(function($dir, $purpose) {
                $path = $this->theme_dir . $dir;
                return [
                    'path' => $path,
                    'purpose' => $purpose,
                    'exists' => file_exists($path),
                    'writable' => is_writable($path),
                    'permissions' => fileperms($path) & 0777
                ];
            }, array_keys(self::REQUIRED_DIRS), self::REQUIRED_DIRS),
            'backups' => $this->getBackupStatus(),
            'metrics' => $this->metrics->getInstallationMetrics(),
            'debug_mode' => $this->config['debug']
        ];
    }

    /**
     * Get backup status information
     */
    private function getBackupStatus(): array {
        $backup_dir = $this->config['backup_dir'];
        if (!is_dir($backup_dir)) {
            return [
                'status' => 'unavailable',
                'count' => 0
            ];
        }

        $backups = glob($backup_dir . '/*.*');
        $total_size = 0;
        $types = [];

        foreach ($backups as $backup) {
            $total_size += filesize($backup);
            $ext = pathinfo($backup, PATHINFO_EXTENSION);
            $types[$ext] = ($types[$ext] ?? 0) + 1;
        }

        return [
            'status' => 'available',
            'count' => count($backups),
            'total_size' => $total_size,
            'types' => $types,
            'latest' => !empty($backups) ? max(array_map('filemtime', $backups)) : null
        ];
    }

    /**
     * Enhanced logging methods
     */
    private function logDebug(string $message): void {
        if ($this->config['debug']) {
            $this->error->logMessage(
                $message,
                'INSTALL',
                'DEBUG',
                $this->getLogContext()
            );
        }
    }

    private function logError(string $message): void {
        $this->error->logMessage(
            $message,
            'INSTALL',
            'ERROR',
            $this->getLogContext()
        );
    }

    /**
     * Get context for logging
     */
    private function getLogContext(): array {
        return [
            'state' => $this->getInstallationState(),
            'warranty' => $this->warranty_status,
            'environment' => $this->config['deployment']['environment'],
            'debug_mode' => $this->config['debug']
        ];
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {}

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}

