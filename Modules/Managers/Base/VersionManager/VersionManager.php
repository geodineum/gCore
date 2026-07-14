<?php
declare(strict_types=1);
/**
 * VersionManager - Advanced Version Control and Cache Invalidation System
 *
 * Provides version tracking and cache invalidation functionality.
 * Framework-agnostic with conditional WordPress support for maximum flexibility.
 *
 * ARCHITECTURE
 * ------------
 * - Implements Singleton pattern for centralized version management
 * - Uses WordPress options API when available, file storage otherwise
 * - Provides group-based version tracking with automatic invalidation
 * - Integrates with WordPress when present, works standalone otherwise
 *
 * KEY FEATURES
 * -----------
 * 1. Version Management:
 *    - Group-specific version tracking
 *    - Automatic version incrementation
 *    - Version history support (optional)
 *    - Multi-tenant isolation (site_id/node_id)
 *
 * 2. Cache Integration:
 *    - Versioned cache key generation
 *    - Group-based cache invalidation
 *    - Prefix management for cache segregation
 *    - Automatic cache busting on theme/plugin updates (WordPress)
 *
 * 3. Framework Integration:
 *    - WordPress integration when available
 *    - Generic PHP fallback mode
 *    - File-based storage for non-WordPress contexts
 *    - gNode capability registration
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\VersionManager
 * @implements  ModuleInterface
 * @since       2.0.0
 * @author      Niels Erik Toren
 * @copyright   2024 gCore
 * @version     2.0.0
 */

namespace gCore\Modules\Managers\Base\VersionManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\gCore;
use gCore\Modules\Core\Shared\ManagerConfigTrait;

// Define WordPress constants if not in WordPress context
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

class VersionManager implements ModuleInterface {
    use ManagerConfigTrait;

    /** @var VersionManager Singleton instance */
    private static $instance = null;

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Version numbers for each cache group */
    private $versions = [];

    /** @var array Configuration settings */
    private $config = [];

    /** @var string Option name for storing versions */
    private const VERSION_OPTION = 'gCore_cache_versions';

    /** @var array Default versions for core groups */
    private const DEFAULT_GROUPS = [
        'core' => 1,
        'face' => 1,
        'api' => 1,
        'manifest' => 1
    ];

    /** @var array Node metadata for multi-tenant isolation */
    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];

    /** @var array Capability vector for gNode integration */
    private $capabilityVector = [
        'versioning' => 1.0,
        'cache_busting' => 0.95,
        'invalidation' => 0.9,
        'tracking' => 0.85
    ];

    /** @var \gCore\gNode\gNodeClientInterface|null gNode-Client instance for topology registration */
    private $gNodeClient = null;

    /** @var string|null Storage file path for non-WordPress contexts */
    private $storageFile = null;

    /** @var \gCore\Modules\Managers\Base\CacheManager\CacheManager|null CacheManager instance */
    private $cacheManager = null;

    /** @var bool Whether gNode functions are available */
    private $gNodeAvailable = false;

    /** @var array Version history storage */
    private $history = [];

    /** @var string Cache key prefix for version storage */
    private const CACHE_KEY_PREFIX = 'version_registry_';

    /** @var string History cache key prefix */
    private const HISTORY_KEY_PREFIX = 'version_history_';

    /** @var int Maximum history entries per group */
    private const MAX_HISTORY_ENTRIES = 100;

    /** @var array Default configuration */
    private $default_config = [
        'version_prefix' => 'gCore_',
        'auto_increment' => true,
        'store_history' => true,
        'debug' => false,
        'ttl' => DAY_IN_SECONDS,
        'storage_path' => null,
        'site_id' => 'default',
        'node_id' => 'node1'
    ];

    /**
     * Get singleton instance
     *
     * @return ModuleInterface VersionManager instance
     */
    public static function getInstance(): ModuleInterface {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {}

    /**
     * Initialize the module
     *
     * @param array $config Configuration options
     * @throws \Exception If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Layered config resolution (lowest priority → highest):
            //   1. self::$default_config              — hardcoded floor
            //   2. ValKey HGETALL (defaults+override) — site → bootloader-default
            //   3. $config arg                         — caller passthrough
            //
            // The WP_DEBUG override that previously clobbered user-supplied
            // `debug` config (audit inconsistency #6) is GONE — operators
            // who explicitly set `debug: false` in $config or in the
            // per-site ValKey override now have their choice respected,
            // even when WP_DEBUG=true. WP_DEBUG only takes effect when
            // no explicit `debug` value exists anywhere.
            $siteId = (string)($config['site_id'] ?? $this->default_config['site_id']);

            // Stash gNode-Client for downstream use (preserves legacy
            // behaviour — it's still needed by registerCapabilities + sync paths).
            if (isset($config['gnode_client']) &&
                $config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface) {
                $this->gNodeClient = $config['gnode_client'];
            }

            // ValKey layer (best-effort — failures don't break boot)
            $valkeyConfig = [];
            $storage = $this->gcoreResolveStorage($config);
            if ($storage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'VersionManager');
            }

            // Floor: hardcoded defaults with WP_DEBUG-aware `debug` ONLY if
            // no other layer provides it. This preserves the legacy
            // behaviour for callers that don't supply config but no
            // longer silently overrides their explicit choice.
            $floorDefaults = $this->default_config;
            $debugWasExplicit = array_key_exists('debug', $valkeyConfig) || array_key_exists('debug', $config);
            if (!$debugWasExplicit) {
                $floorDefaults['debug'] = defined('WP_DEBUG') ? WP_DEBUG : false;
            }

            $this->config = array_merge($floorDefaults, $valkeyConfig, $config);

            // Store node metadata for multi-tenant isolation. Audit fix:
            // node_id default is now consistently "node1" (was a mix of
            // "node1" and "default" across managers).
            $this->nodeMetadata['site_id'] = $this->config['site_id'] ?? 'default';
            $this->nodeMetadata['node_id'] = $this->config['node_id'] ?? 'node1';

            // Setup storage file for non-WordPress contexts
            if ($this->config['storage_path']) {
                $this->storageFile = $this->buildSafeStoragePath(
                    $this->config['storage_path'],
                    $this->nodeMetadata['site_id'],
                    $this->nodeMetadata['node_id']
                );
            }

            // Get CacheManager for distributed version storage
            $this->initializeCacheManager();

            // Check gNode availability for atomic operations
            $this->checkGNodeAvailability();

            $this->loadVersions();

            // Load version history if enabled
            if ($this->config['store_history']) {
                $this->loadHistory();
            }

            // Register WordPress hooks if in WordPress context
            if (defined('ABSPATH') && function_exists('add_action')) {
                $this->registerHooks();
            }

            $this->initialized = true;

            if ($this->config['debug']) {
                error_log('VersionManager initialized for site: ' . $this->nodeMetadata['site_id'] .
                    ', node: ' . $this->nodeMetadata['node_id']);
            }

        } catch (\Exception $e) {
            error_log('VersionManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Initialize CacheManager for distributed version storage
     */
    private function initializeCacheManager(): void {
        try {
            $core = gCore::getInstance();
            $this->cacheManager = $core->getService('CacheManager');
        } catch (\Throwable $e) {
            $this->cacheManager = null;
            if ($this->config['debug']) {
                error_log('VersionManager: CacheManager not available - ' . $e->getMessage());
            }
        }
    }

    /**
     * Check if gNode functions are available for atomic operations
     */
    private function checkGNodeAvailability(): void {
        if ($this->cacheManager === null) {
            $this->gNodeAvailable = false;
            return;
        }

        try {
            $result = $this->cacheManager->fcall('GNODE_UTILS_SERVER_INFO', [
                $this->nodeMetadata['site_id']
            ]);
            $this->gNodeAvailable = ($result !== null && $result !== false);
        } catch (\Throwable $e) {
            $this->gNodeAvailable = false;
            if ($this->config['debug']) {
                error_log('VersionManager: gNode functions not available - ' . $e->getMessage());
            }
        }
    }

    /**
     * Build a safe storage path with validation
     *
     * Prevents path traversal attacks and validates input parameters.
     *
     * @param string $basePath Base storage directory
     * @param string $siteId Site identifier
     * @param string $nodeId Node identifier
     * @return string|null Safe file path or null if validation fails
     */
    private function buildSafeStoragePath(string $basePath, string $siteId, string $nodeId): ?string {
        // Sanitize site_id and node_id - allow only alphanumeric, dash, underscore
        $safeSiteId = preg_replace('/[^a-zA-Z0-9_-]/', '', $siteId);
        $safeNodeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $nodeId);

        if (empty($safeSiteId) || empty($safeNodeId)) {
            if ($this->config['debug']) {
                error_log('VersionManager: Invalid site_id or node_id after sanitization');
            }
            return null;
        }

        // Resolve and validate base path
        $realBasePath = realpath($basePath);
        if ($realBasePath === false) {
            // Path doesn't exist yet - validate parent directory
            $parentPath = dirname($basePath);
            $realParent = realpath($parentPath);
            if ($realParent === false) {
                if ($this->config['debug']) {
                    error_log('VersionManager: Storage path parent directory does not exist: ' . $parentPath);
                }
                return null;
            }
            // Use the intended path under valid parent
            $realBasePath = $realParent . DIRECTORY_SEPARATOR . basename($basePath);
        }

        // Ensure no path traversal in base path
        if (strpos($basePath, '..') !== false) {
            if ($this->config['debug']) {
                error_log('VersionManager: Path traversal detected in storage_path');
            }
            return null;
        }

        // Build final path
        $filename = 'versions_' . $safeSiteId . '_' . $safeNodeId . '.json';
        return $realBasePath . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Load versions from storage
     *
     * Priority: CacheManager -> WordPress options -> File storage -> Defaults
     */
    private function loadVersions(): void {
        $saved_versions = [];

        // 1. Try CacheManager first (distributed)
        if ($this->cacheManager !== null) {
            try {
                $cacheKey = self::CACHE_KEY_PREFIX . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
                $cached = $this->cacheManager->get($cacheKey);
                if ($cached && is_array($cached)) {
                    $saved_versions = $cached;
                }
            } catch (\Throwable $e) {
                error_log('[gCore] VersionManager: Failed to load from CacheManager - ' . $e->getMessage());
            }
        }

        // 2. Fallback to WordPress options
        if (empty($saved_versions) && function_exists('get_option')) {
            $saved_versions = get_option(self::VERSION_OPTION, []);
        }

        // 3. Fallback to file storage
        if (empty($saved_versions) && $this->storageFile && file_exists($this->storageFile)) {
            try {
                $json = file_get_contents($this->storageFile);
                if ($json !== false) {
                    $saved_versions = json_decode($json, true) ?: [];
                }
            } catch (\Throwable $e) {
                error_log('[gCore] VersionManager: Failed to load from file - ' . $e->getMessage());
            }
        }

        $this->versions = array_merge(self::DEFAULT_GROUPS, $saved_versions);
    }

    /**
     * Load version history from storage
     */
    private function loadHistory(): void {
        if ($this->cacheManager === null) {
            return;
        }

        try {
            $historyKey = self::HISTORY_KEY_PREFIX . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
            $cached = $this->cacheManager->get($historyKey);
            if ($cached && is_array($cached)) {
                $this->history = $cached;
            }
        } catch (\Throwable $e) {
            error_log('[gCore] VersionManager: Failed to load history - ' . $e->getMessage());
        }
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('switch_theme', [$this, 'incrementAllVersions']);
        add_action('activated_plugin', [$this, 'incrementAllVersions']);
        add_action('deactivated_plugin', [$this, 'incrementAllVersions']);
        add_action('customize_save_after', [$this, 'handleCustomizerSave']);
        add_action('upgrader_process_complete', [$this, 'handlePluginUpdate'], 10, 2);
    }

    /**
     * Get version for a specific group
     *
     * @param string $group Cache group name
     * @return int Version number
     * @api
     */
    public function getVersion(string $group = 'core'): int {
        return $this->versions[$group] ?? 1;
    }

    /**
     * Increment version for a specific group
     *
     * Uses GNODE_CACHE_INCR for atomic distributed increment when available.
     *
     * @param string $group Cache group name
     * @param int $amount Amount to increment (default: 1)
     * @return int New version number
     * @api
     */
    public function incrementVersion(string $group = 'core', int $amount = 1): int {
        $oldVersion = $this->versions[$group] ?? 0;

        if (!isset($this->versions[$group])) {
            $this->versions[$group] = 1;
        }

        // Use gNode atomic increment if available
        if ($this->gNodeAvailable && $this->cacheManager !== null) {
            try {
                $key = 'version:' . $group;
                $result = $this->cacheManager->fcall('GNODE_CACHE_INCR', [
                    $key,
                    $amount,
                    $this->nodeMetadata['site_id']
                ]);
                if (is_numeric($result)) {
                    $this->versions[$group] = (int) $result;
                } else {
                    $this->versions[$group] += $amount;
                }
            } catch (\Throwable $e) {
                $this->versions[$group] += $amount;
                error_log('[gCore] VersionManager: GNODE_CACHE_INCR failed - ' . $e->getMessage());
            }
        } else {
            $this->versions[$group] += $amount;
        }

        $this->saveVersions();

        // Record history
        if ($this->config['store_history']) {
            $this->recordHistory($group, $oldVersion, $this->versions[$group], 'increment');
        }

        // Trigger action if in WordPress
        if (function_exists('do_action')) {
            do_action('gCore_cache_version_incremented', $group, $this->versions[$group]);
        }

        if ($this->config['debug']) {
            error_log("Version incremented for group '{$group}': {$this->versions[$group]}");
        }

        return $this->versions[$group];
    }

    /**
     * Decrement version for a specific group
     *
     * Uses GNODE_CACHE_DECR for atomic distributed decrement when available.
     * Version cannot go below 1.
     *
     * @param string $group Cache group name
     * @param int $amount Amount to decrement (default: 1)
     * @return int New version number
     * @api
     */
    public function decrementVersion(string $group = 'core', int $amount = 1): int {
        if (!isset($this->versions[$group])) {
            return 1;
        }

        $oldVersion = $this->versions[$group];

        // Use gNode atomic decrement if available
        if ($this->gNodeAvailable && $this->cacheManager !== null) {
            try {
                $key = 'version:' . $group;
                $result = $this->cacheManager->fcall('GNODE_CACHE_DECR', [
                    $key,
                    $amount,
                    $this->nodeMetadata['site_id']
                ]);
                if (is_numeric($result)) {
                    $this->versions[$group] = max(1, (int) $result);
                } else {
                    $this->versions[$group] = max(1, $this->versions[$group] - $amount);
                }
            } catch (\Throwable $e) {
                $this->versions[$group] = max(1, $this->versions[$group] - $amount);
                error_log('[gCore] VersionManager: GNODE_CACHE_DECR failed - ' . $e->getMessage());
            }
        } else {
            $this->versions[$group] = max(1, $this->versions[$group] - $amount);
        }

        $this->saveVersions();

        // Record history
        if ($this->config['store_history']) {
            $this->recordHistory($group, $oldVersion, $this->versions[$group], 'decrement');
        }

        // Trigger action if in WordPress
        if (function_exists('do_action')) {
            do_action('gCore_cache_version_decremented', $group, $this->versions[$group]);
        }

        if ($this->config['debug']) {
            error_log("Version decremented for group '{$group}': {$this->versions[$group]}");
        }

        return $this->versions[$group];
    }

    /**
     * Reset version for a specific group to initial value
     *
     * @param string $group Cache group name
     * @param int $resetTo Version to reset to (default: 1)
     * @return int New version number
     * @api
     */
    public function resetVersion(string $group = 'core', int $resetTo = 1): int {
        $oldVersion = $this->versions[$group] ?? 0;
        $this->versions[$group] = max(1, $resetTo);
        $this->saveVersions();

        // Record history
        if ($this->config['store_history']) {
            $this->recordHistory($group, $oldVersion, $this->versions[$group], 'reset');
        }

        // Trigger action if in WordPress
        if (function_exists('do_action')) {
            do_action('gCore_cache_version_reset', $group, $this->versions[$group]);
        }

        if ($this->config['debug']) {
            error_log("Version reset for group '{$group}': {$this->versions[$group]}");
        }

        return $this->versions[$group];
    }

    /**
     * Record a version change in history
     *
     * @param string $group Group name
     * @param int $oldVersion Previous version
     * @param int $newVersion New version
     * @param string $action Action type (increment, decrement, reset)
     */
    private function recordHistory(string $group, int $oldVersion, int $newVersion, string $action): void {
        $entry = [
            'group' => $group,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'action' => $action,
            'timestamp' => microtime(true),
            'site_id' => $this->nodeMetadata['site_id'],
            'node_id' => $this->nodeMetadata['node_id']
        ];

        // Add to local history
        if (!isset($this->history[$group])) {
            $this->history[$group] = [];
        }
        array_unshift($this->history[$group], $entry);

        // Trim to max entries
        if (count($this->history[$group]) > self::MAX_HISTORY_ENTRIES) {
            $this->history[$group] = array_slice($this->history[$group], 0, self::MAX_HISTORY_ENTRIES);
        }

        // Persist history
        $this->saveHistory();
    }

    /**
     * Save version history to cache
     */
    private function saveHistory(): void {
        if ($this->cacheManager === null) {
            return;
        }

        try {
            $historyKey = self::HISTORY_KEY_PREFIX . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
            $this->cacheManager->set($historyKey, $this->history, DAY_IN_SECONDS * 30);
        } catch (\Throwable $e) {
            error_log('[gCore] VersionManager: Failed to save history - ' . $e->getMessage());
        }
    }

    /**
     * Get version history for a group
     *
     * @param string|null $group Group name (null for all groups)
     * @param int $limit Maximum entries to return
     * @return array History entries
     * @api
     */
    public function getHistory(?string $group = null, int $limit = 50): array {
        if ($group !== null) {
            $groupHistory = $this->history[$group] ?? [];
            return array_slice($groupHistory, 0, $limit);
        }

        // Merge all group histories and sort by timestamp
        $allHistory = [];
        foreach ($this->history as $groupHistory) {
            $allHistory = array_merge($allHistory, $groupHistory);
        }
        usort($allHistory, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($allHistory, 0, $limit);
    }

    /**
     * Clear version history
     *
     * @param string|null $group Group name (null for all groups)
     * @return bool Success status
     * @api
     */
    public function clearHistory(?string $group = null): bool {
        if ($group !== null) {
            unset($this->history[$group]);
        } else {
            $this->history = [];
        }
        $this->saveHistory();
        return true;
    }

    /**
     * Increment all versions
     * @api
     */
    public function incrementAllVersions(): void {
        foreach ($this->versions as $group => $version) {
            $this->incrementVersion($group);
        }

        // Trigger action if in WordPress
        if (function_exists('do_action')) {
            do_action('gCore_cache_all_versions_incremented', $this->versions);
        }

        if ($this->config['debug']) {
            error_log("All versions incremented");
        }
    }

    /**
     * Register a new cache group
     *
     * @param string $group Group name
     * @param int $initial_version Initial version number
     * @return bool Success
     * @api
     */
    public function registerGroup(string $group, int $initial_version = 1): bool {
        if (isset($this->versions[$group])) {
            return false;
        }

        $this->versions[$group] = $initial_version;
        $this->saveVersions();

        // Trigger action if in WordPress
        if (function_exists('do_action')) {
            do_action('gCore_cache_group_registered', $group, $initial_version);
        }

        if ($this->config['debug']) {
            error_log("Cache group registered: {$group} with version {$initial_version}");
        }

        return true;
    }

    /**
     * Handle customizer save (WordPress-specific)
     */
    public function handleCustomizerSave(): void {
        $this->incrementVersion('manifest');
        $this->incrementVersion('face');
    }

    /**
     * Handle plugin updates (WordPress-specific)
     *
     * @param mixed $upgrader Upgrader instance
     * @param array $options Upgrader options
     */
    public function handlePluginUpdate($upgrader, $options): void {
        if (is_array($options) &&
            isset($options['action']) && $options['action'] === 'update' &&
            isset($options['type']) && $options['type'] === 'theme') {
            $this->incrementAllVersions();
        }
    }

    /**
     * Save versions to storage
     *
     * Saves to all available storage backends for redundancy:
     * 1. CacheManager (primary, distributed)
     * 2. WordPress options (local persistence)
     * 3. File storage (fallback)
     */
    private function saveVersions(): void {
        $saveErrors = [];

        // 1. Save to CacheManager (primary, distributed)
        if ($this->cacheManager !== null) {
            try {
                $cacheKey = self::CACHE_KEY_PREFIX . $this->nodeMetadata['site_id'] . '_' . $this->nodeMetadata['node_id'];
                $this->cacheManager->set($cacheKey, $this->versions, DAY_IN_SECONDS * 365);
            } catch (\Throwable $e) {
                $saveErrors[] = 'CacheManager: ' . $e->getMessage();
            }
        }

        // 2. Save to WordPress options (local persistence)
        if (function_exists('update_option')) {
            try {
                update_option(self::VERSION_OPTION, $this->versions, 'no');
            } catch (\Throwable $e) {
                $saveErrors[] = 'WordPress: ' . $e->getMessage();
            }
        }

        // 3. Save to file storage (fallback)
        if ($this->storageFile) {
            try {
                $dir = dirname($this->storageFile);
                if (!is_dir($dir)) {
                    if (!@mkdir($dir, 0755, true)) {
                        throw new \RuntimeException("Failed to create directory: {$dir}");
                    }
                }
                if (!is_writable($dir)) {
                    throw new \RuntimeException("Directory not writable: {$dir}");
                }
                $json = json_encode($this->versions, JSON_PRETTY_PRINT);
                if ($json === false) {
                    throw new \RuntimeException("Failed to encode versions to JSON");
                }
                $result = file_put_contents($this->storageFile, $json, LOCK_EX);
                if ($result === false) {
                    throw new \RuntimeException("Failed to write to file: {$this->storageFile}");
                }
            } catch (\Throwable $e) {
                $saveErrors[] = 'File: ' . $e->getMessage();
            }
        }

        if (!empty($saveErrors)) {
            foreach ($saveErrors as $error) {
                error_log('[gCore] VersionManager save error - ' . $error);
            }
        }
    }

    /**
     * Get prefix for a group
     *
     * @param string $group Group name
     * @return string Cache prefix
     * @api
     */
    public function getPrefix(string $group = 'core'): string {
        return sprintf(
            'v%d_%s_%s_%s_',
            $this->getVersion($group),
            $group,
            $this->nodeMetadata['site_id'],
            $this->nodeMetadata['node_id']
        );
    }

    /**
     * Generate full cache key with multi-tenant isolation
     *
     * @param string $key Base key
     * @param string $group Cache group
     * @return string Full cache key
     * @api
     */
    public function generateKey(string $key, string $group = 'core'): string {
        return $this->getPrefix($group) . $key;
    }

    /**
     * Get configuration
     *
     * @return array Configuration settings
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update configuration
     *
     * @param array $config New configuration settings
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Check if initialized
     *
     * @return bool Initialization status
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get status information
     *
     * @return array Status information
     */
    public function getStatus(): array {
        $historyCount = 0;
        foreach ($this->history as $groupHistory) {
            $historyCount += count($groupHistory);
        }

        return [
            'initialized' => $this->initialized,
            'versions' => $this->versions,
            'groups' => array_keys($this->versions),
            'gnode_available' => $this->gNodeAvailable,
            'cache_manager_available' => $this->cacheManager !== null,
            'history_enabled' => $this->config['store_history'],
            'history_entries' => $historyCount,
            'site_id' => $this->nodeMetadata['site_id'],
            'node_id' => $this->nodeMetadata['node_id'],
            'storage_mode' => $this->cacheManager !== null ? 'CacheManager' :
                              (function_exists('get_option') ? 'WordPress' : 'File'),
            'framework' => defined('ABSPATH') ? 'WordPress' : 'Generic PHP'
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}
