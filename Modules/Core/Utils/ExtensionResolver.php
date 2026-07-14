<?php
declare(strict_types=1);
/**
 * Extension Resolver
 *
 * Resolves optional gCore manager extensions to their full implementation
 * class or a shipped stub.
 *
 * Extensions are registered at autoload time by the packages that provide
 * them (a Composer package typically calls
 * `ExtensionResolver::register(...)` from an `autoload.files` entry).
 * If a registered extension's class exists on the autoloader, `resolve()`
 * returns it. Otherwise it returns the stub shipped with gCore so callers
 * still get a functioning (no-op) manager.
 *
 * Usage:
 *   // Extension package (its own bootstrap):
 *   ExtensionResolver::register(
 *       'SEOManager',
 *       \Some\Vendor\SEO\SEOManagerFull::class,
 *       gCore\Modules\Managers\Stubs\SEOManagerStub::class,
 *       'some-vendor/some-seo-package'
 *   );
 *
 *   // gCore application:
 *   $class = ExtensionResolver::resolve('SEOManager');
 *
 * @package     gCore
 * @subpackage  Modules\Core\Utils
 * @version     2.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Utils;

class ExtensionResolver
{
    /**
     * Registered extension mapping.
     * manager name => ['full' => class, 'stub' => class, 'package' => composer name]
     */
    private static array $registry = [];

    /** Cache of already-resolved classes. */
    private static array $resolved = [];

    /**
     * Register an extension mapping. Normally called at autoload time
     * from a Composer package that provides the full implementation.
     */
    public static function register(
        string $managerName,
        string $fullClass,
        string $stubClass,
        string $packageName
    ): void {
        self::$registry[$managerName] = [
            'full' => $fullClass,
            'stub' => $stubClass,
            'package' => $packageName,
        ];
        // Invalidate the cache for this manager in case register() is
        // called after an earlier resolve().
        unset(self::$resolved[$managerName]);
    }

    /**
     * Resolve a manager to its concrete implementation class.
     *
     * @return string Fully qualified class name. When the manager is not
     *               registered the original name is returned so the caller
     *               can fall through to its own class-loading logic (core
     *               managers).
     */
    public static function resolve(string $managerName): string
    {
        if (isset(self::$resolved[$managerName])) {
            return self::$resolved[$managerName];
        }

        if (!isset(self::$registry[$managerName])) {
            return $managerName;
        }

        $config = self::$registry[$managerName];

        if (self::isForceDisabled($managerName)) {
            self::$resolved[$managerName] = $config['stub'];
            self::logResolution($managerName, 'disabled', $config['stub']);
            return $config['stub'];
        }

        if (class_exists($config['full'])) {
            self::$resolved[$managerName] = $config['full'];
            self::logResolution($managerName, 'full', $config['full']);
            return $config['full'];
        }

        self::$resolved[$managerName] = $config['stub'];
        self::logResolution($managerName, 'stub', $config['stub']);
        return $config['stub'];
    }

    /**
     * True if the registered full-implementation class is available on
     * the autoloader.
     */
    public static function isExtensionInstalled(string $managerName): bool
    {
        if (!isset(self::$registry[$managerName])) {
            return false;
        }
        return class_exists(self::$registry[$managerName]['full']);
    }

    /**
     * Composer package name registered for a manager, or null if not
     * registered.
     */
    public static function getPackageName(string $managerName): ?string
    {
        return self::$registry[$managerName]['package'] ?? null;
    }

    /**
     * Snapshot of every registered extension with its current mode.
     *
     * @return array<string, array{installed: bool, mode: string, class: string, package: string}>
     */
    public static function getStatus(): array
    {
        $disabled = self::getDisabledList();
        $status = [];
        foreach (self::$registry as $name => $config) {
            $installed = class_exists($config['full']);
            $isDisabled = in_array($name, $disabled, true);

            if ($isDisabled) {
                $mode = 'disabled';
                $class = $config['stub'];
            } elseif ($installed) {
                $mode = 'full';
                $class = $config['full'];
            } else {
                $mode = 'stub';
                $class = $config['stub'];
            }

            $status[$name] = [
                'installed' => $installed,
                'mode' => $mode,
                'class' => $class,
                'package' => $config['package'],
            ];
        }
        return $status;
    }

    /**
     * `composer require` commands for every registered manager whose
     * full implementation isn't loaded.
     */
    public static function getMissingPackages(): array
    {
        $missing = [];
        foreach (self::$registry as $name => $config) {
            if (!class_exists($config['full'])) {
                $missing[$name] = "composer require {$config['package']}";
            }
        }
        return $missing;
    }

    /** Whether a manager is force-disabled via the admin panel. */
    public static function isForceDisabled(string $managerName): bool
    {
        return in_array($managerName, self::getDisabledList(), true);
    }

    /** Force-disabled list from WordPress options, or [] if unavailable. */
    private static function getDisabledList(): array
    {
        if (!function_exists('get_option')) {
            return [];
        }
        $disabled = get_option('gcore_disabled_extensions', []);
        return is_array($disabled) ? $disabled : [];
    }

    /** Invalidate the resolved-class cache (call after toggling admin state). */
    public static function clearCache(): void
    {
        self::$resolved = [];
    }

    /** The raw registry, keyed by manager name. */
    public static function getExtensionDefinitions(): array
    {
        return self::$registry;
    }

    private static function logResolution(string $manager, string $mode, string $class): void
    {
        if (SelfContainedErrorHandler::shouldLog('debug')) {
            error_log("[gCore] ExtensionResolver: {$manager} -> {$mode} ({$class})");
        }
    }
}
