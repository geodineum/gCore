<?php
declare(strict_types=1);
/**
 * InferenceManager Stub
 *
 * Graceful no-op implementation for default tier.
 * Provides all InferenceManagerInterface methods but returns error responses.
 * No actual ML inference occurs without Ollama backend.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\InferenceManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class InferenceManagerStub
 *
 * Free-tier stub implementation of InferenceManagerInterface.
 * All inference methods return error responses indicating stub mode.
 */
class InferenceManagerStub implements InferenceManagerInterface
{
    /** @var InferenceManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged */
    private static $upgradeNoticeLogged = false;

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => false,
        'stub_mode' => true,
        'site_id' => 'default',
        'node_id' => 'stub',
    ];

    /** @var array Capability vector (minimal for stub) */
    private $capabilityVector = [
        'ml_inference' => 0.0,
        'prediction' => 0.0,
        'recommendation' => 0.0,
        'analytics' => 0.1,
        'pattern_recognition' => 0.0
    ];

    /** @var string Stub error message */
    private const STUB_ERROR = 'ML inference requires gcore-inference full with Ollama backend';

    /**
     * Get singleton instance
     */
    public static function getInstance(): ModuleInterface
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Initialize stub
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        $this->config = array_merge($this->defaultConfig, $config);
        $this->initialized = true;

        $this->logUpgradeNotice();
    }

    /**
     * Log upgrade notice (once per request)
     */
    private function logUpgradeNotice(): void
    {
        if (self::$upgradeNoticeLogged) {
            return;
        }

        self::$upgradeNoticeLogged = true;

        if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] InferenceManager stub active - the gcore-inference extension provides ML capabilities'); }
        }
    }

    // =========================================================================
    // CORE INFERENCE OPERATIONS (all return error responses)
    // =========================================================================

    /**
     * Generate text (stub: returns error)
     */
    public function generateText(string $prompt, string $model = 'llama3', array $options = []): array
    {
        return [
            'success' => false,
            'error' => self::STUB_ERROR,
            'cached' => false,
            'stub_mode' => true,
        ];
    }

    /**
     * Chat (stub: returns error)
     */
    public function chat(array $messages, string $model = 'llama3', array $options = []): array
    {
        return [
            'success' => false,
            'error' => self::STUB_ERROR,
            'stub_mode' => true,
        ];
    }

    /**
     * Generate embeddings (stub: returns error)
     */
    public function generateEmbeddings(string $text, string $model = 'nomic-embed-text'): array
    {
        return [
            'success' => false,
            'error' => self::STUB_ERROR,
            'cached' => false,
            'stub_mode' => true,
        ];
    }

    /**
     * Batch inference (stub: returns empty)
     */
    public function batchInference(array $prompts, string $model = 'llama3', array $options = []): array
    {
        return [];
    }

    // =========================================================================
    // MODEL MANAGEMENT (all return empty/false)
    // =========================================================================

    /**
     * List available models (stub: returns empty)
     */
    public function listAvailableModels(bool $useCache = true): array
    {
        return [];
    }

    /**
     * Get model info (stub: returns error)
     */
    public function getModelInfo(string $model): array
    {
        return ['error' => self::STUB_ERROR, 'stub_mode' => true];
    }

    /**
     * Pull model (stub: returns false)
     */
    public function pullModel(string $model, callable $progressCallback = null): bool
    {
        return false;
    }

    /**
     * Warm up models (stub: returns failures)
     */
    public function warmupModels(array $models, ?string $keepAlive = null): array
    {
        $results = [];
        foreach ($models as $model) {
            $results[$model] = [
                'success' => false,
                'error' => self::STUB_ERROR,
            ];
        }
        return $results;
    }

    /**
     * Preload model (stub: returns false)
     */
    public function preloadModel(string $model, string $keepAlive = '5m'): bool
    {
        return false;
    }

    /**
     * Unload model (stub: returns false)
     */
    public function unloadModel(string $model): bool
    {
        return false;
    }

    /**
     * Set keep alive (stub: no-op)
     */
    public function setKeepAlive(string $duration): void
    {
        // Stub - no-op
    }

    // =========================================================================
    // AUDIT (returns empty)
    // =========================================================================

    /**
     * Get audit log (stub: returns empty)
     */
    public function getAuditLog(int $count = 100, ?string $startId = null): array
    {
        return [];
    }

    // =========================================================================
    // CAPABILITY DISCOVERY
    // =========================================================================

    /**
     * Get capability vector (minimal for stub)
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    // =========================================================================
    // MODULE INTERFACE
    // =========================================================================

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'stub_mode' => true,
            'mode' => 'stub',
            'gnode_enabled' => false,
            'ollama_url' => null,
            'ollama_connected' => false,
            'statistics' => [
                'inferences_total' => 0,
                'inferences_cached' => 0,
                'inferences_generated' => 0,
                'embeddings_generated' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
                'errors' => 0,
                'rate_limit_hits' => 0,
            ],
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'upgrade_message' => 'The gcore-inference extension provides ML capabilities (text generation, chat, embeddings)',
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
