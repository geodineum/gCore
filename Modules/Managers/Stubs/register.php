<?php
/**
 * Stub-extension auto-registration.
 *
 * Loaded via composer.json `autoload.files` so it runs once per request
 * before any `getService()` call. Populates `ExtensionResolver`'s
 * registry with one entry per shipped manager.
 *
 * For each manager we register two class names:
 *   - `full`  — the Pro implementation namespace. Convention:
 *               `gCore\<Short>\<Short>ManagerPro` where Short is the
 *               manager name with the "Manager" suffix removed.
 *               This matches the actual Pro packages at
 *               `geodineum/gcore-<short>` (e.g. gCore-Template ships
 *               `gCore\Template\TemplateManagerPro`).
 *   - `stub`  — what gets used when the Pro class isn't autoloadable.
 *               For managers with an in-tree Base implementation this
 *               is the Base class (fully functional). For
 *               Pro-only managers this is the no-op Stub class
 *               shipped under Stubs/.
 *
 * When a Pro package is composer-required, its namespace becomes
 * autoloadable and ExtensionResolver routes to it; remove the package
 * and the Stub/Base fallback takes over. No caller code changes.
 *
 * Rewritten to fix two bugs:
 *   1. Pro class name convention was wrong (`gCore\Pro\<Name>\<Name>`
 *      vs actual `gCore\<Short>\<Short>ManagerPro`). Resolver never
 *      found any Pro package even when composer-installed.
 *   2. 6 managers (TemplateManager, TopologyManager, SEOManager,
 *      OptimizationManager, ManifestManager, MetricsManager) were
 *      registered in $baseManagers but had no Base/ directory on disk
 *      (extracted to archive/ via commit a8658ec on 2026-01-16).
 *      Their Stubs/<Name>Stub.php DO exist, so they're moved to
 *      $stubOnlyManagers using the existing Stub class.
 *   3. AssetManager was in $stubOnlyManagers despite having a
 *      Base/AssetManager/ (804 LOC). Moved to $baseManagers — the
 *      Stub is now bypassed (and is a candidate for L2 deletion).
 *
 * @package gCore\Modules\Managers\Stubs
 */

declare(strict_types=1);

use gCore\Modules\Core\Utils\ExtensionResolver;

// Composer's PSR-4 hasn't been registered for our own classes yet at
// autoload-files time, but that's fine — we only reference class
// NAMES (strings); class_exists() is called later at resolve() time.

/**
 * Managers with an in-tree Base implementation under
 * Modules/Managers/Base/<Name>/<Name>.php. The default routes to the
 * Base class. A Pro package CAN override (if a matching Pro class
 * `gCore\<Short>\<Short>ManagerPro` becomes autoloadable), but for
 * these foundational managers no Pro package is planned — the Base
 * class IS the canonical implementation.
 */
$baseManagers = [
    'StateManager'        => 'gCore\\Modules\\Managers\\Base\\StateManager\\StateManager',
    'WordPressManager'    => 'gCore\\Modules\\Managers\\Base\\WordPressManager\\WordPressManager',
    'AssetManager'        => 'gCore\\Modules\\Managers\\Base\\AssetManager\\AssetManager',
];

/**
 * Pro-tier managers — Stub class ships in tree (free-tier safe
 * fallback); Pro class lives in a separate `geodineum/gcore-<short>`
 * package and overrides via ExtensionResolver when present.
 *
 * Per-package Pro classes (verified 2026-05-19):
 *   gCore-Analytics    : gCore\Analytics\AnalyticsManagerPro     (1435 LOC)
 *   gCore-Comms        : gCore\Comms\CommsManagerPro             ( 837 LOC)
 *   gCore-Inference    : gCore\Inference\InferenceManagerPro     (1591 LOC)
 *   gCore-Manifest     : gCore\Manifest\ManifestManagerPro       ( 969 LOC)
 *   gCore-Metrics      : gCore\Metrics\MetricsManagerPro         ( 555 LOC)
 *   gCore-Optimization : gCore\Optimization\OptimizationManagerPro ( 666 LOC)
 *   gCore-SEO          : gCore\SEO\SEOManagerPro                 (2067 LOC)
 *   gCore-Template     : gCore\Template\TemplateManagerPro       ( 523 LOC)
 *   gCore-Topology     : gCore\Topology\TopologyManagerPro       (1641 LOC)
 *   gCore-Translate    : gCore\Translate\TranslateManagerPro     (1101 LOC)
 *
 * EcommerceManager has no Pro package yet — Stub is the only impl.
 */
$stubOnlyManagers = [
    'AnalyticsManager'    => 'gCore\\Modules\\Managers\\Stubs\\AnalyticsManagerStub',
    'CommsManager'        => 'gCore\\Modules\\Managers\\Stubs\\CommsManagerStub',
    'EcommerceManager'    => 'gCore\\Modules\\Managers\\Stubs\\EcommerceManagerStub',
    'InferenceManager'    => 'gCore\\Modules\\Managers\\Stubs\\InferenceManagerStub',
    'ManifestManager'     => 'gCore\\Modules\\Managers\\Stubs\\ManifestManagerStub',
    'MetricsManager'      => 'gCore\\Modules\\Managers\\Stubs\\MetricsManagerStub',
    'OptimizationManager' => 'gCore\\Modules\\Managers\\Stubs\\OptimizationManagerStub',
    'SEOManager'          => 'gCore\\Modules\\Managers\\Stubs\\SEOManagerStub',
    'TemplateManager'     => 'gCore\\Modules\\Managers\\Stubs\\TemplateManagerStub',
    'TopologyManager'     => 'gCore\\Modules\\Managers\\Stubs\\TopologyManagerStub',
    'TranslateManager'    => 'gCore\\Modules\\Managers\\Stubs\\TranslateManagerStub',
];

/**
 * Single registration loop — same Pro-class convention applies to
 * both lists. ExtensionResolver tries the Pro class first; if it's
 * not autoloadable, falls back to the stub (which for $baseManagers
 * IS the canonical Base class).
 *
 * Convention:
 *   Pro class:   gCore\<Short>\<Short>ManagerPro
 *   Package id:  geodineum/gcore-<lowercase-short>
 *   where <Short> = manager name with "Manager" suffix removed.
 */
$register = static function (array $managers) {
    foreach ($managers as $name => $fallback) {
        $short = str_replace('Manager', '', $name);
        $proClass    = 'gCore\\' . $short . '\\' . $short . 'ManagerPro';
        $packageName = 'geodineum/gcore-' . strtolower($short);
        ExtensionResolver::register($name, $proClass, $fallback, $packageName);
    }
};

$register($baseManagers);
$register($stubOnlyManagers);
