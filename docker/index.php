<?php
/**
 * gCore Docker Demo Page
 * 
 * This page provides basic information about the gCore installation and
 * demonstrates that the container is working properly.
 */

// Define content type as HTML
header('Content-Type: text/html');

// Check if gCore is available
$gcoreAvailable = file_exists(__DIR__ . '/../Modules/Core/gCore.php');
$coreManagersExist = 
    file_exists(__DIR__ . '/../Modules/Managers/Base/ErrorManager/ErrorManager.php') &&
    file_exists(__DIR__ . '/../Modules/Managers/Base/CacheManager/CacheManager.php') &&
    file_exists(__DIR__ . '/../Modules/Managers/Base/SecurityManager/SecurityManager.php') &&
    file_exists(__DIR__ . '/../Modules/Managers/Base/APIManager/APIManager.php');


// Check if Redis/ValKey is available
$redisAvailable = extension_loaded('redis');

// Get PHP version and extensions
$phpVersion = phpversion();
$extensions = get_loaded_extensions();
sort($extensions);

// Get environment variables
$envVars = array_filter($_ENV, function($key) {
    return strpos($key, 'GCORE_') === 0 || 
           in_array($key, ['APP_ENV', 'SITE_ID', 'NODE_ID', 'VALKEY_HOST', 'VALKEY_PORT']);
}, ARRAY_FILTER_USE_KEY);

// Function to check Redis connectivity
function checkRedisConnection() {
    try {
        $redis = new Redis();
        $host = getenv('VALKEY_HOST') ?: 'localhost';
        $port = getenv('VALKEY_PORT') ?: 6379;
        
        if ($redis->connect($host, $port, 2.0)) {
            $info = $redis->info();
            return [
                'status' => 'connected',
                'version' => $info['redis_version'] ?? 'unknown',
                'mode' => $info['redis_mode'] ?? 'standalone'
            ];
        }
        return ['status' => 'failed to connect'];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

$redisConnectionInfo = $redisAvailable ? checkRedisConnection() : ['status' => 'extension not loaded'];

// Get examples directory listing
$examplesAvailable = is_dir(__DIR__ . '/../examples');
$examples = $examplesAvailable ? scandir(__DIR__ . '/../examples') : [];
$examples = array_filter($examples, function($item) {
    return $item !== '.' && $item !== '..' && strpos($item, '.php') !== false;
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>gCore Docker Installation</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
        }
        h1, h2, h3 {
            color: #2c3e50;
        }
        .container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .card {
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            flex: 1;
            min-width: 300px;
        }
        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            margin-left: 10px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
        }
        .warning {
            background-color: #fff3cd;
            color: #856404;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table, th, td {
            border: 1px solid #ddd;
        }
        th, td {
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        code {
            background: #f8f9fa;
            padding: 2px 5px;
            border-radius: 3px;
            font-family: monospace;
        }
        .extension-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }
        .btn {
            display: inline-block;
            background: #3498db;
            color: #fff;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            margin-top: 10px;
        }
        .btn:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <h1>gCore Docker Installation</h1>
    <p>This page confirms that your gCore Docker container is running correctly and provides information about the installation.</p>
    
    <div class="container">
        <div class="card">
            <h2>System Status</h2>
            <p>
                <strong>gCore Available:</strong> 
                <span class="status <?php echo $gcoreAvailable ? 'success' : 'error'; ?>">
                    <?php echo $gcoreAvailable ? 'Yes' : 'No'; ?>
                </span>
            </p>
            <p>
                <strong>Core Managers Exist:</strong> 
                <span class="status <?php echo $coreManagersExist ? 'success' : 'error'; ?>">
                    <?php echo $coreManagersExist ? 'Yes' : 'No'; ?>
                </span>
            </p>
            <p>
                <strong>Redis/ValKey:</strong>
                <span class="status <?php echo $redisConnectionInfo['status'] === 'connected' ? 'success' : 'error'; ?>">
                    <?php echo $redisConnectionInfo['status']; ?>
                </span>
                <?php if ($redisConnectionInfo['status'] === 'connected'): ?>
                    (<?php echo $redisConnectionInfo['version']; ?>, <?php echo $redisConnectionInfo['mode']; ?>)
                <?php endif; ?>
            </p>
        </div>
        
        <div class="card">
            <h2>PHP Information</h2>
            <p><strong>PHP Version:</strong> <?php echo $phpVersion; ?></p>
            <h3>Loaded Extensions</h3>
            <div class="extension-list">
                <?php foreach ($extensions as $ext): ?>
                    <div><?php echo $ext; ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>Environment Variables</h2>
        <table>
            <tr>
                <th>Variable</th>
                <th>Value</th>
            </tr>
            <?php foreach ($envVars as $key => $value): ?>
            <tr>
                <td><?php echo htmlspecialchars($key); ?></td>
                <td><?php echo htmlspecialchars($value); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    
    <div class="card">
        <h2>Example Files</h2>
        <?php if (count($examples) > 0): ?>
            <ul>
                <?php foreach ($examples as $example): ?>
                    <li>
                        <?php echo $example; ?>
                        <a href="/examples/<?php echo $example; ?>" class="btn" target="_blank">View</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No example files found in the examples directory.</p>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h2>Next Steps</h2>
        <p>Now that you have gCore running in Docker, you can:</p>
        <ul>
            <li>Explore the example files to understand how to use gCore components</li>
            <li>View the documentation in the <code>docs/</code> directory</li>
            <li>Modify configuration in the <code>config/</code> directory</li>
            <li>Create your own gCore-powered application</li>
        </ul>
    </div>

    <footer style="margin-top: 40px; text-align: center; color: #7f8c8d;">
        <p>gCore Framework - Powered by Geometric Topology</p>
    </footer>
</body>
</html>