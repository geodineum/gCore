<?php
/**
 * gCore Bootstrap File
 *
 * This file ensures that all required components are loaded and provides PSR-4
 * autoloading for the gCore framework.
 *
 * Supports two deployment modes:
 *   1. Production: Framework at /opt/geodineum/gCore/ (preferred)
 *   2. Development: Framework at __DIR__ (fallback)
 */

// Define constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__));
}

if (!defined('GCORE_VERSION')) {
    define('GCORE_VERSION', '0.1.0');
}

// ==============================================================================
// Load Geodineum Ecosystem Configuration (canonical loader)
// ==============================================================================
// Single-route load via the canonical loader:
//   Tier 1 (disk):   /etc/geodineum/bootstrap.env (3 strict keys, root:root 0644)
//   Tier 2 (ValKey): geodineum:bootstrap:* (paths + GNODE_* + CONSTELLATION_*)
//   Tier 3 (file):   /etc/geodineum/components/gCore/gcore.env (gCore-specific)
//
// Mirrors bash lib/bootstrap-loader.sh and Rust daemon/src/ecosystem_config.rs.
// See Geodineum-pro/deep/remediation/COMMIT_0.1_DESIGN.md.
// ==============================================================================

require_once __DIR__ . '/Bootstrap/EcosystemConfigLoader.php';
try {
    \gCore\Bootstrap\load_ecosystem_config();
} catch (\RuntimeException $e) {
    // Soft-fail: gCore may be loaded in non-production contexts (CLI tooling,
    // CI, first-install before installer runs). Warn once to error_log; the
    // subsequent putenv-fallbacks below and CredentialResolver lookups will
    // surface any hard failures at the call sites that actually need ValKey.
    error_log('gCore Bootstrap: ecosystem_config unavailable (' . $e->getMessage() . ')');
}

// gcore.env is a component-specific overlay (gnode:www-data 640). Kept as a
// separate file for now; migration to ValKey-resident config_schema is
// scheduled for Commit 0.5 (see REMEDIATION_PLAN_DEEP.md).
$gcoreEnvFile = '/etc/geodineum/components/gCore/gcore.env';
if (is_file($gcoreEnvFile) && is_readable($gcoreEnvFile)) {
    $content = @file_get_contents($gcoreEnvFile);
    if ($content !== false) {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, 'export ') === 0) $line = substr($line, 7);
            if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/i', $line, $matches)) {
                $name = $matches[1];
                $value = $matches[2];
                if ((strpos($value, '"') === 0 && substr($value, -1) === '"') ||
                    (strpos($value, "'") === 0 && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
                $value = preg_replace_callback('/\$\{([A-Z_][A-Z0-9_]*)\}/i', function($m) {
                    return getenv($m[1]) ?: '';
                }, $value);
                if (getenv($name) === false) {
                    putenv("$name=$value");
                }
            }
        }
    }
}

// GCORE_BASE_PATH: Framework root directory
// Priority: env var > /opt/geodineum/gCore > __DIR__
if (!defined('GCORE_BASE_PATH')) {
    $envPath = getenv('GCORE_BASE_PATH');
    $optPath = '/opt/geodineum/gCore';

    if ($envPath && is_dir($envPath)) {
        define('GCORE_BASE_PATH', $envPath);
    } elseif (is_dir($optPath) && file_exists($optPath . '/Modules/Core/gCore.php')) {
        define('GCORE_BASE_PATH', $optPath);
    } else {
        define('GCORE_BASE_PATH', __DIR__);
    }
}

// GCORE_CONFIG_PATH: Configuration directory
// Priority: env var (e.g. /etc/geodineum/components/gCore) > GCORE_BASE_PATH/config
if (!defined('GCORE_CONFIG_PATH')) {
    $envConfigPath = getenv('GCORE_CONFIG_PATH');
    if ($envConfigPath && is_dir($envConfigPath)) {
        define('GCORE_CONFIG_PATH', $envConfigPath);
    } else {
        define('GCORE_CONFIG_PATH', GCORE_BASE_PATH . '/config');
    }
}

// GCORE_LIB_PATH: FFI libraries directory
if (!defined('GCORE_LIB_PATH')) {
    define('GCORE_LIB_PATH', GCORE_BASE_PATH . '/lib');
}

// GEODINEUM_LOG_DIR: Centralized geodineum logging directory
// Priority: env var > /var/log/geodineum (if exists + writable) > not defined
// When defined, SelfContainedErrorHandler uses /var/log/geodineum/gcore/sites/{site_id}/
if (!defined('GEODINEUM_LOG_DIR')) {
    $envGeodineumLogDir = getenv('GEODINEUM_LOG_DIR');
    $defaultGeodineumLogDir = '/var/log/geodineum';

    if ($envGeodineumLogDir && is_dir($envGeodineumLogDir) && is_writable($envGeodineumLogDir . '/gcore')) {
        define('GEODINEUM_LOG_DIR', $envGeodineumLogDir);
    } elseif (is_dir($defaultGeodineumLogDir) && is_writable($defaultGeodineumLogDir . '/gcore')) {
        define('GEODINEUM_LOG_DIR', $defaultGeodineumLogDir);
    }
    // If neither condition met, GEODINEUM_LOG_DIR remains undefined
    // and SelfContainedErrorHandler falls back to WP_CONTENT_DIR/logs
}

// GCORE_LOG_PATH: Centralized log directory
// Priority: env var > /var/log/gcore > WP_CONTENT_DIR/gcore-logs > GCORE_BASE_PATH/logs
if (!defined('GCORE_LOG_PATH')) {
    $envLogPath = getenv('GCORE_LOG_PATH');
    $varLogPath = '/var/log/gcore';

    if ($envLogPath && (is_dir($envLogPath) || is_writable(dirname($envLogPath)))) {
        define('GCORE_LOG_PATH', $envLogPath);
    } elseif (is_dir($varLogPath) && is_writable($varLogPath)) {
        define('GCORE_LOG_PATH', $varLogPath);
    } elseif (defined('WP_CONTENT_DIR') && is_writable(WP_CONTENT_DIR)) {
        define('GCORE_LOG_PATH', WP_CONTENT_DIR . '/gcore-logs');
    } else {
        define('GCORE_LOG_PATH', GCORE_BASE_PATH . '/logs');
    }
}

// GCORE_CACHE_PATH: Centralized cache directory
// Priority: env var > /var/cache/gcore > WP_CONTENT_DIR/gcore-cache > GCORE_BASE_PATH/cache
if (!defined('GCORE_CACHE_PATH')) {
    $envCachePath = getenv('GCORE_CACHE_PATH');
    $varCachePath = '/var/cache/gcore';

    if ($envCachePath && (is_dir($envCachePath) || is_writable(dirname($envCachePath)))) {
        define('GCORE_CACHE_PATH', $envCachePath);
    } elseif (is_dir($varCachePath) && is_writable($varCachePath)) {
        define('GCORE_CACHE_PATH', $varCachePath);
    } elseif (defined('WP_CONTENT_DIR') && is_writable(WP_CONTENT_DIR)) {
        define('GCORE_CACHE_PATH', WP_CONTENT_DIR . '/gcore-cache');
    } else {
        define('GCORE_CACHE_PATH', GCORE_BASE_PATH . '/cache');
    }
}

// Error reporting: log errors but don't display in production (breaks output)
// Only enable display_errors explicitly via GCORE_DISPLAY_ERRORS=1 env var
error_reporting(E_ALL);
if (getenv('GCORE_DISPLAY_ERRORS') === '1') {
    ini_set('display_errors', 1);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Create a log function for bootstrap process
function gcore_bootstrap_log($message) {
    $logDir = defined('GCORE_LOG_PATH') ? GCORE_LOG_PATH : __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/bootstrap.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

gcore_bootstrap_log("Bootstrap process started");

// Check if Composer autoloader exists and load it
$composerAutoloadPaths = [
    GCORE_BASE_PATH . '/vendor/autoload.php',
    dirname(GCORE_BASE_PATH) . '/vendor/autoload.php',
    dirname(dirname(GCORE_BASE_PATH)) . '/vendor/autoload.php',
];

$composerAutoloaderLoaded = false;
foreach ($composerAutoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        gcore_bootstrap_log("Loading Composer autoloader from: {$autoloadPath}");
        require_once $autoloadPath;
        $composerAutoloaderLoaded = true;
        break;
    }
}

if (!$composerAutoloaderLoaded) {
    gcore_bootstrap_log("Composer autoloader not found, setting up PSR-4 autoloader");

    // Define a simple PSR-4 autoloader for gCore
    spl_autoload_register(function ($class) {
        // Only handle classes in gCore namespace
        if (strpos($class, 'gCore\\') !== 0) {
            return;
        }

        $basePath = defined('GCORE_BASE_PATH') ? GCORE_BASE_PATH : __DIR__;

        // Convert namespace to path
        $relativeClass = substr($class, 6); // Remove 'gCore\\'
        $file = $basePath . '/' . str_replace('\\', '/', $relativeClass) . '.php';

        // If file exists, include it
        if (file_exists($file)) {
            require_once $file;
            gcore_bootstrap_log("Autoloaded: {$class} from {$file}");
            return;
        }

        // Special case for Modules directory
        if (strpos($relativeClass, 'Modules\\') === 0) {
            $modulesFile = $basePath . '/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($modulesFile)) {
                require_once $modulesFile;
                gcore_bootstrap_log("Autoloaded from Modules: {$class} from {$modulesFile}");
                return;
            }
        }

        gcore_bootstrap_log("Failed to autoload: {$class}, file not found at {$file}");
    });
}

// List of core files that must be loaded explicitly due to dependencies
$coreFiles = [
    // Key interfaces
    '/Modules/Core/Interfaces/ModuleInterface.php',
    '/Modules/Core/Interfaces/Shared/StorageInterface.php',
    '/Modules/Core/Interfaces/Shared/TraitLoadingInterface.php',
    
    // Essential exceptions
    '/Modules/Core/Exceptions/ErrorException.php',
    '/Modules/Core/Exceptions/InitializationException.php',
    '/Modules/Core/Exceptions/ValidationException.php',
    '/Modules/Core/Exceptions/StorageException.php',
    '/Modules/Core/Exceptions/CircularDependencyException.php',
    '/Modules/Core/Exceptions/StateTransitionException.php',
    
    // Core utilities in dependency order
    '/Modules/Core/Utils/SelfContainedErrorHandler.php',
    '/Modules/Core/Utils/SchemaRegistry.php',
    '/Modules/Core/Utils/ConfigLoader.php',
    // FFIHelper.php removed with the orphan Rust FFI (Commit 1.1.b / GC-D1.01).
    '/Modules/Core/Utils/PHPCompatibilityHelper.php',
    
    // ValKey infrastructure
    '/Modules/Core/Adapters/Shared/ValKeyStorage.php',

    // Trait loading infrastructure
    // REMOVED 2025-10-27: GeometricTopology.php and GeometricTopologyAdapter.php (legacy phantom topology)
    // REMOVED: TraitLoadingAdapter{,Legacy,Factory}.php — files never shipped; the
    // requires only produced a bootstrap WARNING on every request.
    '/Modules/Core/Shared/DependencyBundle.php'
];

// Load core files
foreach ($coreFiles as $path) {
    $file = GCORE_BASE_PATH . $path;
    if (file_exists($file)) {
        gcore_bootstrap_log("Loading core file: {$path}");
        require_once $file;
    } else {
        gcore_bootstrap_log("WARNING: Core file not found: {$path}");
    }
}

// Load gNode-Client's PSR-4 autoloader.
//
// gNode-Client is the PHP library (namespace gCore\gNode\, composer
// package gcore/gnode-client) that talks to the gnode-daemon over
// ValKey streams. It's deployed at /opt/geodineum/gNode-Client/
// alongside /opt/geodineum/gCore/ but is NOT in gCore's composer
// `require` (only `suggest`), so gCore's own composer autoloader
// doesn't know about its classes.
//
// Without this probe, class_exists('gCore\gNode\Client') returns
// false → gCore::initializegNodeClient() throws → catch block logs
// "gNode-Client early init failed, will use YAML topology fallback"
// → managers fall back to "legacy mode" / "WITHOUT gNode" → topology
// writes silently no-op → gTemplate registration never persists
// gtemplate_registration_hash. Apache + CLI both affected.
//
// Probe order:
//   1. sibling to GCORE_BASE_PATH (covers /opt/geodineum/gNode-Client/
//      when gCore is at /opt/geodineum/gCore/, plus dev tree at
//      ~/gh/gNode-Client/ when gCore is at ~/gh/gCore/)
//   2. absolute /opt/geodineum/gNode-Client/ — explicit production fallback
//   3. inside gCore's own vendor/ — in case anyone did
//      `composer require gcore/gnode-client` into gCore's own deps
$gNodeClientAutoloadPaths = [
    dirname(GCORE_BASE_PATH) . '/gNode-Client/vendor/autoload.php',
    '/opt/geodineum/gNode-Client/vendor/autoload.php',
    GCORE_BASE_PATH . '/vendor/gcore/gnode-client/vendor/autoload.php',
];
$gNodeClientLoaded = false;
foreach ($gNodeClientAutoloadPaths as $gncAutoload) {
    if (file_exists($gncAutoload)) {
        gcore_bootstrap_log("Loading gNode-Client autoloader: {$gncAutoload}");
        require_once $gncAutoload;
        $gNodeClientLoaded = true;
        break;
    }
}
if (!$gNodeClientLoaded) {
    gcore_bootstrap_log("gNode-Client autoloader NOT found at any standard path — managers will degrade to legacy mode");
}

// Legacy in-tree autoloader probe — an earlier design that was never
// completed. Kept for backwards-compat with any local builds that
// still ship this file; safely no-op if absent.
$gNodeAutoloaderPath = GCORE_BASE_PATH . '/Modules/Core/Client/gNodeAutoloader.php';
if (file_exists($gNodeAutoloaderPath)) {
    gcore_bootstrap_log("Loading legacy in-tree gNode autoloader");
    require_once $gNodeAutoloaderPath;
    if (class_exists('\\gCore\\Modules\\Core\\Client\\gNodeAutoloader')) {
        gcore_bootstrap_log("Registering external gNode client autoloader");
        \gCore\Modules\Core\Client\gNodeAutoloader::register();
    }
}

/**
 * Function to check if all required PHP extensions are available
 * @return array Missing extensions
 */
function gcore_check_required_extensions() {
    $required = ['json', 'mbstring'];
    $recommended = ['redis', 'ffi', 'igbinary', 'zlib'];
    
    $missing = [];
    $warnings = [];
    
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    
    foreach ($recommended as $ext) {
        if (!extension_loaded($ext)) {
            $warnings[] = $ext;
        }
    }
    
    return [
        'missing' => $missing,
        'warnings' => $warnings
    ];
}

/**
 * Function to check if the minimal requirements are met
 * @return bool True if minimal requirements are met
 */
function gcore_meets_requirements() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.2.0', '<')) {
        return false;
    }
    
    // Check for required extensions
    $extensions = gcore_check_required_extensions();
    if (!empty($extensions['missing'])) {
        return false;
    }
    
    return true;
}

// This will be used to store validation errors
global $gcore_bootstrap_errors;
$gcore_bootstrap_errors = [];

// Validate environment
if (!gcore_meets_requirements()) {
    $gcore_bootstrap_errors[] = sprintf(
        'gCore requires PHP 7.2.0 or higher with JSON and mbstring extensions. Your PHP version: %s',
        PHP_VERSION
    );
    
    $extensions = gcore_check_required_extensions();
    if (!empty($extensions['missing'])) {
        $gcore_bootstrap_errors[] = sprintf(
            'Required PHP extensions missing: %s',
            implode(', ', $extensions['missing'])
        );
    }
    
    if (!empty($extensions['warnings'])) {
        $gcore_bootstrap_errors[] = sprintf(
            'Recommended PHP extensions missing: %s. Some features may not work.',
            implode(', ', $extensions['warnings'])
        );
    }
    
    // Log bootstrap errors
    foreach ($gcore_bootstrap_errors as $error) {
        error_log('gCore Bootstrap Error: ' . $error);
    }
}

// Define helper functions for manager access

if (!function_exists('gcore_get_error_manager')) {
    /**
     * Get the ErrorManager instance
     * @return object ErrorManager instance or null
     */
    function gcore_get_error_manager() {
        try {
            $gCore = \gCore\Modules\Core\gCore::getInstance();
            if ($gCore->hasService('ErrorManager')) {
                return $gCore->getService('ErrorManager');
            }
        } catch (\Throwable $e) {
            error_log('Could not get ErrorManager: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('gcore_get_cache_manager')) {
    /**
     * Get the CacheManager instance
     * @return object CacheManager instance or null
     */
    function gcore_get_cache_manager() {
        try {
            $gCore = \gCore\Modules\Core\gCore::getInstance();
            if ($gCore->hasService('CacheManager')) {
                return $gCore->getService('CacheManager');
            }
        } catch (\Throwable $e) {
            error_log('Could not get CacheManager: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('gcore_get_security_manager')) {
    /**
     * Get the SecurityManager instance
     * @return object SecurityManager instance or null
     */
    function gcore_get_security_manager() {
        try {
            $gCore = \gCore\Modules\Core\gCore::getInstance();
            if ($gCore->hasService('SecurityManager')) {
                return $gCore->getService('SecurityManager');
            }
        } catch (\Throwable $e) {
            error_log('Could not get SecurityManager: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('gcore_get_api_manager')) {
    /**
     * Get the APIManager instance
     * @return object APIManager instance or null
     */
    function gcore_get_api_manager() {
        try {
            $gCore = \gCore\Modules\Core\gCore::getInstance();
            if ($gCore->hasService('APIManager')) {
                return $gCore->getService('APIManager');
            }
        } catch (\Throwable $e) {
            error_log('Could not get APIManager: ' . $e->getMessage());
        }
        return null;
    }
}

// Load compatibility bridge for WordPress if in WordPress environment
if (defined('ABSPATH') && function_exists('add_action')) {
    $wpBridgePath = GCORE_BASE_PATH . '/Modules/Core/WordPressCompatibilityBridge.php';
    if (file_exists($wpBridgePath)) {
        require_once $wpBridgePath;
    }
}

// Log bootstrap completion with path info
gcore_bootstrap_log("Bootstrap complete. GCORE_BASE_PATH=" . GCORE_BASE_PATH . ", GCORE_LOG_PATH=" . GCORE_LOG_PATH);