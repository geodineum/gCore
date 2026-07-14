<?php
declare(strict_types=1);
/**
 * InstallManager - Extension Installation & Integrity Verification
 *
 * Handles gCore/gCube extension installation, file integrity verification,
 * warranty tracking, and .htaccess security management.
 *
 * Key Responsibilities:
 * - Install/update gCore extensions from geodineum.com registry
 * - Verify file integrity against central hash registry
 * - Track warranty status and detect tampering
 * - Manage .htaccess rules for security (firewall, banning)
 * - First-run environment setup
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\InstallManager
 * @version     3.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Base\InstallManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\gCore;

// Define constants if not in WordPress context
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

class InstallManager implements ModuleInterface
{
    use ManagerConfigTrait;

    private const DEFAULTS = [
        'site_id' => 'default',
        'node_id' => 'node1',
        'debug' => false,
        'auto_verify' => true,
        'verify_interval' => 86400,
        'htaccess_path' => null,
        'use_gnode' => true,
        'permissions' => [
            'files' => 0644,
            'directories' => 0755,
        ],
    ];

    /** @var InstallManager Singleton instance */
    private static $instance = null;

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Configuration settings */
    private $config = [];

    /** @var object|null ErrorManager instance */
    private $error = null;

    /** @var object|null CacheManager instance */
    private $cache = null;

    // =========================================================================
    // GEODINEUM REGISTRY - Central hash verification and extension registry
    // =========================================================================

    /** @var string Geodineum API base URL - ALL integrity checks go here */
    private const GEODINEUM_API_BASE = 'https://api.geodineum.com/v1';

    /** @var string Hash registry endpoint */
    private const HASH_REGISTRY_ENDPOINT = self::GEODINEUM_API_BASE . '/integrity/hashes';

    /** @var string Extension registry endpoint */
    private const EXTENSION_REGISTRY_ENDPOINT = self::GEODINEUM_API_BASE . '/extensions';

    /** @var string Tampering report endpoint */
    private const TAMPERING_REPORT_ENDPOINT = self::GEODINEUM_API_BASE . '/integrity/report';

    /** @var string License validation endpoint */
    private const LICENSE_VALIDATION_ENDPOINT = self::GEODINEUM_API_BASE . '/license/validate';

    // =========================================================================
    // STATE & TRACKING
    // =========================================================================

    /** @var string Installation base path */
    private $installationBasePath;

    /** @var string Current warranty status */
    private $warrantyStatus = 'UNVERIFIED';

    /** @var array Integrity status from last verification */
    private $integrityStatus = [];

    /** @var array Hash registry from geodineum.com */
    private $hashRegistry = [];

    /** @var array Installed extensions */
    private $installedExtensions = [];

    /** @var array Node metadata for multi-tenant isolation */
    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];

    /** @var array Capability vector for gNode integration */
    private $capabilityVector = [
        'installation' => 1.0,
        'integrity' => 0.95,
        'htaccess' => 0.9,
        'extensions' => 0.85,
        'warranty' => 0.8,
        'distributed_lock' => 0.9,
        'pubsub' => 0.85
    ];

    /** @var \gCore\gNode\gNodeClientInterface|null gNode-Client for distributed operations */
    private $gNodeClient = null;

    /** @var bool Whether gNode is available */
    private $useGNode = false;

    /** @var int Lock timeout for extension operations (seconds) */
    private const LOCK_TIMEOUT = 300; // 5 minutes

    /** @var string PubSub channel for installation notifications */
    private const PUBSUB_CHANNEL = 'gcore:install:notifications';

    /** @var array Warranty status markers */
    private const WARRANTY_STATUS = [
        'valid' => 'VALID',
        'modified' => 'MODIFIED',
        'tampered' => 'TAMPERED',
        'unverified' => 'UNVERIFIED'
    ];

    /** @var array Required directories for gCore */
    private const REQUIRED_DIRS = [
        '/logs' => 'Error and debug logs',
        '/cache' => 'Cache storage',
        '/temp' => 'Temporary files',
        '/backups' => 'Backup storage'
    ];

    /**
     * Get singleton instance
     */
    public static function getInstance(): ModuleInterface
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Initialize InstallManager
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        // Layered config: DEFAULTS → ValKey → $config arg
        $siteId = (string)($config['site_id'] ?? self::DEFAULTS['site_id']);
        $valkeyConfig = [];
        $storage = $this->gcoreResolveStorage($config);
        if ($storage !== null) {
            $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'InstallManager');
        }
        $floor = self::DEFAULTS + ['installation_base_path' => getcwd()];
        $this->config = array_merge($floor, $valkeyConfig, $config);

        // Sensitive key: license_key reads from secrets keyspace with
        // fallback to $config passthrough (legacy callers still supply it).
        if (empty($this->config['license_key']) && $storage !== null) {
            $secret = $this->gcoreGetSecret($storage, $siteId, 'InstallManager', 'license_key');
            if ($secret !== null) {
                $this->config['license_key'] = $secret;
            }
        }

        $this->nodeMetadata['site_id'] = $this->config['site_id'];
        $this->nodeMetadata['node_id'] = $this->config['node_id'];
        $this->installationBasePath = $this->config['installation_base_path'];

        // Get dependencies from gCore
        try {
            $gCore = gCore::getInstance();
            $this->error = $gCore->getService('ErrorManager');
            $this->cache = $gCore->getService('CacheManager');

            // Check for gNode-Client integration
            if (isset($config['gnode_client']) &&
                $config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface) {
                $this->gNodeClient = $config['gnode_client'];
                $this->useGNode = $this->config['use_gnode'];
                $this->log('info', 'InstallManager using gNode-Client for distributed operations', [
                    'site_id' => $this->nodeMetadata['site_id'],
                    'gnode_enabled' => $this->useGNode
                ]);
            }
        } catch (\Exception $e) {
            // Continue without dependencies - they're optional
        }

        // Load saved state
        $this->loadState();

        // Auto-verify if enabled and due
        if ($this->config['auto_verify'] && $this->isVerificationDue()) {
            $this->verifyIntegrity();
        }

        $this->initialized = true;
        $this->log('info', 'InstallManager initialized', [
            'site_id' => $this->nodeMetadata['site_id'],
            'warranty_status' => $this->warrantyStatus
        ]);
    }

    /**
     * Get capability vector for service discovery
     *
     * @return array Capability vector
     */
    public function getCapabilityVector(): array {
        return $this->capabilityVector;
    }

    // =========================================================================
    // PUBLIC API - Extension Management
    // =========================================================================

    /**
     * Get available extensions from geodineum.com registry
     *
     * @param string|null $type Filter by type: 'manager', 'theme', 'plugin'
     * @return array Available extensions
     * @api
     */
    public function getAvailableExtensions(?string $type = null): array
    {
        try {
            $response = $this->apiRequest(self::EXTENSION_REGISTRY_ENDPOINT, [
                'type' => $type,
                'site_id' => $this->nodeMetadata['site_id'],
                'installed' => array_keys($this->installedExtensions)
            ]);

            return $response['extensions'] ?? [];

        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch extensions: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Install an extension from geodineum.com
     * Uses GNODE_LOCK for concurrent installation prevention across nodes
     *
     * @param string $extensionId Extension identifier (e.g., 'translate-manager')
     * @param string|null $licenseKey License key for optional extensions
     * @return array Installation result
     * @api
     */
    public function installExtension(string $extensionId, ?string $licenseKey = null): array
    {
        $this->log('info', "Installing extension: {$extensionId}");

        $lockKey = $this->buildLockKey('install', $extensionId);
        $lockAcquired = false;

        try {
            // Acquire distributed lock to prevent concurrent installs
            if ($this->useGNode && $this->gNodeClient !== null) {
                $lockAcquired = $this->acquireLock($lockKey, self::LOCK_TIMEOUT);
                if (!$lockAcquired) {
                    return [
                        'success' => false,
                        'error' => 'Another installation is in progress for this extension',
                        'extension_id' => $extensionId,
                        'retry_after' => 30
                    ];
                }
            }

            // Validate license for optional extensions
            if ($licenseKey) {
                $licenseValid = $this->validateLicense($licenseKey, $extensionId);
                if (!$licenseValid) {
                    return [
                        'success' => false,
                        'error' => 'Invalid or expired license key',
                        'extension_id' => $extensionId
                    ];
                }
            }

            // Broadcast installation started
            $this->broadcastNotification('install_started', [
                'extension_id' => $extensionId,
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'timestamp' => time()
            ]);

            // Get extension package info from registry
            $extensionInfo = $this->apiRequest(self::EXTENSION_REGISTRY_ENDPOINT . '/' . $extensionId, [
                'license_key' => $licenseKey,
                'site_id' => $this->nodeMetadata['site_id']
            ]);

            if (!$extensionInfo || !isset($extensionInfo['download_url'])) {
                return [
                    'success' => false,
                    'error' => 'Extension not found or not available',
                    'extension_id' => $extensionId
                ];
            }

            // Download and extract
            $packagePath = $this->downloadPackage($extensionInfo['download_url']);
            $extractPath = $this->extractPackage($packagePath, $extensionInfo);

            // Verify downloaded files against registry hashes
            $hashesValid = $this->verifyExtensionHashes($extractPath, $extensionInfo['hashes'] ?? []);
            if (!$hashesValid) {
                $this->cleanupFailedInstall($extractPath);

                // Broadcast installation failed
                $this->broadcastNotification('install_failed', [
                    'extension_id' => $extensionId,
                    'reason' => 'integrity_check_failed',
                    'site_id' => $this->nodeMetadata['site_id'],
                    'timestamp' => time()
                ]);

                return [
                    'success' => false,
                    'error' => 'Package integrity verification failed',
                    'extension_id' => $extensionId
                ];
            }

            // Register installed extension
            $this->installedExtensions[$extensionId] = [
                'version' => $extensionInfo['version'],
                'installed_at' => time(),
                'license_key' => $licenseKey ? substr($licenseKey, 0, 8) . '...' : null,
                'path' => $extractPath,
                'hashes' => $extensionInfo['hashes'] ?? []
            ];

            $this->saveState();

            // Clean up temp package
            if (file_exists($packagePath)) {
                unlink($packagePath);
            }

            // Broadcast installation completed
            $this->broadcastNotification('install_completed', [
                'extension_id' => $extensionId,
                'version' => $extensionInfo['version'],
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'timestamp' => time()
            ]);

            return [
                'success' => true,
                'extension_id' => $extensionId,
                'version' => $extensionInfo['version'],
                'path' => $extractPath
            ];

        } catch (\Exception $e) {
            $this->log('error', "Extension install failed: " . $e->getMessage());

            // Broadcast installation failed
            $this->broadcastNotification('install_failed', [
                'extension_id' => $extensionId,
                'reason' => $e->getMessage(),
                'site_id' => $this->nodeMetadata['site_id'],
                'timestamp' => time()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'extension_id' => $extensionId
            ];
        } finally {
            // Always release the lock
            if ($lockAcquired && $this->useGNode && $this->gNodeClient !== null) {
                $this->releaseLock($lockKey);
            }
        }
    }

    /**
     * Update an installed extension
     *
     * @param string $extensionId Extension identifier
     * @return array Update result
     * @api
     */
    public function updateExtension(string $extensionId): array
    {
        if (!isset($this->installedExtensions[$extensionId])) {
            return [
                'success' => false,
                'error' => 'Extension not installed',
                'extension_id' => $extensionId
            ];
        }

        $installed = $this->installedExtensions[$extensionId];

        // Create backup before update
        $backupPath = $this->createBackup($extensionId, $installed['path']);

        // Re-install with existing license
        $result = $this->installExtension($extensionId, $installed['license_key'] ?? null);

        if (!$result['success'] && $backupPath) {
            // Restore from backup on failure
            $this->restoreBackup($backupPath, $installed['path']);
        }

        return $result;
    }

    /**
     * Get installed extensions
     *
     * @return array Installed extensions
     * @api
     */
    public function getInstalledExtensions(): array
    {
        return $this->installedExtensions;
    }

    // =========================================================================
    // PUBLIC API - Integrity Verification
    // =========================================================================

    /**
     * Verify system integrity against geodineum.com hash registry
     *
     * This checks all installed extensions and core files against known-good hashes.
     * Tampering is reported to geodineum.com for warranty tracking.
     *
     * @param bool $force Force verification even if recently checked
     * @return array Verification result
     * @api
     */
    public function verifyIntegrity(bool $force = false): array
    {
        if (!$force && !$this->isVerificationDue()) {
            return [
                'status' => $this->warrantyStatus,
                'last_check' => $this->integrityStatus['timestamp'] ?? null,
                'cached' => true
            ];
        }

        $this->log('info', 'Starting integrity verification');
        $startTime = microtime(true);

        $this->integrityStatus = [
            'timestamp' => time(),
            'violations' => [],
            'modified_files' => [],
            'status' => true,
            'files_checked' => 0
        ];

        try {
            // Sync hash registry from geodineum.com
            $this->syncHashRegistry();

            // Verify each installed extension
            foreach ($this->installedExtensions as $extensionId => $extension) {
                $this->verifyExtensionFiles($extensionId, $extension);
            }

            // Verify core gCore files
            $this->verifyCoreFiles();

            // Update warranty status
            $this->updateWarrantyStatus();

            // Report tampering if detected
            if (!empty($this->integrityStatus['violations'])) {
                $this->reportTampering();
            }

            $duration = microtime(true) - $startTime;
            $this->log('info', "Integrity verification completed in {$duration}s", [
                'status' => $this->warrantyStatus,
                'violations' => count($this->integrityStatus['violations'])
            ]);

            // Save state
            $this->saveState();

            return [
                'status' => $this->warrantyStatus,
                'violations' => $this->integrityStatus['violations'],
                'files_checked' => $this->integrityStatus['files_checked'],
                'duration' => $duration,
                'cached' => false
            ];

        } catch (\Exception $e) {
            $this->log('error', 'Integrity verification failed: ' . $e->getMessage());
            return [
                'status' => 'ERROR',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get warranty information
     *
     * @return array Warranty details
     * @api
     */
    public function getWarrantyInfo(): array
    {
        return [
            'status' => $this->warrantyStatus,
            'last_verified' => $this->integrityStatus['timestamp'] ?? null,
            'violations' => count($this->integrityStatus['violations'] ?? []),
            'modified_files' => $this->integrityStatus['modified_files'] ?? [],
            'site_id' => $this->nodeMetadata['site_id'],
            'extensions' => array_keys($this->installedExtensions)
        ];
    }

    /**
     * Validate a license key
     *
     * @param string $licenseKey License key to validate
     * @param string|null $product Product to validate for
     * @return bool True if valid
     * @api
     */
    public function validateLicense(string $licenseKey, ?string $product = null): bool
    {
        try {
            $response = $this->apiRequest(self::LICENSE_VALIDATION_ENDPOINT, [
                'license_key' => $licenseKey,
                'product' => $product,
                'site_id' => $this->nodeMetadata['site_id'],
                'site_url' => $this->getSiteUrl()
            ]);

            return $response['valid'] ?? false;

        } catch (\Exception $e) {
            $this->log('error', 'License validation failed: ' . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // PUBLIC API - Environment Setup
    // =========================================================================

    /**
     * First-run environment setup
     *
     * Creates required directories and configures .htaccess for security.
     * Should be called on first gCore initialization.
     *
     * @return array Setup result
     * @api
     */
    public function setupEnvironment(): array
    {
        $results = [
            'directories' => [],
            'htaccess' => false,
            'permissions' => []
        ];

        // Create required directories
        foreach (self::REQUIRED_DIRS as $dir => $purpose) {
            $fullPath = $this->installationBasePath . $dir;
            $results['directories'][$dir] = $this->createDirectory($fullPath, $purpose);
        }

        // Setup .htaccess security rules
        $results['htaccess'] = $this->setupHtaccess();

        // Validate permissions
        $results['permissions'] = $this->validatePermissions();

        $this->log('info', 'Environment setup completed', $results);

        return $results;
    }

    /**
     * Validate environment requirements
     *
     * @return array Validation results
     * @api
     */
    public function validateEnvironment(): array
    {
        $requirements = [
            'php_version' => [
                'required' => '7.4.0',
                'current' => PHP_VERSION,
                'passed' => version_compare(PHP_VERSION, '7.4.0', '>=')
            ],
            'memory_limit' => [
                'required' => '64M',
                'current' => ini_get('memory_limit'),
                'passed' => $this->convertToBytes(ini_get('memory_limit')) >= $this->convertToBytes('64M')
            ],
            'curl' => [
                'required' => true,
                'current' => extension_loaded('curl'),
                'passed' => extension_loaded('curl')
            ],
            'json' => [
                'required' => true,
                'current' => extension_loaded('json'),
                'passed' => extension_loaded('json')
            ],
            'writable_base' => [
                'required' => true,
                'current' => is_writable($this->installationBasePath),
                'passed' => is_writable($this->installationBasePath)
            ]
        ];

        // WordPress-specific checks
        if (defined('ABSPATH')) {
            global $wp_version;
            $requirements['wordpress_version'] = [
                'required' => '5.9.0',
                'current' => $wp_version ?? 'unknown',
                'passed' => isset($wp_version) && version_compare($wp_version, '5.9.0', '>=')
            ];
        }

        $allPassed = array_reduce($requirements, function ($carry, $req) {
            return $carry && $req['passed'];
        }, true);

        return [
            'passed' => $allPassed,
            'requirements' => $requirements
        ];
    }

    // =========================================================================
    // .htaccess API extracted to HtaccessManager (Commit 1.3.a / GC-D3.02).
    // Callers: \gCore\Modules\Managers\Base\HtaccessManager\HtaccessManager
    //          ::getInstance()->setupHtaccess() / addHtaccessRule() / etc.
    //
    // IP-block API extracted to IPBlockManager (Commit 1.3.b / GC-D3.02).
    // Callers: \gCore\Modules\Managers\Base\IPBlockManager\IPBlockManager
    //          ::getInstance()->blockIP() / unblockIP() / etc.
    // =========================================================================

    // =========================================================================
    // Backup API extracted to BackupManager (Commit 1.3.c / GC-D3.02).
    // Callers: \gCore\Modules\Managers\Base\BackupManager\BackupManager
    //          ::getInstance()->createBackup() / restoreBackup() / etc.
    // =========================================================================

    // =========================================================================
    // PUBLIC API - Status
    // =========================================================================

    /**
     * Get current status
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'warranty_status' => $this->warrantyStatus,
            'last_verification' => $this->integrityStatus['timestamp'] ?? null,
            'violations' => count($this->integrityStatus['violations'] ?? []),
            'installed_extensions' => count($this->installedExtensions),
            'site_id' => $this->nodeMetadata['site_id'],
            'api_endpoint' => self::GEODINEUM_API_BASE
        ];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    // =========================================================================
    // PRIVATE - API Communication
    // =========================================================================

    /**
     * Make API request to geodineum.com
     *
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param string $method HTTP method
     * @return array Response data
     * @throws \Exception on failure
     */
    private function apiRequest(string $endpoint, array $data = [], string $method = 'POST'): array
    {
        $data['site_id'] = $this->nodeMetadata['site_id'];
        $data['site_url'] = $this->getSiteUrl();
        $data['gcore_version'] = '3.0.0';
        $data['php_version'] = PHP_VERSION;

        if (function_exists('wp_remote_request')) {
            // WordPress HTTP API
            $response = wp_remote_request($endpoint, [
                'method' => $method,
                'body' => json_encode($data),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-GCore-Site' => base64_encode($this->getSiteUrl()),
                    'X-GCore-License' => $this->config['license_key'] ?? ''
                ],
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                throw new \Exception('API request failed: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

        } else {
            // cURL fallback
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-GCore-Site: ' . base64_encode($this->getSiteUrl()),
                    'X-GCore-License: ' . ($this->config['license_key'] ?? '')
                ],
                CURLOPT_TIMEOUT => 30
            ]);

            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \Exception('API request failed: ' . $error);
            }
        }

        if ($code !== 200) {
            throw new \Exception("API returned status {$code}");
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response from API');
        }

        return $decoded;
    }

    /**
     * Sync hash registry from geodineum.com
     */
    private function syncHashRegistry(): void
    {
        try {
            $response = $this->apiRequest(self::HASH_REGISTRY_ENDPOINT, [
                'installed_extensions' => array_keys($this->installedExtensions),
                'extension_versions' => array_map(function ($ext) {
                    return $ext['version'] ?? 'unknown';
                }, $this->installedExtensions)
            ]);

            $this->hashRegistry = $response['hashes'] ?? [];

            // Cache the registry
            $this->cacheSet('gcore_hash_registry', $this->hashRegistry, DAY_IN_SECONDS);

            $this->log('info', 'Hash registry synced from geodineum.com', [
                'files' => count($this->hashRegistry)
            ]);

        } catch (\Exception $e) {
            $this->log('warning', 'Failed to sync hash registry: ' . $e->getMessage());

            // Try to use cached registry
            $cached = $this->cacheGet('gcore_hash_registry');
            if ($cached) {
                $this->hashRegistry = $cached;
            }
        }
    }

    /**
     * Report tampering to geodineum.com
     */
    private function reportTampering(): void
    {
        try {
            $this->apiRequest(self::TAMPERING_REPORT_ENDPOINT, [
                'violations' => $this->integrityStatus['violations'],
                'modified_files' => $this->integrityStatus['modified_files'],
                'warranty_status' => $this->warrantyStatus,
                'extensions' => $this->installedExtensions,
                'timestamp' => time()
            ]);

            $this->log('warning', 'Tampering reported to geodineum.com', [
                'violations' => count($this->integrityStatus['violations'])
            ]);

        } catch (\Exception $e) {
            $this->log('error', 'Failed to report tampering: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // PRIVATE - Integrity Verification
    // =========================================================================

    /**
     * Verify extension files against hash registry
     */
    private function verifyExtensionFiles(string $extensionId, array $extension): void
    {
        $basePath = $extension['path'] ?? '';
        $expectedHashes = $extension['hashes'] ?? [];

        if (!$basePath || !file_exists($basePath)) {
            $this->addViolation($extensionId, 'missing', "Extension directory not found: {$basePath}");
            return;
        }

        foreach ($expectedHashes as $relativePath => $expectedHash) {
            $fullPath = $basePath . '/' . ltrim($relativePath, '/');
            $this->integrityStatus['files_checked']++;

            if (!file_exists($fullPath)) {
                $this->addViolation($extensionId, 'missing_file', "File missing: {$relativePath}");
                continue;
            }

            $actualHash = hash_file('sha256', $fullPath);
            if ($actualHash !== $expectedHash) {
                $this->addViolation($extensionId, 'modified', "File modified: {$relativePath}", true);
                $this->integrityStatus['modified_files'][] = $fullPath;
            }
        }
    }

    /**
     * Verify core gCore files
     */
    private function verifyCoreFiles(): void
    {
        if (empty($this->hashRegistry['core'])) {
            return;
        }

        $gCorePath = dirname(dirname(dirname(dirname(__DIR__))));

        foreach ($this->hashRegistry['core'] as $relativePath => $expectedHash) {
            $fullPath = $gCorePath . '/' . ltrim($relativePath, '/');
            $this->integrityStatus['files_checked']++;

            if (!file_exists($fullPath)) {
                continue; // Core file missing is not a violation (might be optional)
            }

            $actualHash = hash_file('sha256', $fullPath);
            if ($actualHash !== $expectedHash) {
                $this->addViolation('gcore', 'core_modified', "Core file modified: {$relativePath}", true);
                $this->integrityStatus['modified_files'][] = $fullPath;
            }
        }
    }

    /**
     * Verify downloaded extension hashes
     */
    private function verifyExtensionHashes(string $path, array $expectedHashes): bool
    {
        foreach ($expectedHashes as $relativePath => $expectedHash) {
            $fullPath = $path . '/' . ltrim($relativePath, '/');

            if (!file_exists($fullPath)) {
                $this->log('warning', "Downloaded file missing: {$relativePath}");
                return false;
            }

            $actualHash = hash_file('sha256', $fullPath);
            if ($actualHash !== $expectedHash) {
                $this->log('warning', "Hash mismatch for: {$relativePath}");
                return false;
            }
        }

        return true;
    }

    /**
     * Add integrity violation
     */
    private function addViolation(string $component, string $type, string $message, bool $voidsWarranty = false): void
    {
        $this->integrityStatus['violations'][] = [
            'component' => $component,
            'type' => $type,
            'message' => $message,
            'timestamp' => time(),
            'voids_warranty' => $voidsWarranty
        ];

        $this->integrityStatus['status'] = false;

        $this->log('warning', "Integrity violation: [{$type}] {$message}");
    }

    /**
     * Update warranty status based on violations
     */
    private function updateWarrantyStatus(): void
    {
        if (empty($this->integrityStatus['violations'])) {
            $this->warrantyStatus = self::WARRANTY_STATUS['valid'];
        } else {
            $hasWarrantyViolation = false;
            foreach ($this->integrityStatus['violations'] as $violation) {
                if ($violation['voids_warranty'] ?? false) {
                    $hasWarrantyViolation = true;
                    break;
                }
            }

            $this->warrantyStatus = $hasWarrantyViolation
                ? self::WARRANTY_STATUS['tampered']
                : self::WARRANTY_STATUS['modified'];
        }
    }

    /**
     * Check if verification is due
     */
    private function isVerificationDue(): bool
    {
        $lastCheck = $this->integrityStatus['timestamp'] ?? 0;
        return (time() - $lastCheck) > $this->config['verify_interval'];
    }

    // =========================================================================
    // PRIVATE - htaccess Helpers
    // =========================================================================

    // Private .htaccess helpers (getHtaccessPath, generateHtaccessRules,
    // ensureIPBlockSection) extracted to HtaccessManager (1.3.a).

    // =========================================================================
    // PRIVATE - File Operations
    // =========================================================================

    /**
     * Create directory with protection
     */
    private function createDirectory(string $path, string $purpose = ''): bool
    {
        try {
            if (!file_exists($path)) {
                if (function_exists('wp_mkdir_p')) {
                    wp_mkdir_p($path);
                } else {
                    mkdir($path, $this->config['permissions']['directories'], true);
                }
            }

            // Protect directory with .htaccess
            $htaccessPath = $path . '/.htaccess';
            if (!file_exists($htaccessPath)) {
                file_put_contents($htaccessPath, "Order deny,allow\nDeny from all");
            }

            return true;

        } catch (\Exception $e) {
            $this->log('error', "Failed to create directory {$path}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Download package from URL
     */
    private function downloadPackage(string $url): string
    {
        // GC-D2.04 (Commit 1.2.c): host-allowlist + HTTPS-only + bounded
        // redirect chain. Pre-fix the cURL fallback set FOLLOWLOCATION=true
        // with no CURLOPT_MAXREDIRS and no CURLOPT_PROTOCOLS restriction,
        // so a compromised registry response or a DNS-poisoned lookup
        // could coerce cURL into reading file://, gopher://, or internal
        // IMDS endpoints like http://169.254.169.254/. Reject anything
        // outside the canonical download host(s) at parse time.
        $parts = parse_url($url);
        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
        ) {
            throw new \Exception('Download rejected: only https:// URLs are permitted');
        }
        $host = strtolower($parts['host']);
        $allowedHosts = ['api.geodineum.com', 'registry.geodineum.com'];
        if (!in_array($host, $allowedHosts, true)) {
            throw new \Exception('Download rejected: host not in allowlist (' . $host . ')');
        }

        $tempDir = $this->installationBasePath . '/temp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, $this->config['permissions']['directories'], true);
        }

        $tempFile = $tempDir . '/' . uniqid('pkg_') . '.zip';

        if (function_exists('download_url')) {
            // WordPress
            $downloaded = download_url($url);
            if (is_wp_error($downloaded)) {
                throw new \Exception('Download failed: ' . $downloaded->get_error_message());
            }
            rename($downloaded, $tempFile);
        } else {
            // cURL fallback. Hardened per GC-D2.04:
            //  - CURLOPT_PROTOCOLS restricts scheme to HTTPS so redirects
            //    can't downgrade to file:// / gopher:// / etc.
            //  - CURLOPT_REDIR_PROTOCOLS mirrors that for the redirect path.
            //  - CURLOPT_MAXREDIRS=3 bounds redirect chains (DoS + loops).
            $fp = fopen($tempFile, 'w');
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fp);

            if ($error) {
                throw new \Exception('Download failed: ' . $error);
            }
        }

        return $tempFile;
    }

    /**
     * Extract package to destination
     */
    private function extractPackage(string $packagePath, array $extensionInfo): string
    {
        $extractPath = $this->installationBasePath . '/extensions/' . ($extensionInfo['id'] ?? uniqid());

        if (file_exists($extractPath)) {
            $this->removeDirectory($extractPath);
        }

        mkdir($extractPath, $this->config['permissions']['directories'], true);

        $zip = new \ZipArchive();
        if ($zip->open($packagePath) === true) {
            // GC-D2.05 (Commit 1.2.c): reject zip-slip entries BEFORE
            // extractTo. ZipArchive does not reject "../" or absolute
            // paths by default; an entry like
            //   "../../wp-config.php"
            // would write under $extractPath's parent at php-fpm uid.
            $entryCount = $zip->numFiles;
            for ($i = 0; $i < $entryCount; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || !isset($stat['name'])) {
                    $zip->close();
                    throw new \Exception('Invalid ZIP archive (unreadable entry)');
                }
                $name = (string) $stat['name'];
                $normalized = str_replace('\\', '/', $name);
                if ($normalized === ''
                    || $normalized[0] === '/'
                    || preg_match('#(^|/)\.\.(/|$)#', $normalized)
                    || strpos($name, "\0") !== false
                ) {
                    $zip->close();
                    throw new \Exception('Rejected: ZIP entry escapes archive root: ' . $name);
                }
            }
            $zip->extractTo($extractPath);
            $zip->close();
        } else {
            throw new \Exception('Failed to extract package');
        }

        return $extractPath;
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectory(string $source, string $dest): void
    {
        if (!file_exists($dest)) {
            mkdir($dest, $this->config['permissions']['directories'], true);
        }

        $dir = new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $destPath = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!file_exists($destPath)) {
                    mkdir($destPath, $this->config['permissions']['directories']);
                }
            } else {
                copy($item, $destPath);
            }
        }
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $dir = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item);
            } else {
                unlink($item);
            }
        }

        rmdir($path);
    }

    /**
     * Clean up failed installation
     */
    private function cleanupFailedInstall(string $path): void
    {
        if (file_exists($path)) {
            $this->removeDirectory($path);
        }
    }

    /**
     * Validate directory permissions
     */
    private function validatePermissions(): array
    {
        $results = [];

        foreach (self::REQUIRED_DIRS as $dir => $purpose) {
            $fullPath = $this->installationBasePath . $dir;
            $results[$dir] = [
                'exists' => file_exists($fullPath),
                'writable' => is_writable($fullPath),
                'permissions' => file_exists($fullPath) ? decoct(fileperms($fullPath) & 0777) : null
            ];
        }

        return $results;
    }

    // =========================================================================
    // PRIVATE - State Management
    // =========================================================================

    /**
     * Load saved state
     */
    private function loadState(): void
    {
        $state = $this->cacheGet('gcore_install_state');

        if ($state) {
            $this->warrantyStatus = $state['warranty_status'] ?? self::WARRANTY_STATUS['unverified'];
            $this->integrityStatus = $state['integrity_status'] ?? [];
            $this->installedExtensions = $state['installed_extensions'] ?? [];
        }
    }

    /**
     * Save current state
     */
    private function saveState(): void
    {
        $state = [
            'warranty_status' => $this->warrantyStatus,
            'integrity_status' => $this->integrityStatus,
            'installed_extensions' => $this->installedExtensions,
            'updated_at' => time()
        ];

        $this->cacheSet('gcore_install_state', $state, 0);
    }

    // =========================================================================
    // PRIVATE - Utilities
    // =========================================================================

    /**
     * Get site URL
     */
    private function getSiteUrl(): string
    {
        if (function_exists('get_site_url')) {
            return get_site_url();
        }

        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'];
        }

        return 'http://localhost';
    }

    /**
     * Convert memory string to bytes
     */
    private function convertToBytes(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;

        switch ($unit) {
            case 'g': $bytes *= 1024;
            case 'm': $bytes *= 1024;
            case 'k': $bytes *= 1024;
        }

        return $bytes;
    }

    /**
     * Logging wrapper
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $context['site_id'] = $this->nodeMetadata['site_id'];

        if ($this->error) {
            $this->error->logMessage($message, 'INSTALL', strtoupper($level), $context);
        } elseif ($this->config['debug'] || $level === 'error') {
            error_log("[InstallManager][{$level}] {$message} " . json_encode($context));
        }
    }

    // =========================================================================
    // gNode INTEGRATION - Distributed Lock, PubSub, Cache
    // =========================================================================

    /**
     * Build gNode lock key with proper prefixing
     *
     * @param string $operation Operation type (install, update, verify)
     * @param string $resource Resource identifier
     * @return string Lock key
     */
    private function buildLockKey(string $operation, string $resource): string {
        return sprintf(
            '{%s}:install:lock:%s:%s',
            $this->nodeMetadata['site_id'],
            $operation,
            $resource
        );
    }

    /**
     * Build gNode cache key with proper prefixing
     *
     * @param string $key Base key
     * @return string gNode-formatted key
     */
    private function buildGNodeKey(string $key): string {
        return sprintf(
            '{%s}:install:%s',
            $this->nodeMetadata['site_id'],
            $key
        );
    }

    /**
     * Acquire distributed lock using GNODE_LOCK_ACQUIRE
     *
     * @param string $lockKey Lock key
     * @param int $timeout Lock timeout in seconds
     * @return bool True if lock acquired
     */
    private function acquireLock(string $lockKey, int $timeout): bool {
        if (!$this->useGNode || $this->gNodeClient === null) {
            return true; // No gNode = no distributed locking
        }

        try {
            // Use GNODE_LOCK_ACQUIRE Lua function
            // Parameters: key, timeout, owner_id
            $ownerId = $this->nodeMetadata['node_id'] . ':' . getmypid();

            $result = $this->gNodeClient->fcall('GNODE_LOCK_ACQUIRE', [$lockKey], [
                (string)$timeout,
                $ownerId,
                $this->nodeMetadata['site_id']
            ]);

            $acquired = ($result === 'OK' || $result === true || $result === 1);

            if ($acquired) {
                $this->log('debug', "Lock acquired: {$lockKey}");
            } else {
                $this->log('debug', "Lock not available: {$lockKey}");
            }

            return $acquired;

        } catch (\Exception $e) {
            $this->log('warning', "Lock acquisition failed: {$e->getMessage()}");
            return true; // Fail open to allow operation
        }
    }

    /**
     * Release distributed lock using GNODE_LOCK_RELEASE
     *
     * @param string $lockKey Lock key
     * @return bool True if lock released
     */
    private function releaseLock(string $lockKey): bool {
        if (!$this->useGNode || $this->gNodeClient === null) {
            return true;
        }

        try {
            $ownerId = $this->nodeMetadata['node_id'] . ':' . getmypid();

            $result = $this->gNodeClient->fcall('GNODE_LOCK_RELEASE', [$lockKey], [
                $ownerId,
                $this->nodeMetadata['site_id']
            ]);

            $this->log('debug', "Lock released: {$lockKey}");
            return true;

        } catch (\Exception $e) {
            $this->log('warning', "Lock release failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Broadcast notification to all nodes via gNode_PUBSUB
     * Notifies other nodes about installation events for cache invalidation
     *
     * @param string $event Event type (install_started, install_completed, install_failed, etc.)
     * @param array $data Event data
     * @return bool True if broadcast sent
     */
    private function broadcastNotification(string $event, array $data): bool {
        if (!$this->useGNode || $this->gNodeClient === null) {
            return true; // No gNode = no broadcast needed
        }

        try {
            $message = json_encode([
                'event' => $event,
                'data' => $data,
                'source_node' => $this->nodeMetadata['node_id'],
                'source_site' => $this->nodeMetadata['site_id'],
                'timestamp' => microtime(true)
            ]);

            $channel = self::PUBSUB_CHANNEL . ':' . $this->nodeMetadata['site_id'];

            // Use gNode publish method
            $result = $this->gNodeClient->publish($channel, $message);

            $this->log('debug', "Broadcast sent: {$event}", [
                'channel' => $channel,
                'subscribers' => $result
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log('warning', "Broadcast failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Subscribe to installation notifications
     * Other nodes can use this to listen for installation events
     *
     * @param callable $callback Callback function for notifications
     * @return bool True if subscribed
     * @api
     */
    public function subscribeToNotifications(callable $callback): bool {
        if (!$this->useGNode || $this->gNodeClient === null) {
            return false;
        }

        try {
            $channel = self::PUBSUB_CHANNEL . ':' . $this->nodeMetadata['site_id'];

            // Note: This requires async subscription support in gNode-Client
            // For now, provide the channel name for external subscription
            $this->log('info', "Subscription channel available: {$channel}");

            return true;

        } catch (\Exception $e) {
            $this->log('error', "Subscription failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get notification channel name for external subscription
     *
     * @return string Channel name
     */
    public function getNotificationChannel(): string {
        return self::PUBSUB_CHANNEL . ':' . $this->nodeMetadata['site_id'];
    }

    /**
     * Cache get using gNode when available
     */
    private function cacheGet(string $key)
    {
        // Try gNode first
        if ($this->useGNode && $this->gNodeClient !== null) {
            try {
                $gNodeKey = $this->buildGNodeKey($key);
                $result = $this->gNodeClient->fcall('GNODE_CACHE_GET', [], [
                    $gNodeKey,
                    $this->nodeMetadata['site_id']
                ]);

                if ($result !== null && $result !== false && is_string($result)) {
                    $decoded = json_decode($result, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }
            } catch (\Exception $e) {
                $this->log('warning', 'gNode cacheGet failed, falling back to CacheManager/WordPress: ' . $e->getMessage());
            }
        }

        // Fallback to CacheManager
        if ($this->cache) {
            return $this->cache->get($key, $this->nodeMetadata['site_id']);
        }

        // Fallback to WordPress option
        if (function_exists('get_option')) {
            return get_option($key, null);
        }

        return null;
    }

    /**
     * Cache set using gNode when available
     */
    private function cacheSet(string $key, $value, int $ttl = 0): void
    {
        // Try gNode first
        if ($this->useGNode && $this->gNodeClient !== null) {
            try {
                $gNodeKey = $this->buildGNodeKey($key);
                $serialized = json_encode($value);

                $this->gNodeClient->fcall('GNODE_CACHE_SET', [], [
                    $gNodeKey,
                    $serialized,
                    (string)$ttl,
                    $this->nodeMetadata['site_id'],
                    'false',
                    'false'
                ]);
                return;
            } catch (\Exception $e) {
                $this->log('warning', 'gNode cacheSet failed, falling back to CacheManager/WordPress: ' . $e->getMessage());
            }
        }

        // Fallback to CacheManager
        if ($this->cache) {
            $this->cache->set($key, $value, $ttl, $this->nodeMetadata['site_id']);
            return;
        }

        // Fallback to WordPress option
        if (function_exists('update_option')) {
            update_option($key, $value);
        }
    }

    // Singleton protection
    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
