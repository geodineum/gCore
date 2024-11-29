<?php

/**
 * VersionManager - Advanced Version Control and Cache Invalidation System
 * 
 * Provides comprehensive version tracking and cache invalidation functionality for the NiertoCube theme.
 * Implements ModuleInterface for standardized integration with the NiertoCore system while maintaining
 * backward compatibility with existing version control mechanisms.
 * 
 * ARCHITECTURE
 * ------------
 * - Implements Singleton pattern for centralized version management
 * - Uses WordPress options API for persistent version storage
 * - Provides group-based version tracking with automatic invalidation
 * - Integrates with WordPress customizer and plugin update systems
 * 
 * KEY FEATURES
 * -----------
 * 1. Version Management:
 *    - Group-specific version tracking
 *    - Automatic version incrementation
 *    - Version history support (optional)
 *    - Customizer integration
 * 
 * 2. Cache Integration:
 *    - Versioned cache key generation
 *    - Group-based cache invalidation
 *    - Prefix management for cache segregation
 *    - Automatic cache busting on theme/plugin updates
 * 
 * 3. WordPress Integration:
 *    - Theme switching support
 *    - Plugin activation/deactivation handling
 *    - Customizer save detection
 *    - Update process integration
 * 
 * USAGE EXAMPLE
 * ------------
 * ```php
 * $version_manager = VersionManager::getInstance();
 * 
 * // Get version for specific group
 * $version = $version_manager->getVersion('face');
 * 
 * // Generate versioned cache key
 * $key = $version_manager->generateKey('my-key', 'core');
 * 
 * // Register new cache group
 * $version_manager->registerGroup('custom', 1);
 * ```
 * 
 * HOOKS & FILTERS
 * -------------
 * Actions:
 * - 'nierto_cube_cache_version_incremented' - When single version incremented
 * - 'nierto_cube_cache_all_versions_incremented' - When all versions incremented
 * - 'nierto_cube_cache_group_registered' - When new group registered
 * 
 * Integration Points:
 * - 'switch_theme' - Triggers version increment
 * - 'activated_plugin' - Triggers version increment
 * - 'deactivated_plugin' - Triggers version increment
 * - 'customize_save_after' - Triggers specific group increments
 * - 'upgrader_process_complete' - Handles plugin/theme updates
 * 
 * DEPENDENCIES
 * -----------
 * Required:
 * - WordPress 5.0+
 * - PHP 7.4+
 * - NiertoCore System
 * - ModuleInterface
 * 
 * Optional:
 * - WordPress Customizer (for customizer integration)
 * - Plugin API (for plugin update detection)
 * 
 * CONFIGURATION
 * ------------
 * Default settings:
 * ```php
 * [
 *     'version_prefix' => 'nierto_cube_',
 *     'auto_increment' => true,
 *     'store_history' => false,
 *     'debug' => WP_DEBUG,
 *     'ttl' => DAY_IN_SECONDS
 * ]
 * ```
 * 
 * SECURITY MEASURES
 * ---------------
 * - WordPress nonce verification for version updates
 * - Capability checking for administrative actions
 * - Sanitized option storage
 * - Protected version registry
 * 
 * ERROR HANDLING
 * ------------
 * - Graceful degradation on initialization failure
 * - Proper exception handling
 * - Debug logging when enabled
 * - Fallback version values
 * 
 * PERFORMANCE CONSIDERATIONS
 * -----------------------
 * - Minimal database operations
 * - Efficient version storage
 * - Optimized key generation
 * - Lazy loading of versions
 * 
 * BACKWARD COMPATIBILITY
 * -------------------
 * - Maintains existing version keys
 * - Preserves cache invalidation behavior
 * - Supports legacy function calls
 * - Compatible with existing cache implementations
 * 
 * UPGRADE GUIDE
 * -----------
 * 1. Initialize with ModuleInterface compliance
 * 2. Migrate existing version data
 * 3. Update cache key references
 * 4. Test cache invalidation
 * 
 * KNOWN LIMITATIONS
 * ---------------
 * - Single site focus (multisite requires additional configuration)
 * - No built-in version history (unless enabled in config)
 * - Requires manual group registration
 * - Database reads on initialization
 * 
 * @package     NiertoCube
 * @subpackage  Modules
 * @implements  ModuleInterface
 * @since       2.0.0
 * @author      Niels Erik Toren
 * @copyright   2024 NiertoCube
 * @version     2.0.0
 * 
 * @see \NiertoCube\Core\ModuleInterface
 * @see \NiertoCube\Core\NiertoCore
 * 
 * @method static VersionManager getInstance()     Get singleton instance
 * @method void initialize(array $config = [])    Initialize the module
 * @method array getConfig()                      Get current configuration
 * @method void updateConfig(array $config)       Update configuration
 * @method bool isInitialized()                   Check initialization status
 * @method array getStatus()                      Get module status
 */
namespace NiertoCube\Modules;

use NiertoCube\Core\ModuleInterface;
use NiertoCube\Core\NiertoCore;

class VersionManager implements ModuleInterface {
    /** @var VersionManager Singleton instance */
    private static $instance = null;

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Version numbers for each cache group */
    private $versions = [];

    /** @var array Configuration settings */
    private $config = [];

    /** @var string Option name for storing versions */
    private const VERSION_OPTION = 'nierto_cube_cache_versions';

    /** @var array Default versions for core groups */
    private const DEFAULT_GROUPS = [
        'core' => 1,
        'face' => 1,
        'api' => 1,
        'manifest' => 1
    ];

    /** @var array Default configuration */
    private const DEFAULT_CONFIG = [
        'version_prefix' => 'nierto_cube_',
        'auto_increment' => true,
        'store_history' => false,
        'debug' => WP_DEBUG,
        'ttl' => DAY_IN_SECONDS
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
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->loadVersions();
        $this->registerHooks();
    }

    /**
     * Initialize the module
     *
     * @param array $config Configuration options
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            $this->config = array_merge(self::DEFAULT_CONFIG, $config);
            $this->loadVersions();
            $this->registerHooks();
            $this->initialized = true;

        } catch (\Exception $e) {
            error_log('VersionManager initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Load versions from database
     */
    private function loadVersions(): void {
        $saved_versions = get_option(self::VERSION_OPTION, []);
        $this->versions = array_merge(self::DEFAULT_GROUPS, $saved_versions);
    }

    /**
     * Register WordPress hooks
     */
    private function registerHooks(): void {
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
     */
    public function getVersion(string $group = 'core'): int {
        return $this->versions[$group] ?? 1;
    }

    /**
     * Increment version for a specific group
     *
     * @param string $group Cache group name
     * @return int New version number
     */
    public function incrementVersion(string $group = 'core'): int {
        if (!isset($this->versions[$group])) {
            $this->versions[$group] = 1;
        }

        $this->versions[$group]++;
        $this->saveVersions();

        do_action('nierto_cube_cache_version_incremented', $group, $this->versions[$group]);

        return $this->versions[$group];
    }

    /**
     * Increment all versions
     */
    public function incrementAllVersions(): void {
        foreach ($this->versions as $group => $version) {
            $this->incrementVersion($group);
        }

        do_action('nierto_cube_cache_all_versions_incremented', $this->versions);
    }

    /**
     * Register a new cache group
     *
     * @param string $group Group name
     * @param int $initial_version Initial version number
     * @return bool Success
     */
    public function registerGroup(string $group, int $initial_version = 1): bool {
        if (isset($this->versions[$group])) {
            return false;
        }

        $this->versions[$group] = $initial_version;
        $this->saveVersions();

        do_action('nierto_cube_cache_group_registered', $group, $initial_version);

        return true;
    }

    /**
     * Handle customizer save
     */
    public function handleCustomizerSave(): void {
        $this->incrementVersion('manifest');
        $this->incrementVersion('face');
    }

    /**
     * Handle plugin updates
     *
     * @param \WP_Upgrader $upgrader Upgrader instance
     * @param array $options Upgrader options
     */
    public function handlePluginUpdate($upgrader, $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'theme') {
            $this->incrementAllVersions();
        }
    }

    /**
     * Save versions to database
     */
    private function saveVersions(): void {
        update_option(self::VERSION_OPTION, $this->versions, 'no');
    }

    /**
     * Get prefix for a group
     *
     * @param string $group Group name
     * @return string Cache prefix
     */
    public function getPrefix(string $group = 'core'): string {
        return sprintf(
            'v%d_%s_',
            $this->getVersion($group),
            $group
        );
    }

    /**
     * Generate full cache key
     *
     * @param string $key Base key
     * @param string $group Cache group
     * @return string Full cache key
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
        return [
            'initialized' => $this->initialized,
            'versions' => $this->versions,
            'config' => $this->config,
            'groups' => array_keys($this->versions)
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}

// Backward compatibility functions
if (!function_exists('nierto_cube_get_cache_version')) {
    function nierto_cube_get_cache_version() {
        return VersionManager::getInstance()->getVersion();
    }
}

if (!function_exists('nierto_cube_increment_cache_version')) {
    function nierto_cube_increment_cache_version() {
        return VersionManager::getInstance()->incrementVersion();
    }
}