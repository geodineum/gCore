<?php
declare(strict_types=1);
namespace gCore\Modules\Managers\Base\FormatManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\Utils\SelfContainedErrorHandler;
use gCore\Modules\Core\Exceptions\{
    InitializationException,
    StorageException,
    ValidationException
};

/**
 * Format Manager — Delegates to gNode-Client FormatManager
 *
 * Thin ModuleInterface wrapper around gNode-Client's FormatManager.
 * All format operations delegate to the client FM, which provides
 * FormatRegistry caching (80-90% fewer ValKey calls).
 *
 * @package gCore\Modules\Managers\Base\FormatManager
 * @version 2.0.0
 */
class FormatManager implements ModuleInterface {
    use ManagerConfigTrait;

    /** Hardcoded floor defaults. See ManagerConfigTrait for the layering rationale. */
    private const DEFAULTS = [
        'use_gnode' => true,
        'auto_detect' => true,
        'validation_mode' => 'strict',
        'cache_formats' => true,
    ];

    private static $instance = null;
    private $config = [];
    private $initialized = false;

    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];

    private $metrics = [
        'registrations' => 0,
        'detections' => 0,
        'conversions' => 0,
        'detection_hits' => 0,
        'detection_misses' => 0,
        'conversion_failures' => 0
    ];

    /** @var \gCore\gNode\gNodeClientInterface|null */
    private $gNodeClient = null;

    /** @var \gCore\gNode\Format\FormatManager|null Client's FM (has caching) */
    private $clientFM = null;

    private $useGNode = false;

    private $capabilityVector = [
        'format' => 1.0,
        'detection' => 0.9,
        'conversion' => 0.9,
        'validation' => 0.8
    ];

    public static function getInstance(): ModuleInterface {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Initialize with gNode-Client for all format operations.
     */
    public function initialize(array $config = [], array $nodeMetadata = [], $gNodeClient = null): void {
        if ($this->initialized) {
            return;
        }

        try {
            if (!empty($nodeMetadata)) {
                $this->nodeMetadata = array_merge($this->nodeMetadata, $nodeMetadata);
            }

            // Layered config: DEFAULTS → ValKey (defaults + per-site) → $config arg
            $siteId = (string)($this->nodeMetadata['site_id']);
            $valkeyConfig = [];
            $storage = $this->gcoreResolveStorage($config);
            if ($storage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'FormatManager');
            }
            $this->config = array_merge(self::DEFAULTS, $valkeyConfig, $config);

            $gNodeClientInstance = $gNodeClient ?? ($config['gnode_client'] ?? null);

            if ($gNodeClientInstance !== null && $gNodeClientInstance instanceof \gCore\gNode\gNodeClientInterface) {
                $this->gNodeClient = $gNodeClientInstance;
                $this->useGNode = !empty($this->config['use_gnode']);

                // Get the client's FormatManager (has FormatRegistry caching)
                if ($this->useGNode && method_exists($this->gNodeClient, 'getFormatManager')) {
                    try {
                        $this->clientFM = $this->gNodeClient->getFormatManager();
                    } catch (\Throwable $e) {
                        SelfContainedErrorHandler::logWarning(
                            'FormatManager', 'initialize',
                            'Could not get client FormatManager, falling back to direct commands',
                            ['error' => $e->getMessage()]
                        );
                    }
                }

                SelfContainedErrorHandler::logInfo(
                    'FormatManager', 'initialize',
                    'Initialized with gNode integration',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'use_gnode' => $this->useGNode,
                        'has_client_fm' => $this->clientFM !== null
                    ]
                );
            } else {
                $this->useGNode = false;
                SelfContainedErrorHandler::logWarning(
                    'FormatManager', 'initialize',
                    'Initialized WITHOUT gNode (local mode not supported)',
                    ['site_id' => $this->nodeMetadata['site_id']]
                );
            }

            $this->initialized = true;

        } catch (\Throwable $e) {
            throw new InitializationException(
                "Failed to initialize FormatManager: " . $e->getMessage(), 0, $e
            );
        }
    }

    // ========================================================================
    // FORMAT REGISTRATION & MANAGEMENT (delegated to client FM)
    // ========================================================================

    /**
     * Register a format. Delegates to gNode-Client's FM for caching.
     * @api
     */
    public function registerFormat(string $name, array $schema, array $patterns = [], array $metadata = []): array {
        $this->validateFormatName($name);
        $this->requireGNode();

        try {
            $definition = [
                'name' => $name,
                'version' => $metadata['version'] ?? '1.0.0',
                'schema' => $schema,
                'patterns' => $patterns,
                'metadata' => $metadata
            ];

            if ($this->clientFM) {
                $this->clientFM->registerFormat($definition);
            } else {
                $this->gNodeClient->executeCommand('register_format', [
                    'format_definition' => $definition
                ]);
            }

            $this->metrics['registrations']++;

            SelfContainedErrorHandler::logInfo(
                'FormatManager', 'registerFormat', "Registered format",
                ['name' => $name, 'patterns_count' => count($patterns)]
            );

            return ['success' => true, 'name' => $name, 'version' => $definition['version']];

        } catch (\Throwable $e) {
            $this->logError('register_format', $name, $e->getMessage());
            throw new StorageException("Failed to register format: {$name}", 0, $e);
        }
    }

    /**
     * List all registered formats. Client FM caches results.
     * @api
     */
    public function listFormats(): array {
        $this->requireGNode();

        try {
            if ($this->clientFM) {
                return $this->clientFM->listFormats();
            }
            $result = $this->gNodeClient->executeCommand('list_formats', []);
            return $result ?? [];
        } catch (\Throwable $e) {
            $this->logError('list_formats', 'all', $e->getMessage());
            return [];
        }
    }

    /**
     * Get a specific format definition. Uses client FM cache.
     * @api
     */
    public function getFormat(string $name): ?array {
        $this->validateFormatName($name);
        $this->requireGNode();

        try {
            if ($this->clientFM) {
                $schema = $this->clientFM->getSchema($name);
                if ($schema !== null) {
                    return ['name' => $name, 'schema' => $schema];
                }
                return null;
            }
            $result = $this->gNodeClient->executeCommand('get_format', ['name' => $name]);
            return $result ?? null;
        } catch (\Throwable $e) {
            $this->logError('get_format', $name, $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a format. No client FM equivalent — uses executeCommand.
     * @api
     */
    public function deleteFormat(string $name): bool {
        $this->validateFormatName($name);
        $this->requireGNode();

        try {
            $result = $this->gNodeClient->executeCommand('delete_format', ['name' => $name]);

            // Clear client FM cache if available
            if ($this->clientFM) {
                $this->clientFM->clearCache();
            }

            SelfContainedErrorHandler::logInfo(
                'FormatManager', 'deleteFormat', "Deleted format", ['name' => $name]
            );

            return isset($result['success']) && $result['success'];
        } catch (\Throwable $e) {
            $this->logError('delete_format', $name, $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // FORMAT DETECTION (delegated — client FM has local pattern cache)
    // ========================================================================

    /**
     * Detect message format. Client FM tries local cache first.
     * @api
     */
    public function detectFormat(string $message): array {
        $this->requireGNode();

        try {
            $this->metrics['detections']++;

            if ($this->clientFM) {
                $formatName = $this->clientFM->detectFormat($message);
                if ($formatName !== null) {
                    $this->metrics['detection_hits']++;
                    return ['format' => $formatName, 'confidence' => 1.0, 'pattern_matched' => null];
                }
                $this->metrics['detection_misses']++;
                return ['format' => 'unknown', 'confidence' => 0.0, 'pattern_matched' => null];
            }

            $result = $this->gNodeClient->executeCommand('detect_format', ['message' => $message]);

            if (isset($result['detected_format']) && $result['detected_format'] !== 'unknown') {
                $this->metrics['detection_hits']++;
            } else {
                $this->metrics['detection_misses']++;
            }

            return [
                'format' => $result['detected_format'] ?? 'unknown',
                'confidence' => $result['confidence'] ?? 0.0,
                'pattern_matched' => $result['pattern_matched'] ?? null
            ];
        } catch (\Throwable $e) {
            $this->metrics['detection_misses']++;
            $this->logError('detect_format', 'message', $e->getMessage());
            return ['format' => 'unknown', 'confidence' => 0.0, 'pattern_matched' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Detect format and validate in one step.
     * @api
     */
    public function detectAndValidate(string $message): array {
        $detection = $this->detectFormat($message);

        if ($detection['format'] === 'unknown') {
            return array_merge($detection, [
                'validation' => ['valid' => false, 'errors' => ['Format could not be detected']]
            ]);
        }

        try {
            $validated = $this->validateMessage($message, $detection['format']);
            return array_merge($detection, ['validation' => $validated]);
        } catch (\Throwable $e) {
            return array_merge($detection, [
                'validation' => ['valid' => false, 'errors' => [$e->getMessage()]]
            ]);
        }
    }

    // ========================================================================
    // FORMAT CONVERSION (delegated)
    // ========================================================================

    /**
     * Convert message between formats.
     * @api
     */
    public function convertFormat(
        string $message,
        string $sourceFormat,
        string $targetFormat,
        array $options = []
    ): array {
        $this->validateFormatName($sourceFormat);
        $this->validateFormatName($targetFormat);
        $this->requireGNode();

        try {
            $this->metrics['conversions']++;

            if ($this->clientFM && empty($options)) {
                $converted = $this->clientFM->convertFormat($message, $sourceFormat, $targetFormat);
                return [
                    'converted_message' => $converted,
                    'source_format' => $sourceFormat,
                    'target_format' => $targetFormat,
                    'validation' => 'passed'
                ];
            }

            $parameters = [
                'source_format' => $sourceFormat,
                'target_format' => $targetFormat,
                'message' => $message
            ];
            if (isset($options['source_version'])) {
                $parameters['source_version'] = $options['source_version'];
            }
            if (isset($options['target_version'])) {
                $parameters['target_version'] = $options['target_version'];
            }

            $result = $this->gNodeClient->executeCommand('convert_format', $parameters);

            if (isset($result['validation']) && $result['validation'] !== 'passed') {
                $this->metrics['conversion_failures']++;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->metrics['conversion_failures']++;
            $this->logError('convert_format', "{$sourceFormat}->{$targetFormat}", $e->getMessage());
            throw new StorageException(
                "Failed to convert format from {$sourceFormat} to {$targetFormat}", 0, $e
            );
        }
    }

    /**
     * Auto-detect source format and convert.
     * @api
     */
    public function autoConvertFormat(string $message, string $targetFormat, array $options = []): array {
        $detection = $this->detectFormat($message);

        if ($detection['format'] === 'unknown') {
            throw new StorageException('Cannot auto-convert: source format could not be detected');
        }

        $result = $this->convertFormat($message, $detection['format'], $targetFormat, $options);
        return array_merge($result, ['detected_source' => $detection]);
    }

    // ========================================================================
    // FORMAT VALIDATION (no client FM equivalent — uses executeCommand)
    // ========================================================================

    /**
     * Validate message against a format's schema.
     * @api
     */
    public function validateMessage(string $message, string $formatName): array {
        $this->validateFormatName($formatName);
        $this->requireGNode();

        try {
            $result = $this->gNodeClient->executeCommand('validate_format', [
                'format_name' => $formatName,
                'message' => $message
            ]);
            return ['valid' => $result['valid'] ?? false, 'errors' => $result['errors'] ?? []];
        } catch (\Throwable $e) {
            $this->logError('validate_format', $formatName, $e->getMessage());
            return ['valid' => false, 'errors' => [$e->getMessage()]];
        }
    }

    // ========================================================================
    // BULK OPERATIONS
    // ========================================================================

    /** @api */
    public function registerFormats(array $formats): array {
        $this->requireGNode();
        $results = [];

        foreach ($formats as $format) {
            try {
                $name = $format['name'] ?? null;
                if (!$name) {
                    $results[] = ['success' => false, 'error' => 'Format name is required'];
                    continue;
                }
                $result = $this->registerFormat($name, $format['schema'] ?? [], $format['patterns'] ?? [], $format['metadata'] ?? []);
                $results[] = array_merge(['success' => true], $result);
            } catch (\Throwable $e) {
                $results[] = ['success' => false, 'name' => $format['name'] ?? 'unknown', 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @api */
    public function detectFormats(array $messages): array {
        $this->requireGNode();
        $results = [];

        foreach ($messages as $index => $message) {
            try {
                $results[$index] = $this->detectFormat($message);
            } catch (\Throwable $e) {
                $results[$index] = ['format' => 'unknown', 'confidence' => 0.0, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    // ========================================================================
    // MODULE INTERFACE
    // ========================================================================

    public function getMetrics(): array {
        $detectionRate = $this->metrics['detections'] > 0
            ? round(($this->metrics['detection_hits'] / $this->metrics['detections']) * 100, 2) : 0;
        $conversionSuccessRate = $this->metrics['conversions'] > 0
            ? round((($this->metrics['conversions'] - $this->metrics['conversion_failures']) / $this->metrics['conversions']) * 100, 2) : 0;

        $clientStats = $this->clientFM ? $this->clientFM->getStatistics() : [];

        return [
            'registrations' => $this->metrics['registrations'],
            'detections' => $this->metrics['detections'],
            'conversions' => $this->metrics['conversions'],
            'detection_hits' => $this->metrics['detection_hits'],
            'detection_misses' => $this->metrics['detection_misses'],
            'detection_rate' => $detectionRate,
            'conversion_failures' => $this->metrics['conversion_failures'],
            'conversion_success_rate' => $conversionSuccessRate,
            'mode' => $this->useGNode ? 'gnode' : 'local',
            'client_fm_stats' => $clientStats
        ];
    }

    public function getConfig(): array { return $this->config; }
    public function getCapabilityVector(): array { return $this->capabilityVector; }
    public function isInitialized(): bool { return $this->initialized; }

    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
        if (isset($config['site_id'])) { $this->nodeMetadata['site_id'] = $config['site_id']; }
        if (isset($config['node_id'])) { $this->nodeMetadata['node_id'] = $config['node_id']; }

        // Persist updates to ValKey per-site override.
        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->nodeMetadata['site_id']);
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'FormatManager', (string)$key, $value);
            }
        }
    }

    public function getStatus(): array {
        return [
            'initialized' => $this->initialized,
            'use_gnode' => $this->useGNode,
            'gnode_connected' => $this->gNodeClient !== null,
            'has_client_fm' => $this->clientFM !== null,
            'metrics' => $this->metrics,
            'node_metadata' => $this->nodeMetadata,
            'capability_vector' => $this->capabilityVector
        ];
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    private function requireGNode(): void {
        if (!$this->useGNode) {
            throw new StorageException('Format operations require gNode integration (useGNode=true)');
        }
    }

    private function validateFormatName(string $name): void {
        if (empty($name)) {
            throw new ValidationException("Format name cannot be empty");
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
            throw new ValidationException("Format name contains invalid characters: {$name}");
        }
        if (strlen($name) > 64) {
            throw new ValidationException("Format name too long (max 64 characters): {$name}");
        }
    }

    private function logError(string $operation, string $identifier, string $message): void {
        SelfContainedErrorHandler::logErrorMessage(
            'FormatManager', $operation, "Format operation failed",
            ['identifier' => $identifier, 'error' => $message, 'site_id' => $this->nodeMetadata['site_id']]
        );
    }
}
