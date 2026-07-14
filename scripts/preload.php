<?php
/**
 * gCore OPcache Preload Script
 *
 * Preloads core framework classes into OPcache at PHP-FPM startup.
 * Eliminates file stat + autoloader overhead for every request.
 *
 * Enable in php.ini (FPM only, not CLI):
 *   opcache.preload=/opt/geodineum/gCore/scripts/preload.php
 *   opcache.preload_user=www-data
 *
 * @package gCore
 */

$basePath = dirname(__DIR__);

// Core interfaces (loaded by virtually every request)
$preload = [
    // Interfaces
    'Modules/Core/Interfaces/ModuleInterface.php',
    'Modules/Core/Interfaces/Shared/StorageInterface.php',
    'Modules/Core/Interfaces/Shared/TraitLoadingInterface.php',
    'Modules/Core/Interfaces/AssetManagerInterface.php',
    'Modules/Core/Interfaces/Security/SecurityCapabilityInterface.php',

    // Exceptions
    'Modules/Core/Exceptions/ErrorException.php',
    'Modules/Core/Exceptions/InitializationException.php',
    'Modules/Core/Exceptions/ValidationException.php',
    'Modules/Core/Exceptions/StorageException.php',

    // Core utilities
    'Modules/Core/Utils/SelfContainedErrorHandler.php',
    'Modules/Core/Utils/ConfigLoader.php',
    'Modules/Core/Utils/ExtensionResolver.php',
    'Modules/Core/Utils/TopologyParser.php',

    // Storage layer
    'Modules/Storage/gNodeStorageAdapter.php',
    'Modules/Storage/gNodeDetector.php',
    'Modules/Storage/StorageFactory.php',

    // gCore service container
    'Modules/Core/gCore.php',

    // Compiled config (preload the cached array itself)
    'config/compiled.php',
];

$loaded = 0;
$failed = 0;

foreach ($preload as $file) {
    $path = $basePath . '/' . $file;
    if (file_exists($path)) {
        try {
            opcache_compile_file($path);
            $loaded++;
        } catch (\Throwable $e) {
            $failed++;
        }
    }
}

// Preload Composer autoloader (makes class map available immediately)
$autoloader = $basePath . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    opcache_compile_file($autoloader);

    // Also preload the classmap file
    $classmap = $basePath . '/vendor/composer/autoload_classmap.php';
    if (file_exists($classmap)) {
        opcache_compile_file($classmap);
    }
}
