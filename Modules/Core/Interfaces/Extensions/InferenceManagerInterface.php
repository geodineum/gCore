<?php
declare(strict_types=1);
/**
 * InferenceManager Interface
 *
 * Contract for ML inference capabilities via Ollama integration.
 * Extension implementations provide full text generation, chat, embeddings,
 * batch operations, and model management.
 * Default stubs provide graceful no-op degradation.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

/**
 * Interface InferenceManagerInterface
 *
 * Defines the contract for ML inference operations.
 * Implementations may use Ollama for inference (extension) or
 * provide no-op stubs for graceful degradation (default).
 */
interface InferenceManagerInterface extends ModuleInterface
{
    // =========================================================================
    // CORE INFERENCE OPERATIONS
    // =========================================================================

    /**
     * Generate text completion
     *
     * @param string $prompt Text prompt
     * @param string $model Model to use (default: llama3)
     * @param array $options Additional options (temperature, max_tokens, etc.)
     * @return array ['success' => bool, 'result' => string, 'metrics' => array, 'cached' => bool]
     */
    public function generateText(string $prompt, string $model = 'llama3', array $options = []): array;

    /**
     * Multi-turn chat with conversation context
     *
     * @param array $messages Array of [{role: 'user'|'assistant'|'system', content: '...'}]
     * @param string $model Model to use
     * @param array $options Additional options
     * @return array ['success' => bool, 'result' => string, 'conversation_id' => string, 'metrics' => array]
     */
    public function chat(array $messages, string $model = 'llama3', array $options = []): array;

    /**
     * Generate vector embeddings for semantic search
     *
     * @param string $text Text to embed
     * @param string $model Embedding model
     * @return array ['success' => bool, 'embedding' => array, 'dimensions' => int, 'cached' => bool]
     */
    public function generateEmbeddings(string $text, string $model = 'nomic-embed-text'): array;

    /**
     * Batch inference for multiple prompts
     *
     * @param array $prompts Array of prompts
     * @param string $model Model to use
     * @param array $options Additional options
     * @return array Associative array [prompt_hash => result]
     */
    public function batchInference(array $prompts, string $model = 'llama3', array $options = []): array;

    // =========================================================================
    // MODEL MANAGEMENT
    // =========================================================================

    /**
     * List available models
     *
     * @param bool $useCache Use cached model list
     * @return array Array of model information
     */
    public function listAvailableModels(bool $useCache = true): array;

    /**
     * Get model information
     *
     * @param string $model Model name
     * @return array Model details
     */
    public function getModelInfo(string $model): array;

    /**
     * Pull (download) a model
     *
     * @param string $model Model name
     * @param callable|null $progressCallback Progress callback
     * @return bool Success status
     */
    public function pullModel(string $model, callable $progressCallback = null): bool;

    /**
     * Warm up (preload) models into memory
     *
     * @param array $models List of model names
     * @param string|null $keepAlive Keep alive duration
     * @return array Results for each model
     */
    public function warmupModels(array $models, ?string $keepAlive = null): array;

    /**
     * Preload a single model
     *
     * @param string $model Model name
     * @param string $keepAlive Keep alive duration
     * @return bool Success
     */
    public function preloadModel(string $model, string $keepAlive = '5m'): bool;

    /**
     * Unload a model from memory
     *
     * @param string $model Model name
     * @return bool Success
     */
    public function unloadModel(string $model): bool;

    /**
     * Set keep_alive duration for subsequent requests
     *
     * @param string $duration Duration string (e.g., '5m', '1h', '-1')
     */
    public function setKeepAlive(string $duration): void;

    // =========================================================================
    // AUDIT
    // =========================================================================

    /**
     * Get recent audit entries from gNode stream
     *
     * @param int $count Number of entries to retrieve
     * @param string|null $startId Start ID for pagination
     * @return array Audit entries
     */
    public function getAuditLog(int $count = 100, ?string $startId = null): array;

    // =========================================================================
    // CAPABILITY DISCOVERY
    // =========================================================================

    /**
     * Get capability vector for GeometricTopology service discovery
     *
     * @return array Capability vector with dimension scores (0.0-1.0)
     */
    public function getCapabilityVector(): array;
}
