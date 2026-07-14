<?php
declare(strict_types=1);
/**
 * seed-api-tags.php — one-time bootstrap: mark the build-with API with @api.
 *
 * Reads CONTRACT.md's curated method names (§1 container + §2/§4 base managers;
 * skips §3 stubs = Chapter 2, and §5/§6 = internal plumbing) and inserts an
 * `@api` docblock tag on each matching method in source. gen-public-api.php then
 * emits only interface methods + @api-tagged class/trait methods.
 *
 *   php scripts/seed-api-tags.php          # DRY: report what would change
 *   php scripts/seed-api-tags.php --apply  # write the tags
 *
 * Idempotent: a method already carrying @api is left untouched.
 */

$ROOT  = dirname(__DIR__);
$APPLY = in_array('--apply', $argv, true);

// ---- 1. the curated build-with surface, transcribed from CONTRACT.md --------
// Container = §1; base managers = §2 + §4. The universal ModuleInterface
// lifecycle is documented once (via the interface) and NOT repeated per manager,
// so it is absent here. The "missing" report at the end flags any name that
// isn't a real method in source (a transcription slip), so this stays honest.
$MAP = [
    'gCore' => ['getInstance','initialize','getService','findServiceByCapabilities','registerServiceCapabilities','hasService','isServiceActive','getServiceStatus','getStatus','getServiceRegistry','getExtensionStatus','isExtensionInstalled','getMissingExtensionPackages','shutdown','getStorageAdapter','getStorage'],
    'SecurityManager' => ['defineRole','assignRole','hasPermission','hasCapability','getUserCapabilities','generateCsrfToken','validateCsrfToken','generateJWT','validateJWT','createAPIKey','validateAPIKey','revokeAPIKey','validateAPIRequest','setgNodeClient'],
    'ErrorManager' => ['trackError','trackSystemEvent','handleError','handleException','handleShutdown','notifyAdmin','getRecentErrors','getErrorStats','clearErrorHistory','log','logCriticalError'],
    'CacheManager' => ['set','get','delete','exists','increment','decrement','setNx','getMultiple','setMultiple','deleteMultiple','clear','getKeys','getMetrics','batchSet','batchGet','batchDelete','storeContent','retrieveContent','storeTemplate','storeAssetBundle','broadcastInvalidate','broadcastClearAll','listenForInvalidations','enableNativeMode','disableNativeMode','isNativeMode','registerFormat','validateData','setWithValidation','streamAdd','streamReadGroup','streamAck','streamClaim','streamTrim'],
    'FormatManager' => ['registerFormat','listFormats','getFormat','deleteFormat','detectFormat','detectAndValidate','convertFormat','autoConvertFormat','validateMessage','registerFormats','detectFormats'],
    'APIManager' => ['addMiddleware','registerEndpoint','start','processRequest'],
    'StateManager' => ['setState','getState','removeState','hasState','increment','decrement','compareAndSwap','subscribe','unsubscribe','publish','beginTransaction','commitTransaction','rollbackTransaction','getHistory','registerValidator','addMiddleware','restoreState','persistState','offsetGet','offsetSet','offsetExists','offsetUnset'],
    'WordPressManager' => ['cloneDatabase','swapDatabase','getProductionDbName','getEnvironmentDbName','scrubPII','getScrubPreview','isScrubSafe','getEnvironmentInfo'],
    'CookieManager' => ['setCookie','getCookie','deleteCookie','getCookieExpiry','extendCookieExpiry','refreshCookie','getCookiesExpiringSoon','getExpiredCookies','cleanupExpiredTracking','hasConsent','updateConsent','displayConsentBanner','registerExporter','exportPersonalData','registerEraser','erasePersonalData'],
    'ResourceManager' => ['createAssetBundle','loadAsset','batchLoadAssets','optimizeAsset','storeTemplateFragment','discoverTemplatesByCapability','renderTemplateString','loadResource','preloadResources','warmupCache'],
    'AssetManager' => ['storeAsset','getAsset','deleteAsset','listAssets','assetExists','setManifest','getManifest','deleteManifest','listManifests','getBundle','getBundleStatus','invalidateBundle','syncFaceMapping'],
    'HtaccessManager' => ['setupHtaccess','addHtaccessRule','getHtaccessPath','generateHtaccessRules','ensureIPBlockSection'],
    'IPBlockManager' => ['blockIP','unblockIP','getBlockedIPs','cleanExpiredBlocks'],
    'VersionManager' => ['getVersion','incrementVersion','decrementVersion','resetVersion','getHistory','clearHistory','incrementAllVersions','registerGroup','getPrefix','generateKey'],
    'InstallManager' => ['verifyIntegrity','getWarrantyInfo','getInstalledExtensions','getAvailableExtensions','validateLicense','subscribeToNotifications','installExtension','updateExtension','setupEnvironment','validateEnvironment'],
    'BackupManager' => ['createBackup','restoreBackup','cleanOldBackups'],
];
$curated = [];
foreach ($MAP as $mgr => $names) foreach ($names as $nm) $curated[$mgr][$nm] = true;

// ---- 2. resolve manager => target files ------------------------------------
function files_for(string $ROOT, string $mgr): array {
    if ($mgr === 'gCore') return ["$ROOT/Modules/Core/gCore.php"];
    $dir = "$ROOT/Modules/Managers/Base/$mgr";
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->getExtension() === 'php') $out[] = $f->getPathname();
    return $out;
}

// ---- 3. tag each method's docblock -----------------------------------------
$tagged = 0; $already = 0; $missing = [];
foreach ($curated as $mgr => $set) {
    $names = array_keys($set);
    $files = files_for($ROOT, $mgr);
    if (!$files) { $missing[$mgr] = ['(no Base dir)']; continue; }
    $found = [];
    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $changed = false;
        for ($i = 0; $i < count($lines); $i++) {
            if (!preg_match('/^\s*(?:public\s+|final\s+|abstract\s+|static\s+)*function\s+([A-Za-z_]\w*)\s*\(/', $lines[$i], $m)) continue;
            if (preg_match('/\b(private|protected)\s+function/', $lines[$i])) continue;
            $name = $m[1];
            if (!isset($set[$name])) continue;
            $found[$name] = true;
            // walk up to the docblock close `*/` directly above (allow attribute lines)
            $j = $i - 1;
            while ($j >= 0 && preg_match('/^\s*(#\[|\/\/|$)/', $lines[$j])) $j--;
            if ($j >= 0 && preg_match('/\*\/\s*$/', $lines[$j])) {
                // find block start, check for existing @api
                $s = $j; while ($s >= 0 && strpos($lines[$s], '/**') === false) $s--;
                $blockHasApi = false;
                for ($k = max(0,$s); $k <= $j; $k++) if (strpos($lines[$k], '@api') !== false) { $blockHasApi = true; break; }
                if ($blockHasApi) { $already++; continue; }
                $prefix = preg_match('/^(\s*)\*/', $lines[$j], $pm) ? $pm[1] . '* @api' : ' * @api';
                array_splice($lines, $j, 0, [$prefix]);
                $i++; $tagged++; $changed = true;
            } else {
                $indent = preg_match('/^(\s*)/', $lines[$i], $pm) ? $pm[1] : '    ';
                array_splice($lines, $i, 0, [$indent . '/** @api */']);
                $i++; $tagged++; $changed = true;
            }
        }
        if ($changed && $APPLY) file_put_contents($file, implode("\n", $lines) . "\n");
    }
    $miss = array_diff($names, array_keys($found));
    if ($miss) $missing[$mgr] = array_values($miss);
}

// ---- 4. report -------------------------------------------------------------
fwrite(STDERR, ($APPLY ? "APPLIED" : "DRY-RUN") . ": tagged $tagged method(s), $already already had @api\n");
if ($missing) {
    fwrite(STDERR, "\nCurated in CONTRACT.md but not found as a method in source (trait elsewhere / helper / naming):\n");
    foreach ($missing as $mgr => $ms) fwrite(STDERR, sprintf("  %-18s %s\n", $mgr, implode(', ', $ms)));
}
