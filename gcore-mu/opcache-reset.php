<?php
/**
 * OPcache Reset for gCore files
 * Access via: /wp-content/mu-plugins/gcore-mu/opcache-reset.php?token=<token>
 *
 * Token is stored in ValKey key "gcore:opcache_reset_token".
 * Set it via: redis-cli -p $VALKEY_PORT SET gcore:opcache_reset_token <random-secret>
 */

// Validate token against ValKey
$token = $_GET['token'] ?? '';
if ($token === '' || strlen($token) < 16) {
    http_response_code(403);
    die('Forbidden');
}

$host = getenv('VALKEY_HOST') ?: '127.0.0.1';
$port = (int)(getenv('VALKEY_PORT') ?: 6379);
$auth = getenv('VALKEY_AUTH') ?: '';

// Read auth from credentials file if env var is not set
if ($auth === '' && file_exists('/etc/geodineum/credentials/valkey.password')) {
    $auth = trim(file_get_contents('/etc/geodineum/credentials/valkey.password'));
}

try {
    $redis = new \Redis();
    if (!@$redis->connect($host, $port, 2.0)) {
        http_response_code(503);
        die('Service unavailable');
    }
    if ($auth !== '') {
        $user = getenv('VALKEY_USER') ?: '';
        if ($user !== '') {
            $redis->auth([$user, $auth]);
        } else {
            $redis->auth($auth);
        }
    }

    $storedToken = $redis->get('gcore:opcache_reset_token');
    $redis->close();
} catch (\Exception $e) {
    http_response_code(503);
    die('Service unavailable');
}

if ($storedToken === false || !hash_equals($storedToken, $token)) {
    http_response_code(403);
    die('Forbidden');
}

$basePath = getenv('GCORE_BASE_PATH') ?: (defined('GCORE_BASE_PATH') ? GCORE_BASE_PATH : '/opt/geodineum/gCore');
$files = [
    $basePath . '/Modules/Managers/Base/CacheManager/CacheManager.php',
    $basePath . '/Modules/Managers/Base/CacheManager/Traits/StreamCapabilities.php',
    // TemplateLibrary entries removed (ROADMAP §B.5).
    $basePath . '/Modules/Core/gCore.php',
    $basePath . '/config/service_topology.yaml',
    $basePath . '/gcore-mu/gcore-loader.php',
    $basePath . '/gcore-mu/wp-hooks.php',
];

$results = [];
foreach ($files as $file) {
    if (function_exists('opcache_invalidate')) {
        $result = opcache_invalidate($file, true);
        $results[$file] = $result ? 'invalidated' : 'failed';
    } else {
        $results[$file] = 'opcache_invalidate not available';
    }
}

// Clear any gCore cached config
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
    $results['wp_cache'] = 'flushed';
}

header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'results' => $results]);
