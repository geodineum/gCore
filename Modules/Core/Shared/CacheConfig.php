<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Shared;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Multi-Site Cache Operation Configuration for Cache
 * 
 * Hardened Lua scripts optimized for:
 * - Multi-site deployment
 * - High-throughput scenarios
 * - Cluster-safe operations
 * - Minimal network overhead
 * - error handling
 * - Built-in performance monitoring
 *
 * @package     gCore
 * @subpackage  Shared
 * @version     0.1.0
 */

class CacheConfig {
    private static $config = null;
    private const CONFIG_PATH = 'config/lua_scripts.yaml';
    
    public const DEFAULT_BATCH_SIZE = 1000;
    public const DEFAULT_KEY_LENGTH = 256;
    public const DEFAULT_VALUE_SIZE = 512 * 1024;
    public const DEFAULT_KEY_PATTERN = '^[a-zA-Z0-9:_-]+$';  // for future use
    public const DEFAULT_QUOTA_MAX_SIZE = 1099511627776; // 1 TB
    public const MAX_ENTRY_SIZE = 1048576;  // 1MB
    public const MAX_METRIC_SIZE = 5242880;  // 5MB
    public const CIRCUIT_BREAKER_RESET = 300; // 5 minutes

    // Lock/Transaction timeouts
    public const LOCK_TIMEOUT = 30;        // seconds
    public const LOCK_RETRY_COUNT = 3;     
    public const LOCK_RETRY_DELAY = 100;   // milliseconds
    public const TX_TIMEOUT = 30;          // seconds

    // Stream configurations (for future use)
    public const STREAM_BATCH_SIZE = 100;
    public const STREAM_BLOCK_TIMEOUT = 1000; // milliseconds

    /**
     * Initialize the configuration
     */
    public static function init(): void {
        self::$config = self::getDefaults();
    }

    /**
     * Get default configuration values
     */
    private static function getDefaults(): array {
        return [
            'limits' => [
                'batch' => [
                    'max_size' => self::DEFAULT_BATCH_SIZE,
                    'max_key_length' => self::DEFAULT_KEY_LENGTH,
                    'max_value_size' => self::DEFAULT_VALUE_SIZE,
                    'max_total_size' => self::DEFAULT_VALUE_SIZE * 10
                ],
                'quota' => [
                    'max_size' => self::DEFAULT_QUOTA_MAX_SIZE,
                ],
                'metrics' => [
                    'max_entry_size' => self::MAX_ENTRY_SIZE,
                    'max_metric_size' => self::MAX_METRIC_SIZE
                ]
            ],
            'timeouts' => [
                'lock' => [
                    'timeout' => self::LOCK_TIMEOUT,
                    'retry_count' => self::LOCK_RETRY_COUNT,
                    'retry_delay' => self::LOCK_RETRY_DELAY
                ],
                'transaction' => [
                    'timeout' => self::TX_TIMEOUT
                ],
                'circuit_breaker' => [
                    'reset' => self::CIRCUIT_BREAKER_RESET
                ]
            ],
            'streams' => [
                'batch_size' => self::STREAM_BATCH_SIZE,
                'block_timeout' => self::STREAM_BLOCK_TIMEOUT
            ]
        ];
    }

    /**
     * Render a script by replacing placeholders with configuration values
     */
    public static function render(string $script): string {
        // Ensure config is initialized
        if (self::$config === null) {
            self::init();
        }
        
        return strtr($script, [
            // Batch limits - add null coalescence for safety
            '{maxBatchSize}' => self::$config['limits']['batch']['max_size'] ?? self::DEFAULT_BATCH_SIZE,
            '{maxKeyLength}' => self::$config['limits']['batch']['max_key_length'] ?? self::DEFAULT_KEY_LENGTH,
            '{maxValueSize}' => self::$config['limits']['batch']['max_value_size'] ?? self::DEFAULT_VALUE_SIZE,
            '{maxTotalSize}' => self::$config['limits']['batch']['max_total_size'] ?? (self::DEFAULT_VALUE_SIZE * 10),
            
            // Quota limits
            '{quotaMaxSize}' => self::$config['limits']['quota']['max_size'] ?? self::DEFAULT_QUOTA_MAX_SIZE,
            
            // Metric limits
            '{maxEntrySize}' => self::$config['limits']['metrics']['max_entry_size'] ?? self::MAX_ENTRY_SIZE,
            '{maxMetricSize}' => self::$config['limits']['metrics']['max_metric_size'] ?? self::MAX_METRIC_SIZE,
            
            // Timeouts
            '{lockTimeout}' => self::$config['timeouts']['lock']['timeout'] ?? self::LOCK_TIMEOUT,
            '{lockRetryCount}' => self::$config['timeouts']['lock']['retry_count'] ?? self::LOCK_RETRY_COUNT,
            '{lockRetryDelay}' => self::$config['timeouts']['lock']['retry_delay'] ?? self::LOCK_RETRY_DELAY,
            '{txTimeout}' => self::$config['timeouts']['transaction']['timeout'] ?? self::TX_TIMEOUT,
            '{circuitBreakerReset}' => self::$config['timeouts']['circuit_breaker']['reset'] ?? self::CIRCUIT_BREAKER_RESET,
            
            // Stream configs
            '{streamBatchSize}' => self::$config['streams']['batch_size'] ?? self::STREAM_BATCH_SIZE,
            '{streamBlockTimeout}' => self::$config['streams']['block_timeout'] ?? self::STREAM_BLOCK_TIMEOUT
        ]);
    }
}

// Initialize configuration on file load
CacheConfig::init();