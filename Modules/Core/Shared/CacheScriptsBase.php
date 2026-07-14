<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base class for Cache Scripts functionality
 * 
 * Provides core initialization and shared functionality used across
 * the specialized script collection classes.
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */
abstract class CacheScriptsBase {
    protected static bool $initialized = false;
    protected static array $compiledScripts = [];

    /**
     * Initialize scripts with configuration
     * Must be called before any script access
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        // Ensure CacheConfig is initialized first
        CacheConfig::init();

        // Load and compile all scripts from various providers
        self::loadAllScripts();

        self::$initialized = true;
    }

    /**
     * Load scripts from all specialized script providers
     *
     * Note: CacheScriptsGroupManager, CacheScriptsLockManager, CacheScriptsSiteManager,
     * and CacheScriptsTransactionManager were archived (2026-01-10) as obsolete.
     * These are replaced by gNode Lua functions and PHP traits.
     */
    protected static function loadAllScripts(): void {
        // Core key operations
        self::compileScriptsFromProvider(CacheScriptsCoreOperations::SCRIPTS);

        // Batch operations
        self::compileScriptsFromProvider(CacheScriptsBatchOperations::SCRIPTS);

        // Publish/Subscribe operations
        self::compileScriptsFromProvider(CacheScriptsPubSub::SCRIPTS);

        // Monitoring and maintenance
        self::compileScriptsFromProvider(CacheScriptsMonitoring::SCRIPTS);

        // Stream operations
        self::compileScriptsFromProvider(CacheScriptsStreamOperations::SCRIPTS);

        // Hash operations
        self::compileScriptsFromProvider(CacheScriptsHashOperations::SCRIPTS);

        // Utility operations
        self::compileScriptsFromProvider(CacheScriptsUtils::SCRIPTS);
    }

    /**
     * Compile scripts from a provider array
     *
     * @param array $scripts The scripts array from a provider
     */
    protected static function compileScriptsFromProvider(array $scripts): void {
        foreach ($scripts as $name => $script) {
            self::$compiledScripts[$name] = [
                'script' => CacheConfig::render($script['script']),
                'keys' => $script['keys'] ?? 1,
                'args' => $script['args'] ?? []
            ];
        }
    }

    /**
     * Get compiled script by name
     *
     * @param string $name The script name
     * @return array The compiled script with metadata
     * @throws \RuntimeException if scripts not initialized
     */
    public static function getScript(string $name): array {
        if (!self::$initialized) {
            throw new \RuntimeException('CacheScripts not initialized');
        }

        if (!isset(self::$compiledScripts[$name])) {
            throw new \RuntimeException("Unknown script: {$name}");
        }

        return self::$compiledScripts[$name];
    }
}