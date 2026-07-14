<?php
declare(strict_types=1);
/**
 * gNodeDetector - Centralized gNode Daemon Detection
 *
 * Provides a single point for detecting gNode availability throughout gCore.
 * This enables the freemium model where:
 * - Default tier: Works without gNode using local storage adapters
 * - Extension tier: Auto-enhances when gNode is detected
 *
 * Detection Methods:
 * 1. Check for registered gnode_client service in gCore
 * 2. Check for gNode-Client class availability
 * 3. Check for ValKey/Redis connectivity
 * 4. Check for gNode daemon process (optional)
 *
 * @package     gCore
 * @subpackage  Storage
 * @version     1.0.0
 */

namespace gCore\Modules\Storage;

if (!defined('ABSPATH')) {
    if (!defined('GCORE_STANDALONE')) {
        define('GCORE_STANDALONE', true);
    }
}

/**
 * gNodeDetector
 *
 * Centralized service for detecting gNode availability.
 * Caches detection results for performance.
 */
class gNodeDetector
{
    /**
     * Cached detection result
     * @var bool|null
     */
    private static $available = null;

    /**
     * Cached gNode-Client instance
     * @var object|null
     */
    private static $client = null;

    /**
     * Detection details for debugging
     * @var array
     */
    private static $detectionDetails = [];

    /**
     * Time of last detection (Unix timestamp)
     * @var int
     */
    private static $lastDetection = 0;

    /**
     * Cache TTL in seconds (re-detect after this time)
     * @var int
     */
    private static $cacheTTL = 60;

    /**
     * Check if gNode is available
     *
     * This is the main entry point for gNode detection.
     * Results are cached for performance.
     *
     * @param bool $forceRecheck Force a fresh detection
     * @return bool True if gNode is available
     */
    public static function isAvailable(bool $forceRecheck = false): bool
    {
        // Check cache validity
        $now = time();
        $cacheValid = (self::$available !== null) &&
                      (($now - self::$lastDetection) < self::$cacheTTL);

        if (!$forceRecheck && $cacheValid) {
            return self::$available;
        }

        // Perform detection
        self::$available = self::detect();
        self::$lastDetection = $now;

        return self::$available;
    }

    /**
     * Perform gNode detection
     *
     * @return bool True if gNode is available
     */
    private static function detect(): bool
    {
        self::$detectionDetails = [
            'checked_at' => date('Y-m-d H:i:s'),
            'methods' => []
        ];

        // Method 1: Check gCore service registry
        if (self::checkGCoreService()) {
            self::$detectionDetails['methods']['gcore_service'] = true;
            return true;
        }
        self::$detectionDetails['methods']['gcore_service'] = false;

        // Method 2: Check for gNode-Client class
        if (self::checkgNodeClientClass()) {
            self::$detectionDetails['methods']['gnode_client_class'] = true;
            // Class exists but no active instance
        } else {
            self::$detectionDetails['methods']['gnode_client_class'] = false;
        }

        // Method 3: Check ValKey/Redis connectivity
        if (self::checkValKeyConnection()) {
            self::$detectionDetails['methods']['valkey_connection'] = true;
            // ValKey available but gNode not confirmed
        } else {
            self::$detectionDetails['methods']['valkey_connection'] = false;
        }

        // gNode requires both: class available AND registered service
        return false;
    }

    /**
     * Check if gNode is registered as a service in gCore
     *
     * @return bool True if gnode_client service exists
     */
    private static function checkGCoreService(): bool
    {
        try {
            // Check if gCore class exists
            if (!class_exists('\gCore\Modules\Core\gCore')) {
                return false;
            }

            // Get gCore instance
            $gCore = \gCore\Modules\Core\gCore::getInstance();

            // Check if gCore is initialized
            if (!$gCore->isInitialized()) {
                return false;
            }

            // Check for gnode_client service
            if ($gCore->hasService('gnode_client')) {
                // Cache the client instance
                self::$client = $gCore->getService('gnode_client');
                return true;
            }

            return false;

        } catch (\Throwable $e) {
            self::$detectionDetails['gcore_error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Check if gNode-Client class is available
     *
     * @return bool True if gNode-Client class exists
     */
    private static function checkgNodeClientClass(): bool
    {
        return class_exists('\gCore\gNode\Client');
    }

    /**
     * Check if ValKey/Redis connection is possible
     * This is a lightweight check - doesn't fully test gNode
     *
     * @return bool True if ValKey/Redis appears available
     */
    private static function checkValKeyConnection(): bool
    {
        try {
            // Check for phpredis extension
            if (!extension_loaded('redis')) {
                self::$detectionDetails['valkey_note'] = 'Redis extension not loaded';
                return false;
            }

            // Try to get config from environment — no hardcoded fallback
            $host = getenv('VALKEY_HOST') ?: getenv('REDIS_HOST');
            $port = getenv('VALKEY_PORT') ?: getenv('REDIS_PORT');

            if (!$host || !$port) {
                self::$detectionDetails['valkey_note'] = 'VALKEY_HOST/VALKEY_PORT not configured';
                return false;
            }

            // Quick connection test (with short timeout)
            $redis = new \Redis();
            $connected = @$redis->connect($host, (int)$port, 0.5); // 500ms timeout

            if ($connected) {
                $redis->close();
                return true;
            }

            return false;

        } catch (\Throwable $e) {
            self::$detectionDetails['valkey_error'] = $e->getMessage();
            return false;
        }
    }

    /**
     * Get the cached gNode-Client instance if available
     *
     * @return object|null The gNode-Client or null
     */
    public static function getClient(): ?object
    {
        // Ensure detection has run
        if (self::$available === null) {
            self::isAvailable();
        }

        return self::$client;
    }

    /**
     * Set the gNode-Client instance (for injection)
     * Useful when gCore injects the client
     *
     * @param object $client The gNode-Client instance
     * @return void
     */
    public static function setClient(object $client): void
    {
        self::$client = $client;
        self::$available = true;
        self::$lastDetection = time();
        self::$detectionDetails['injected'] = true;
    }

    /**
     * Get detection details for debugging
     *
     * @return array Detection details
     */
    public static function getDetectionDetails(): array
    {
        // Ensure detection has run
        if (self::$available === null) {
            self::isAvailable();
        }

        return array_merge(self::$detectionDetails, [
            'available' => self::$available,
            'has_client' => self::$client !== null,
            'cache_age' => time() - self::$lastDetection
        ]);
    }

    /**
     * Get a human-readable status message
     *
     * @return string Status message
     */
    public static function getStatusMessage(): string
    {
        $available = self::isAvailable();

        if ($available) {
            return 'gNode is available - extension features enabled';
        }

        // Build informative message about why gNode isn't available
        $details = self::getDetectionDetails();
        $reasons = [];

        if (!($details['methods']['gnode_client_class'] ?? false)) {
            $reasons[] = 'gNode-Client library not installed';
        }

        if (!($details['methods']['valkey_connection'] ?? false)) {
            $reasons[] = 'ValKey/Redis not accessible';
        }

        if (!($details['methods']['gcore_service'] ?? false)) {
            $reasons[] = 'gNode service not registered with gCore';
        }

        if (empty($reasons)) {
            return 'gNode not available - using default tier storage';
        }

        return 'gNode not available (' . implode(', ', $reasons) . ') - using default tier storage';
    }

    /**
     * Reset cached detection (for testing)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$available = null;
        self::$client = null;
        self::$detectionDetails = [];
        self::$lastDetection = 0;
    }

    /**
     * Set cache TTL
     *
     * @param int $ttl TTL in seconds
     * @return void
     */
    public static function setCacheTTL(int $ttl): void
    {
        self::$cacheTTL = max(0, $ttl);
    }

    /**
     * Check if a specific gNode feature is available
     * Allows checking for extension-only features
     *
     * @param string $feature Feature name
     * @return bool True if feature is available
     */
    public static function hasFeature(string $feature): bool
    {
        // If gNode isn't available, no features are available
        if (!self::isAvailable()) {
            return false;
        }

        // Feature map - can be extended
        $features = [
            'batch_operations' => true,
            'content_minification' => true,
            'template_caching' => true,
            'asset_bundling' => true,
            'distributed_cache' => true,
            'broadcast_invalidation' => true,
            'native_resp3' => true,
            'format_validation' => true,
            'geometric_topology' => true,
            'tera_templates' => true
        ];

        return $features[$feature] ?? false;
    }

    /**
     * Get list of available extension features
     *
     * @return array Feature availability map
     */
    public static function getFeatures(): array
    {
        $gNodeAvailable = self::isAvailable();

        return [
            'batch_operations' => [
                'available' => $gNodeAvailable,
                'description' => 'Batch cache operations (single round-trip)'
            ],
            'content_minification' => [
                'available' => $gNodeAvailable,
                'description' => 'Automatic HTML/CSS/JS minification and compression'
            ],
            'template_caching' => [
                'available' => $gNodeAvailable,
                'description' => 'Server-side Tera template caching with dependencies'
            ],
            'asset_bundling' => [
                'available' => $gNodeAvailable,
                'description' => 'Automatic asset bundling and optimization'
            ],
            'distributed_cache' => [
                'available' => $gNodeAvailable,
                'description' => 'Cross-node distributed cache with coherence'
            ],
            'broadcast_invalidation' => [
                'available' => $gNodeAvailable,
                'description' => 'Multi-node cache invalidation broadcasts'
            ],
            'geometric_topology' => [
                'available' => $gNodeAvailable,
                'description' => 'Capability-based service discovery'
            ],
            'tera_templates' => [
                'available' => $gNodeAvailable,
                'description' => 'Rust-based template rendering'
            ]
        ];
    }

    /**
     * Get upgrade prompt for users without gNode
     *
     * @param string $feature Optional specific feature being requested
     * @return array Upgrade information
     */
    public static function getUpgradePrompt(?string $feature = null): array
    {
        $prompt = [
            'title' => 'Extension Feature',
            'message' => 'This feature requires gNode integration.',
            'features' => self::getFeatures(),
            'benefits' => [
                'Batched cache operations',
                'Distributed caching across multiple servers',
                'Automatic content optimization',
                'Rust-based processing'
            ],
            'action' => [
                'label' => 'Learn More',
                'url' => 'https://geodineum.com/docs/gnode-integration'  // Placeholder URL
            ]
        ];

        if ($feature !== null) {
            $featureInfo = self::getFeatures()[$feature] ?? null;
            if ($featureInfo) {
                $prompt['requested_feature'] = [
                    'name' => $feature,
                    'description' => $featureInfo['description']
                ];
            }
        }

        return $prompt;
    }
}
