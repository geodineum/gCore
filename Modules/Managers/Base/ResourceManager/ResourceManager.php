<?php
declare(strict_types=1);
/**
 * gCore Resource Management System
 *
 * Provides resource management including asset bundling, template management,
 * and resource optimization functionality. Framework-agnostic design with gNode integration.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\ResourceManager
 * @version     1.0.0
 * @since       1.0.0
 *
 * == Integration Points ==
 * - CacheManager: Content caching and retrieval
 * - OptimizationManager: Optimization strategies
 * - MetricsManager: Performance tracking
 * - gNode: Asset bundling, template management, service discovery
 *
 * == Key Features ==
 * - Native gNode asset bundling (batched, single round-trip)
 * - Template fragment management with discovery
 * - Server-side template rendering
 * - Resource loading and optimization
 * - Capability-based template discovery
 * - Multi-tenant isolation (site_id/node_id)
 *
 * == Usage Example ==
 * $gCore = \gCore\Modules\Core\gCore::getInstance();
 * $resources = $gCore->getService('ResourceManager');
 * $bundle = $resources->createAssetBundle('main-css', [
 *     'style.css' => $cssContent,
 *     'theme.css' => $themeContent
 * ], 'css', true);
 *
 * @author    Niels Erik Toren
 * @copyright 2024 gCore
 */

namespace gCore\Modules\Managers\Base\ResourceManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Core\Utils\SelfContainedErrorHandler;
use gCore\Modules\Core\Exceptions\{
    InitializationException,
    StorageException,
    ValidationException
};

require_once dirname(__DIR__, 2) . '/Traits/StateManagerAware.php';
use gCore\Modules\Managers\Traits\StateManagerAware;

class ResourceManager implements ModuleInterface {
    use StateManagerAware;
    use ManagerConfigTrait;

    private const DEFAULTS = [
        'site_id' => 'default',
        'node_id' => 'node1',
        'use_gnode' => true,
        'cache_enabled' => true,
        'optimization_enabled' => true,
        'default_bundle_type' => 'mixed',
        'default_minify' => true,
        'default_ttl' => 3600,
        'max_bundle_size' => 1048576,
        'debug' => false,
    ];

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Initialization state
     */
    private $initialized = false;

    /**
     * Configuration
     */
    private $config = [];

    /**
     * Node metadata for multi-tenant isolation
     */
    private $nodeMetadata = [
        'site_id' => 'default',
        'node_id' => 'node1'
    ];

    /**
     * Capability vector for gNode integration
     */
    private $capabilityVector = [
        'resource_loading' => 1.0,
        'asset_bundling' => 0.95,
        'template_management' => 0.9,
        'optimization' => 0.8,
        'caching' => 0.85
    ];

    /**
     * gNode-Client instance for gNode integration
     */
    private $gNodeClient = null;

    /**
     * Use gNode integration flag
     */
    private $useGNode = false;

    /**
     * CacheManager instance
     */
    private $cache = null;

    /**
     * OptimizationManager instance
     */
    private $optimization = null;

    /**
     * MetricsManager instance
     */
    private $metrics = null;

    /**
     * Resource registry (in-memory cache)
     */
    private $registry = [
        'assets' => [],
        'templates' => [],
        'bundles' => []
    ];

    /**
     * Resource statistics
     */
    private $stats = [
        'assets_loaded' => 0,
        'templates_rendered' => 0,
        'bundles_created' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0
    ];

    /**
     * Get singleton instance
     *
     * @return ModuleInterface ResourceManager instance
     */
    public static function getInstance(): ModuleInterface {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {}

    /**
     * Initialize ResourceManager with configuration
     *
     * @param array $config Configuration options
     * @return void
     * @throws InitializationException If initialization fails
     */
    public function initialize(array $config = []): void {
        if ($this->initialized) {
            return;
        }

        try {
            // Layered config: DEFAULTS → ValKey (defaults + per-site) → $config arg
            $siteId = (string)($config['site_id'] ?? self::DEFAULTS['site_id']);
            $valkeyConfig = [];
            $storage = $this->gcoreResolveStorage($config);
            if ($storage !== null) {
                $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'ResourceManager');
            }
            $this->config = array_merge(self::DEFAULTS, $valkeyConfig, $config);

            // Set node metadata for multi-tenant isolation
            $this->nodeMetadata = [
                'site_id' => $this->config['site_id'],
                'node_id' => $this->config['node_id']
            ];

            // Set site ID for StateManagerAware trait
            $this->siteId = $this->config['site_id'];

            // Check for gNode-Client integration (supports gNodeClient, KeyBasedClientLuaEnabled, etc.)
            if (isset($config['gnode_client']) &&
                $config['gnode_client'] instanceof \gCore\gNode\gNodeClientInterface &&
                ($this->config['use_gnode'] ?? true)) {
                $this->gNodeClient = $config['gnode_client'];
                $this->useGNode = true;

                SelfContainedErrorHandler::logInfo(
                    'ResourceManager',
                    'initialize',
                    'ResourceManager using gNode integration',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'node_id' => $this->nodeMetadata['node_id']
                    ]
                );
            } else {
                $this->useGNode = false;

                SelfContainedErrorHandler::logWarning(
                    'ResourceManager',
                    'initialize',
                    'ResourceManager using legacy mode (gNode not available)',
                    [
                        'site_id' => $this->nodeMetadata['site_id'],
                        'node_id' => $this->nodeMetadata['node_id']
                    ]
                );
            }

            // Initialize dependencies from gCore
            $core = \gCore\Modules\Core\gCore::getInstance();

            // CacheManager is optional (enables persistent caching)
            try {
                $this->cache = $core->getService('CacheManager');
            } catch (\Exception $e) {
                // CacheManager not available - basic operations will still work
                // but caching features will be disabled
                $this->cache = null;
                $this->config['cache_enabled'] = false;
                $this->debug('CacheManager not available: ' . $e->getMessage());
            }

            // OptimizationManager is optional
            try {
                $this->optimization = $core->getService('OptimizationManager');
            } catch (\Exception $e) {
                $this->debug('OptimizationManager not available: ' . $e->getMessage());
            }

            // MetricsManager is optional
            try {
                $this->metrics = $core->getService('MetricsManager');
            } catch (\Exception $e) {
                $this->debug('MetricsManager not available: ' . $e->getMessage());
            }

            $this->initialized = true;

            SelfContainedErrorHandler::logInfo(
                'ResourceManager',
                'initialize',
                'Successfully initialized ResourceManager',
                [
                    'site_id' => $this->nodeMetadata['site_id'],
                    'node_id' => $this->nodeMetadata['node_id'],
                    'gnode_enabled' => $this->useGNode
                ]
            );

        } catch (\Exception $e) {
            SelfContainedErrorHandler::logError(
                'ResourceManager',
                'initialize',
                $e,
                [
                    'site_id' => $this->nodeMetadata['site_id'] ?? 'default',
                    'node_id' => $this->nodeMetadata['node_id'] ?? 'node1'
                ]
            );

            throw new InitializationException(
                'Failed to initialize ResourceManager: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    // ========================================================================
    // ASSET MANAGEMENT - Native gNode assetBundle Integration
    // ========================================================================

    /**
     * Create an asset bundle using native gNode assetBundle method
     * Combines multiple assets (CSS/JS) into optimized bundle
     *
     * @param string $bundleId Bundle identifier
     * @param array $assets Array of asset identifiers or content
     * @param string $bundleType Bundle type ('css', 'js', or 'mixed')
     * @param bool $minify Enable minification
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return array Result with bundle details
     * @throws StorageException If gNode not available or operation fails
     * @api
     */
    public function createAssetBundle(
        string $bundleId,
        array $assets,
        string $bundleType = 'mixed',
        bool $minify = true,
        ?int $ttl = null
    ): array {
        // gNode-only feature
        if (!$this->useGNode) {
            throw new StorageException(
                'Asset bundling requires gNode integration (useGNode=true)'
            );
        }

        $this->validateBundleId($bundleId);

        if (empty($assets)) {
            throw new ValidationException('Assets array cannot be empty');
        }

        try {
            $ttl = $ttl ?? $this->config['default_ttl'];

            // Use native gNode assetBundle method
            $result = $this->gNodeClient->assetBundle(
                $bundleId,
                $assets,
                $bundleType,
                $minify,
                $ttl
            );

            // Update statistics
            if ($result['success'] ?? false) {
                $this->stats['bundles_created']++;
                $this->incrementCounter('resource_bundles_created');

                // Store in registry
                $this->registry['bundles'][$bundleId] = [
                    'type' => $bundleType,
                    'minified' => $minify,
                    'created_at' => time(),
                    'result' => $result
                ];
            }

            // Record metrics if available
            if ($this->metrics) {
                $this->metrics->recordMetric('resource_bundle_created', [
                    'bundle_id' => $bundleId,
                    'type' => $bundleType,
                    'asset_count' => count($assets),
                    'minified' => $minify,
                    'success' => $result['success'] ?? false
                ]);
            }

            SelfContainedErrorHandler::logInfo(
                'ResourceManager',
                'createAssetBundle',
                "Created asset bundle using native method",
                [
                    'bundle_id' => $bundleId,
                    'type' => $bundleType,
                    'asset_count' => count($assets),
                    'minified' => $minify,
                    'success' => $result['success'] ?? false
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('create_asset_bundle', $bundleId, $e->getMessage());
            throw new StorageException(
                "Failed to create asset bundle: {$bundleId}",
                0,
                $e
            );
        }
    }

    /**
     * Load an asset by identifier
     *
     * @param string $assetId Asset identifier
     * @param bool $useCache Whether to use caching
     * @return array|null Asset data or null if not found
     * @api
     */
    public function loadAsset(string $assetId, bool $useCache = true): ?array {
        $this->validateAssetId($assetId);

        // Check in-memory registry first
        if (isset($this->registry['assets'][$assetId])) {
            $this->stats['cache_hits']++;
            $this->incrementCounter('resource_cache_hits');
            return $this->registry['assets'][$assetId];
        }

        // Check cache (if available)
        if ($useCache && $this->config['cache_enabled'] && $this->cache !== null) {
            $cacheKey = $this->getAssetCacheKey($assetId);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                $this->stats['cache_hits']++;
                $this->incrementCounter('resource_cache_hits');
                $this->registry['assets'][$assetId] = $cached;
                return $cached;
            }
        }

        $this->stats['cache_misses']++;
        $this->incrementCounter('resource_cache_misses');
        return null;
    }

    /**
     * Batch load multiple assets using gNode executeBatch
     * batched into a single round-trip vs sequential loads
     *
     * @param array $assetIds Array of asset identifiers
     * @return array Associative array of assetId => data
     * @throws StorageException If gNode not available
     * @api
     */
    public function batchLoadAssets(array $assetIds): array {
        if (empty($assetIds)) {
            return [];
        }

        if (!$this->useGNode) {
            throw new StorageException(
                'Batch operations require gNode integration (useGNode=true)'
            );
        }

        try {
            // Prepare batch commands
            $commands = [];
            foreach ($assetIds as $assetId) {
                $this->validateAssetId($assetId);

                $cacheKey = $this->getAssetCacheKey($assetId);

                $commands[] = [
                    'cmd' => 'content_retrieve',
                    'params' => [
                        'key' => $cacheKey
                    ]
                ];
            }

            // Execute batch
            $results = $this->gNodeClient->executeBatch($commands);

            // Process results
            $output = [];
            $index = 0;
            foreach ($assetIds as $assetId) {
                $result = $results[$index] ?? null;

                if (isset($result['status']) && $result['status'] === 'ok' && isset($result['result']['content'])) {
                    $output[$assetId] = $result['result'];
                    $this->stats['cache_hits']++;
                    $this->incrementCounter('resource_cache_hits');
                } else {
                    $output[$assetId] = null;
                    $this->stats['cache_misses']++;
                    $this->incrementCounter('resource_cache_misses');
                }
                $index++;
            }

            $this->stats['assets_loaded'] += count($assetIds);
            $this->incrementCounter('resource_assets_loaded', count($assetIds));

            return $output;

        } catch (\Throwable $e) {
            $this->logError('batch_load_assets', implode(',', $assetIds), $e->getMessage());
            throw new StorageException(
                "Batch load assets failed",
                0,
                $e
            );
        }
    }

    /**
     * Optimize an asset (minify, compress)
     *
     * @param string $content Asset content
     * @param string $type Asset type ('css' or 'js')
     * @param array $options Optimization options
     * @return string Optimized content
     * @api
     */
    public function optimizeAsset(string $content, string $type, array $options = []): string {
        // Use OptimizationManager if available
        if ($this->optimization && $this->config['optimization_enabled']) {
            try {
                return $this->optimization->optimize($content, $type, $options);
            } catch (\Exception $e) {
                $this->debug('Optimization failed, returning original: ' . $e->getMessage());
            }
        }

        // Basic optimization fallback
        return $this->basicOptimize($content, $type);
    }

    /**
     * Basic asset optimization (fallback)
     *
     * @param string $content Content to optimize
     * @param string $type Content type
     * @return string Optimized content
     */
    private function basicOptimize(string $content, string $type): string {
        if ($type === 'css') {
            // Remove comments
            $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
            // Remove whitespace
            $content = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $content);
        } elseif ($type === 'js') {
            // Remove single-line comments
            $content = preg_replace('!//.*!', '', $content);
            // Remove multi-line comments
            $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        }

        return trim($content);
    }

    /**
     * Invalidate an asset from cache
     *
     * @param string $assetId Asset identifier
     * @return bool Success status
     */
    public function invalidateAsset(string $assetId): bool {
        $this->validateAssetId($assetId);

        // Remove from registry
        unset($this->registry['assets'][$assetId]);

        // Remove from cache (if available)
        if ($this->cache !== null) {
            $cacheKey = $this->getAssetCacheKey($assetId);
            return $this->cache->delete($cacheKey);
        }

        return true;
    }

    // ========================================================================
    // TEMPLATE MANAGEMENT - Native gNode templateFragment Integration
    // ========================================================================

    /**
     * Store template fragment using native gNode templateFragment method
     *
     * @param string $templateId Template identifier
     * @param string $content Template content
     * @param array $dependencies Template dependencies
     * @param array $variables Template variables
     * @param int|null $ttl Time to live in seconds
     * @return array Result with success status
     * @throws StorageException If gNode not available or operation fails
     * @api
     */
    public function storeTemplateFragment(
        string $templateId,
        string $content,
        array $dependencies = [],
        array $variables = [],
        ?int $ttl = null
    ): array {
        if (!$this->useGNode) {
            throw new StorageException(
                'Template operations require gNode integration (useGNode=true)'
            );
        }

        $this->validateTemplateId($templateId);

        try {
            $ttl = $ttl ?? $this->config['default_ttl'];

            // Use native templateFragment method
            $result = $this->gNodeClient->templateFragment(
                $templateId,
                $content,
                $dependencies,
                $variables,
                $ttl
            );

            // Update registry
            if ($result['success'] ?? false) {
                $this->registry['templates'][$templateId] = [
                    'dependencies' => $dependencies,
                    'variables' => $variables,
                    'stored_at' => time(),
                    'result' => $result
                ];
            }

            SelfContainedErrorHandler::logInfo(
                'ResourceManager',
                'storeTemplateFragment',
                "Stored template fragment using native method",
                [
                    'template_id' => $templateId,
                    'dependencies' => $dependencies,
                    'size' => strlen($content),
                    'success' => $result['success'] ?? false
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('store_template_fragment', $templateId, $e->getMessage());
            throw new StorageException(
                "Failed to store template fragment: {$templateId}",
                0,
                $e
            );
        }
    }

    /**
     * Discover templates by capability using gNode geometric discovery
     *
     * @param array $capabilities Required capabilities
     * @param int $limit Maximum number of results
     * @return array Array of matching template metadata
     * @throws StorageException If gNode not available
     * @api
     */
    public function discoverTemplatesByCapability(array $capabilities, int $limit = 10): array {
        if (!$this->useGNode) {
            throw new StorageException(
                'Template discovery requires gNode integration (useGNode=true)'
            );
        }

        try {
            $result = $this->gNodeClient->discoverTemplatesByCapability($capabilities, $limit);

            SelfContainedErrorHandler::logInfo(
                'ResourceManager',
                'discoverTemplatesByCapability',
                "Discovered templates by capability",
                [
                    'capabilities' => $capabilities,
                    'limit' => $limit,
                    'found' => count($result)
                ]
            );

            return $result;

        } catch (\Throwable $e) {
            $this->logError('discover_templates', json_encode($capabilities), $e->getMessage());
            throw new StorageException(
                "Failed to discover templates by capability",
                0,
                $e
            );
        }
    }

    /**
     * Render template string with variables using gNode
     *
     * @param string $template Template string
     * @param array $variables Template variables
     * @param array $config Rendering configuration
     * @return string Rendered template
     * @throws StorageException If gNode not available
     * @api
     */
    public function renderTemplateString(
        string $template,
        array $variables = [],
        array $config = []
    ): string {
        if (!$this->useGNode) {
            throw new StorageException(
                'Template rendering requires gNode integration (useGNode=true)'
            );
        }

        try {
            $result = $this->gNodeClient->renderTemplateString($template, $variables, $config);

            $this->stats['templates_rendered']++;
            $this->incrementCounter('resource_templates_rendered');

            // Record metrics if available
            if ($this->metrics) {
                $this->metrics->recordMetric('template_rendered', [
                    'template_size' => strlen($template),
                    'variable_count' => count($variables)
                ]);
            }

            return $result;

        } catch (\Throwable $e) {
            $this->logError('render_template_string', 'template', $e->getMessage());
            throw new StorageException(
                "Failed to render template string",
                0,
                $e
            );
        }
    }

    /**
     * Get all template metadata from gNode
     *
     * @param array $config Query configuration
     * @return array Array of template metadata
     * @throws StorageException If gNode not available
     */
    public function getAllTemplateMetadata(array $config = []): array {
        if (!$this->useGNode) {
            throw new StorageException(
                'Template metadata requires gNode integration (useGNode=true)'
            );
        }

        try {
            return $this->gNodeClient->getAllTemplateMetadata($config);

        } catch (\Throwable $e) {
            $this->logError('get_all_template_metadata', 'all', $e->getMessage());
            throw new StorageException(
                "Failed to get template metadata",
                0,
                $e
            );
        }
    }

    /**
     * Get template statistics from gNode
     *
     * @return array Template statistics
     * @throws StorageException If gNode not available
     */
    public function getTemplateStatistics(): array {
        if (!$this->useGNode) {
            throw new StorageException(
                'Template statistics require gNode integration (useGNode=true)'
            );
        }

        try {
            $gNodeStats = $this->gNodeClient->getTemplateStatistics();

            // Merge with local stats
            return array_merge($gNodeStats, [
                'local' => [
                    'templates_rendered' => $this->stats['templates_rendered'],
                    'templates_in_registry' => count($this->registry['templates'])
                ]
            ]);

        } catch (\Throwable $e) {
            $this->logError('get_template_statistics', 'all', $e->getMessage());
            throw new StorageException(
                "Failed to get template statistics",
                0,
                $e
            );
        }
    }

    /**
     * Cache a rendered template
     *
     * @param string $templateId Template identifier
     * @param string $rendered Rendered content
     * @param int|null $ttl Time to live in seconds
     * @return bool Success status
     */
    public function cacheTemplate(string $templateId, string $rendered, ?int $ttl = null): bool {
        $this->validateTemplateId($templateId);

        // Store in registry regardless of cache availability
        $this->registry['templates'][$templateId] = [
            'content' => $rendered,
            'cached_at' => time()
        ];

        // Persist to cache if available
        if ($this->cache !== null) {
            $ttl = $ttl ?? $this->config['default_ttl'];
            $cacheKey = $this->getTemplateCacheKey($templateId);

            return $this->cache->set($cacheKey, [
                'content' => $rendered,
                'cached_at' => time()
            ], $ttl);
        }

        return true;
    }

    /**
     * Invalidate a template from cache
     *
     * @param string $templateId Template identifier
     * @return bool Success status
     */
    public function invalidateTemplate(string $templateId): bool {
        $this->validateTemplateId($templateId);

        // Remove from registry
        unset($this->registry['templates'][$templateId]);

        // Remove from cache (if available)
        if ($this->cache !== null) {
            $cacheKey = $this->getTemplateCacheKey($templateId);
            return $this->cache->delete($cacheKey);
        }

        return true;
    }

    // ========================================================================
    // RESOURCE LOADING
    // ========================================================================

    /**
     * Load a resource by URL or identifier
     *
     * @param string $url Resource URL or identifier
     * @param string $type Resource type
     * @return array|null Resource data or null if not found
     * @api
     */
    public function loadResource(string $url, string $type = 'auto'): ?array {
        // Auto-detect type from URL if needed
        if ($type === 'auto') {
            $type = $this->detectResourceType($url);
        }

        // Check cache first (if available)
        if ($this->config['cache_enabled'] && $this->cache !== null) {
            $cacheKey = $this->getResourceCacheKey($url);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->stats['cache_hits']++;
                $this->incrementCounter('resource_cache_hits');
                return $cached;
            }
        }

        // In a real implementation, this would fetch the resource
        // For now, return null indicating resource not found locally
        $this->stats['cache_misses']++;
        $this->incrementCounter('resource_cache_misses');
        return null;
    }

    /**
     * Preload resources for performance
     *
     * @param array $resources Array of resource URLs or identifiers
     * @return array Results for each resource
     * @api
     */
    public function preloadResources(array $resources): array {
        $results = [];

        foreach ($resources as $resource) {
            $results[$resource] = $this->loadResource($resource);
        }

        return $results;
    }

    /**
     * Setup lazy-loading for a resource
     *
     * @param string $url Resource URL
     * @param string $trigger Trigger condition
     * @return array Lazy-load configuration
     */
    public function setupLazyLoad(string $url, string $trigger = 'viewport'): array {
        return [
            'url' => $url,
            'trigger' => $trigger,
            'lazy' => true,
            'status' => 'pending'
        ];
    }

    /**
     * Get bundle manifest
     *
     * @param string $bundleId Bundle identifier
     * @return array|null Bundle manifest or null if not found
     */
    public function getBundleManifest(string $bundleId): ?array {
        return $this->registry['bundles'][$bundleId] ?? null;
    }

    /**
     * Warmup cache with resources
     *
     * @param array $resources Array of resource identifiers
     * @return int Number of resources cached
     * @api
     */
    public function warmupCache(array $resources): int {
        $cached = 0;

        foreach ($resources as $resource) {
            // This would be implemented with actual resource loading
            $cached++;
        }

        return $cached;
    }

    // ========================================================================
    // PERFORMANCE OPTIMIZATION
    // ========================================================================

    /**
     * Minify asset content
     *
     * @param string $content Content to minify
     * @param string $type Content type
     * @return string Minified content
     */
    public function minifyAsset(string $content, string $type): string {
        return $this->basicOptimize($content, $type);
    }

    /**
     * Compress bundle
     *
     * @param array $bundle Bundle data
     * @return array Compressed bundle
     */
    public function compressBundle(array $bundle): array {
        // This would implement actual compression
        return array_merge($bundle, [
            'compressed' => true,
            'original_size' => strlen(json_encode($bundle)),
            'compressed_size' => strlen(gzcompress(json_encode($bundle)))
        ]);
    }

    /**
     * Generate source map for bundle
     *
     * @param string $bundleId Bundle identifier
     * @return array|null Source map or null if not available
     */
    public function generateSourceMap(string $bundleId): ?array {
        $bundle = $this->getBundleManifest($bundleId);

        if ($bundle === null) {
            return null;
        }

        return [
            'version' => 3,
            'file' => $bundleId,
            'sourceRoot' => '',
            'sources' => [],
            'names' => [],
            'mappings' => ''
        ];
    }

    /**
     * Analyze bundle performance
     *
     * @param string $bundleId Bundle identifier
     * @return array Bundle analytics
     */
    public function analyzeBundle(string $bundleId): array {
        $bundle = $this->getBundleManifest($bundleId);

        if ($bundle === null) {
            return ['error' => 'Bundle not found'];
        }

        return [
            'bundle_id' => $bundleId,
            'type' => $bundle['type'] ?? 'unknown',
            'minified' => $bundle['minified'] ?? false,
            'created_at' => $bundle['created_at'] ?? null,
            'size_estimate' => $bundle['result']['bundled_size'] ?? 0,
            'compression_ratio' => $bundle['result']['compression_ratio'] ?? 0.0
        ];
    }

    // ========================================================================
    // VALIDATION AND HELPERS
    // ========================================================================

    /**
     * Validate bundle identifier
     *
     * @param string $bundleId Bundle identifier
     * @return void
     * @throws ValidationException If invalid
     */
    private function validateBundleId(string $bundleId): void {
        if (empty($bundleId)) {
            throw new ValidationException('Bundle ID cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $bundleId)) {
            throw new ValidationException('Bundle ID contains invalid characters');
        }
    }

    /**
     * Validate asset identifier
     *
     * @param string $assetId Asset identifier
     * @return void
     * @throws ValidationException If invalid
     */
    private function validateAssetId(string $assetId): void {
        if (empty($assetId)) {
            throw new ValidationException('Asset ID cannot be empty');
        }
    }

    /**
     * Validate template identifier
     *
     * @param string $templateId Template identifier
     * @return void
     * @throws ValidationException If invalid
     */
    private function validateTemplateId(string $templateId): void {
        if (empty($templateId)) {
            throw new ValidationException('Template ID cannot be empty');
        }
    }

    /**
     * Get cache key for asset
     *
     * @param string $assetId Asset identifier
     * @return string Cache key
     */
    private function getAssetCacheKey(string $assetId): string {
        return "resource:asset:{$this->nodeMetadata['site_id']}:{$assetId}";
    }

    /**
     * Get cache key for template
     *
     * @param string $templateId Template identifier
     * @return string Cache key
     */
    private function getTemplateCacheKey(string $templateId): string {
        return "resource:template:{$this->nodeMetadata['site_id']}:{$templateId}";
    }

    /**
     * Get cache key for resource
     *
     * @param string $url Resource URL
     * @return string Cache key
     */
    private function getResourceCacheKey(string $url): string {
        return "resource:url:{$this->nodeMetadata['site_id']}:" . md5($url);
    }

    /**
     * Detect resource type from URL
     *
     * @param string $url Resource URL
     * @return string Detected type
     */
    private function detectResourceType(string $url): string {
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));

        $typeMap = [
            'css' => 'stylesheet',
            'js' => 'script',
            'jpg' => 'image',
            'jpeg' => 'image',
            'png' => 'image',
            'gif' => 'image',
            'svg' => 'image',
            'woff' => 'font',
            'woff2' => 'font',
            'ttf' => 'font',
            'eot' => 'font'
        ];

        return $typeMap[$ext] ?? 'unknown';
    }

    /**
     * Log error
     *
     * @param string $operation Operation name
     * @param string $identifier Resource identifier
     * @param string $message Error message
     * @return void
     */
    private function logError(string $operation, string $identifier, string $message): void {
        SelfContainedErrorHandler::logErrorMessage(
            'ResourceManager',
            $operation,
            "Error with identifier '{$identifier}': {$message}",
            [
                'site_id' => $this->nodeMetadata['site_id'],
                'node_id' => $this->nodeMetadata['node_id'],
                'identifier' => $identifier
            ]
        );
    }

    /**
     * Debug logging
     *
     * @param string $message Debug message
     * @return void
     */
    private function debug(string $message): void {
        if ($this->config['debug']) {
            SelfContainedErrorHandler::logDebug(
                'ResourceManager',
                'debug',
                $message,
                [
                    'site_id' => $this->nodeMetadata['site_id'],
                    'node_id' => $this->nodeMetadata['node_id']
                ]
            );
        }
    }

    // ========================================================================
    // MODULE INTERFACE IMPLEMENTATION
    // ========================================================================

    /**
     * Get module configuration
     *
     * @return array Configuration
     */
    public function getConfig(): array {
        return $this->config;
    }

    /**
     * Update module configuration
     *
     * @param array $config New configuration
     * @return void
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);

        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'ResourceManager', (string)$key, $value);
            }
        }
    }

    /**
     * Check if module is initialized
     *
     * @return bool Initialization status
     */
    public function isInitialized(): bool {
        return $this->initialized;
    }

    /**
     * Get module status
     *
     * @return array Status information
     */
    public function getStatus(): array {
        // Determine mode based on gNode availability
        $mode = $this->useGNode ? 'full' : 'free_tier';
        $storageType = 'none';
        $storageStatus = 'unavailable';

        if ($this->cache !== null) {
            try {
                $cacheStatus = $this->cache->getStatus();
                $storageType = $cacheStatus['storage_type'] ?? 'unknown';
                $storageStatus = $cacheStatus['storage'] ?? 'available';
            } catch (\Throwable $e) {
                // Cache unavailable
            }
        }

        return [
            'initialized' => $this->initialized,
            'site_id' => $this->nodeMetadata['site_id'],
            'node_id' => $this->nodeMetadata['node_id'],
            'mode' => $mode,
            'storage_type' => $storageType,
            'storage' => $storageStatus,
            'gnode_enabled' => $this->useGNode,
            'cache_enabled' => $this->config['cache_enabled'],
            'optimization_enabled' => $this->config['optimization_enabled'],
            'statistics' => $this->stats,
            'capabilities' => $this->capabilityVector,
            'registry_counts' => [
                'assets' => count($this->registry['assets']),
                'templates' => count($this->registry['templates']),
                'bundles' => count($this->registry['bundles'])
            ]
        ];
    }

    /**
     * Get capability vector for service discovery
     *
     * @return array Capability vector
     */
    public function getCapabilityVector(): array {
        return $this->capabilityVector;
    }

    /**
     * Get resource statistics
     *
     * Returns per-request stats and, when StateManager is available,
     * accumulated distributed counters across all workers/requests.
     *
     * @return array Resource statistics
     */
    public function getStatistics(): array {
        $result = $this->stats;

        if ($this->hasStateManager()) {
            $result['distributed'] = [
                'assets_loaded' => $this->getCounter('resource_assets_loaded'),
                'templates_rendered' => $this->getCounter('resource_templates_rendered'),
                'bundles_created' => $this->getCounter('resource_bundles_created'),
                'cache_hits' => $this->getCounter('resource_cache_hits'),
                'cache_misses' => $this->getCounter('resource_cache_misses'),
            ];
        }

        return $result;
    }

    /**
     * Prevent cloning of singleton
     */
    private function __clone() {
    }

    /**
     * Prevent unserialization of singleton
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
}
