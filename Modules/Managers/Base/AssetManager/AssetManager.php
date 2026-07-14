<?php
declare(strict_types=1);
/**
 * AssetManager - CMS-Agnostic Asset Management with gNode-Client Backend
 *
 * Provides persistent asset storage, manifest-driven bundling, and bundle
 * retrieval via gNode stream commands backed by gNode_ASSET_* Lua functions.
 *
 * Features:
 * - Asset CRUD (store, get, delete, list, exists)
 * - Manifest management (set, get, delete, list)
 * - Bundle retrieval (getBundle reads daemon-built gzip bundles)
 * - Backward-compat bridge (syncFaceMapping writes legacy + manifest)
 * - Optional minification and compression on store
 *
 * Storage layout (ValKey):
 * - {site_id}:asset:{asset_id}              STRING: content
 * - {site_id}:asset:{asset_id}:meta         HASH: metadata
 * - {site_id}:asset:manifests               SET: manifest ID index
 * - {site_id}:asset:manifest:{manifest_id}  HASH: manifest definition
 * - {site_id}:gnode:bundle:{manifest_id}      STRING: gzip bundle (daemon-built)
 * - {site_id}:gnode:bundle:{manifest_id}:meta HASH: build metadata
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\AssetManager
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Base\AssetManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\AssetManagerInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\gNode\gNodeClient;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 6));
}

/**
 * Class AssetManager
 *
 * Full implementation of AssetManagerInterface with gNode-Client backend.
 * Communicates with the gNode daemon via stream commands:
 *   asset_store, asset_get, asset_delete, asset_list,
 *   manifest_set, manifest_get, manifest_delete, manifest_list
 */
class AssetManager implements AssetManagerInterface
{
    use ManagerConfigTrait;

    /** @var AssetManager Singleton instance */
    private static $instance = null;

    /** @var gNodeClient|null gNode-Client for ValKey operations */
    private $gNodeClient = null;

    /** @var array In-memory asset cache (per-request optimization) */
    private $assetCache = [];

    /** @var array In-memory manifest cache */
    private $manifestCache = [];

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var array Operation statistics */
    private $stats = [
        'assets_stored' => 0,
        'assets_retrieved' => 0,
        'bundles_retrieved' => 0,
        'cache_hits' => 0,
        'errors' => 0,
    ];

    /**
     * Default configuration.
     *
     * Audit fix : node_id default unified to 'node1' to match
     * every other gCore manager (was 'default' here, inconsistent
     * with the ecosystem convention — only StateManager + this file
     * diverged per the 2026-05-22 config audit).
     */
    private $defaultConfig = [
        'site_id' => 'default',
        'node_id' => 'node1',
        'default_ttl' => 0,          // 0 = no expiry for assets
        'bundle_ttl' => 300,         // 5 min for bundles
        'default_minify' => false,
        'default_gzip' => false,
        'cache_assets' => true,      // per-request in-memory cache
    ];

    /** @var array Capability vector for gNode topology */
    private $capabilityVector = [
        'asset_management' => 1.0,
        'content_bundling' => 0.9,
        'compression' => 0.8,
        'manifest_management' => 0.9,
        'persistence' => 1.0,
    ];

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
    private function __clone() {}
    public function __wakeup() { throw new \Exception("Cannot unserialize singleton"); }

    /**
     * Initialize AssetManager
     *
     * @param array $config Configuration including gnode_client injection
     */
    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        // Get gNode-Client from injection (gCore injects this automatically)
        if (isset($config['gnode_client']) && $config['gnode_client'] instanceof gNodeClient) {
            $this->gNodeClient = $config['gnode_client'];
        }

        // Layered config: defaultConfig → ValKey → $config arg
        $siteId = (string)($config['site_id'] ?? $this->defaultConfig['site_id']);
        $valkeyConfig = [];
        $storage = $this->gcoreResolveStorage($config);
        if ($storage !== null) {
            $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'AssetManager');
        }
        $this->config = array_merge($this->defaultConfig, $valkeyConfig, $config);

        $this->initialized = true;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mode = $this->gNodeClient ? 'distributed' : 'fallback';
            error_log("[gCore] AssetManager initialized in {$mode} mode for site: {$this->config['site_id']}");
        }
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

        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'AssetManager', (string)$key, $value);
            }
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'stub_mode' => false,
            'mode' => $this->gNodeClient ? 'distributed' : 'local_fallback',
            'storage_type' => $this->gNodeClient ? 'valkey' : 'memory',
            'gnode_available' => $this->gNodeClient !== null,
            'gnode_connected' => $this->gNodeClient ? $this->gNodeClient->isConnected() : false,
            'cached_assets' => count($this->assetCache),
            'cached_manifests' => count($this->manifestCache),
            'stats' => $this->stats,
            'site_id' => $this->config['site_id'],
            'node_id' => $this->config['node_id'],
            'capabilities' => $this->capabilityVector,
        ];
    }

    // =========================================================================
    // ASSET CRUD
    // =========================================================================

    /**
     * Store an asset
     * @api
     */
    public function storeAsset(string $assetId, string $content, string $contentType = 'text/html', array $options = []): array
    {
        $params = [
            'key' => $assetId,
            'content' => $content,
            'content_type' => $contentType,
        ];

        if (isset($options['ttl'])) {
            $params['ttl'] = (int) $options['ttl'];
        } elseif ($this->config['default_ttl'] > 0) {
            $params['ttl'] = $this->config['default_ttl'];
        }

        $params['minify'] = $options['minify'] ?? $this->config['default_minify'];
        $params['gzip'] = $options['gzip'] ?? $this->config['default_gzip'];

        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->executeCommand('asset_store', $params);

                if ($result && isset($result['status']) && $result['status'] === 'ok') {
                    $this->stats['assets_stored']++;

                    // Update cache
                    if ($this->config['cache_assets']) {
                        $this->assetCache[$assetId] = [
                            'content' => $content,
                            'content_type' => $contentType,
                            'metadata' => $result,
                        ];
                    }

                    return $result;
                }

                return $result ?? ['status' => 'error', 'error' => 'No response from gNode'];
            } catch (\Throwable $e) {
                $this->logError('storeAsset failed', $e);
                $this->stats['errors']++;
                return ['status' => 'error', 'error' => $e->getMessage()];
            }
        }

        // Fallback: in-memory only
        $this->assetCache[$assetId] = [
            'content' => $content,
            'content_type' => $contentType,
            'metadata' => [
                'size' => strlen($content),
                'stored_at' => time(),
            ],
        ];
        $this->stats['assets_stored']++;

        return ['status' => 'ok', 'size' => strlen($content)];
    }

    /**
     * Retrieve an asset
     * @api
     */
    public function getAsset(string $assetId): ?array
    {
        // Check in-memory cache first
        if ($this->config['cache_assets'] && isset($this->assetCache[$assetId])) {
            $this->stats['cache_hits']++;
            $this->stats['assets_retrieved']++;
            return $this->assetCache[$assetId];
        }

        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->executeCommand('asset_get', [
                    'key' => $assetId,
                    'decompress' => true,
                ]);

                if ($result && isset($result['status']) && $result['status'] === 'ok') {
                    $this->stats['assets_retrieved']++;

                    // Cache the result
                    if ($this->config['cache_assets']) {
                        $this->assetCache[$assetId] = $result;
                    }

                    return $result;
                }

                return null;
            } catch (\Throwable $e) {
                $this->logError('getAsset failed', $e);
                $this->stats['errors']++;
                return null;
            }
        }

        return null;
    }

    /**
     * Delete an asset
     * @api
     */
    public function deleteAsset(string $assetId): bool
    {
        // Remove from cache
        unset($this->assetCache[$assetId]);

        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->executeCommand('asset_delete', [
                    'key' => $assetId,
                ]);

                return $result && isset($result['status']) && $result['status'] === 'ok';
            } catch (\Throwable $e) {
                $this->logError('deleteAsset failed', $e);
                $this->stats['errors']++;
                return false;
            }
        }

        return true;
    }

    /**
     * List assets
     * @api
     */
    public function listAssets(?string $prefix = null): array
    {
        if ($this->gNodeClient) {
            try {
                $params = [];
                if ($prefix !== null) {
                    $params['prefix'] = $prefix;
                }

                $result = $this->gNodeClient->executeCommand('asset_list', $params);

                if ($result && isset($result['assets'])) {
                    return $result['assets'];
                }

                return $result ?? [];
            } catch (\Throwable $e) {
                $this->logError('listAssets failed', $e);
                $this->stats['errors']++;
                return [];
            }
        }

        // Fallback: return cached asset keys
        $keys = array_keys($this->assetCache);
        if ($prefix !== null) {
            $keys = array_filter($keys, function ($k) use ($prefix) {
                return strpos($k, $prefix) === 0;
            });
        }
        return array_values($keys);
    }

    /**
     * Check if asset exists
     * @api
     */
    public function assetExists(string $assetId): bool
    {
        if (isset($this->assetCache[$assetId])) {
            return true;
        }

        if ($this->gNodeClient) {
            try {
                $key = "{$this->config['site_id']}:asset:{$assetId}";
                return (bool) $this->gNodeClient->luaExists($key);
            } catch (\Throwable $e) {
                $this->logError('assetExists failed', $e);
                return false;
            }
        }

        return false;
    }

    // =========================================================================
    // MANIFEST OPERATIONS
    // =========================================================================

    /**
     * Create or update a manifest
     * @api
     */
    public function setManifest(string $manifestId, array $manifest): array
    {
        // Validate required manifest fields
        $required = ['type', 'layout', 'slot_count', 'slots'];
        foreach ($required as $field) {
            if (!isset($manifest[$field])) {
                return ['status' => 'error', 'error' => "Missing required field: {$field}"];
            }
        }

        // Ensure manifest has an ID
        $manifest['id'] = $manifestId;

        if (!isset($manifest['version'])) {
            $manifest['version'] = '1.0.0';
        }

        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->executeCommand('manifest_set', [
                    'manifest_id' => $manifestId,
                    'manifest' => $manifest,
                ]);

                if ($result && isset($result['status']) && $result['status'] === 'ok') {
                    $this->manifestCache[$manifestId] = $manifest;
                    return $result;
                }

                return $result ?? ['status' => 'error', 'error' => 'No response from gNode'];
            } catch (\Throwable $e) {
                $this->logError('setManifest failed', $e);
                $this->stats['errors']++;
                return ['status' => 'error', 'error' => $e->getMessage()];
            }
        }

        // Fallback: in-memory only
        $this->manifestCache[$manifestId] = $manifest;
        return ['status' => 'ok'];
    }

    /**
     * Retrieve a manifest
     * @api
     */
    public function getManifest(string $manifestId): ?array
    {
        // Check cache
        if (isset($this->manifestCache[$manifestId])) {
            return $this->manifestCache[$manifestId];
        }

        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->executeCommand('manifest_get', [
                    'manifest_id' => $manifestId,
                ]);

                if ($result && isset($result['status']) && $result['status'] === 'ok') {
                    // Parse JSON fields back to arrays
                    $manifest = $result;
                    foreach (['slots', 'sections', 'build_options'] as $jsonField) {
                        $key = $jsonField . '_json';
                        if (isset($manifest[$key]) && is_string($manifest[$key])) {
                            $manifest[$jsonField] = json_decode($manifest[$key], true);
                            unset($manifest[$key]);
                        }
                    }

                    $this->manifestCache[$manifestId] = $manifest;
                    return $manifest;
                }

                return null;
            } catch (\Throwable $e) {
                $this->logError('getManifest failed', $e);
                $this->stats['errors']++;
                return null;
            }
        }

        return null;
    }

    /**
     * Delete a manifest
     * @api
     */
    public function deleteManifest(string $manifestId): bool
    {
        unset($this->manifestCache[$manifestId]);

        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->executeCommand('manifest_delete', [
                    'manifest_id' => $manifestId,
                ]);

                return $result && isset($result['status']) && $result['status'] === 'ok';
            } catch (\Throwable $e) {
                $this->logError('manifest_delete failed', $e);
                $this->stats['errors']++;
                return false;
            }
        }

        return true;
    }

    /**
     * List manifest IDs
     * @api
     */
    public function listManifests(): array
    {
        if ($this->gNodeClient) {
            try {
                $result = $this->gNodeClient->executeCommand('manifest_list', []);

                if ($result && isset($result['manifests'])) {
                    return $result['manifests'];
                }

                return $result ?? [];
            } catch (\Throwable $e) {
                $this->logError('manifest_list failed', $e);
                $this->stats['errors']++;
                return [];
            }
        }

        return array_keys($this->manifestCache);
    }

    // =========================================================================
    // BUNDLE RETRIEVAL
    // =========================================================================

    /**
     * Retrieve a built bundle
     *
     * Bundles are gzip-compressed JSON built by the gNode daemon's AssetBuilder.
     * For backward compatibility, "full" and "main" both check legacy key first.
     * @api
     */
    public function getBundle(string $manifestId = 'main', bool $decompress = true): ?array
    {
        if (!$this->gNodeClient) {
            return null;
        }

        try {
            $siteId = $this->config['site_id'];

            // Determine bundle key - support legacy "full" alias
            $bundleKey = ($manifestId === 'main' || $manifestId === 'full')
                ? "{$siteId}:gnode:bundle:full"
                : "{$siteId}:gnode:bundle:{$manifestId}";

            $raw = $this->gNodeClient->luaGet($bundleKey);

            if ($raw === null || $raw === false) {
                // Try the alternate key if we used legacy
                if ($manifestId === 'main') {
                    $raw = $this->gNodeClient->luaGet("{$siteId}:gnode:bundle:main");
                } elseif ($manifestId === 'full') {
                    $raw = $this->gNodeClient->luaGet("{$siteId}:gnode:bundle:main");
                }

                if ($raw === null || $raw === false) {
                    return null;
                }
            }

            $this->stats['bundles_retrieved']++;

            if ($decompress) {
                $decoded = @gzdecode($raw);
                if ($decoded === false) {
                    // Not gzipped, try as plain JSON
                    $decoded = $raw;
                }

                $data = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }

                // Return raw if not JSON
                return ['content' => $decoded];
            }

            return ['raw' => $raw, 'compressed' => true];
        } catch (\Throwable $e) {
            $this->logError('getBundle failed', $e);
            $this->stats['errors']++;
            return null;
        }
    }

    /**
     * Get build status for a manifest's bundle
     * @api
     */
    public function getBundleStatus(string $manifestId): ?array
    {
        if (!$this->gNodeClient) {
            return null;
        }

        try {
            $siteId = $this->config['site_id'];
            $metaKey = "{$siteId}:gnode:bundle:{$manifestId}:meta";
            $meta = $this->gNodeClient->luaHGetAll($metaKey);

            if (!$meta || empty($meta)) {
                return null;
            }

            return $meta;
        } catch (\Throwable $e) {
            $this->logError('getBundleStatus failed', $e);
            return null;
        }
    }

    /**
     * Invalidate a bundle to trigger rebuild
     * @api
     */
    public function invalidateBundle(string $manifestId = 'main'): bool
    {
        if (!$this->gNodeClient) {
            return false;
        }

        try {
            $siteId = $this->config['site_id'];
            $channel = "{$siteId}:events:invalidate";
            $message = json_encode([
                'type' => 'bundle_invalidate',
                'manifest_id' => $manifestId,
                'timestamp' => time(),
            ]);

            // Publish to the invalidation channel that the AssetBuilder listens on
            $this->gNodeClient->luaSet($channel, $message, 60);
            return true;
        } catch (\Throwable $e) {
            $this->logError('invalidateBundle failed', $e);
            return false;
        }
    }

    // =========================================================================
    // BACKWARD COMPATIBILITY BRIDGE
    // =========================================================================

    /**
     * Sync legacy face_mapping to both old key and new manifest
     *
     * This is the bridge method that CMS adapters (such as gCube) call
     * to support both old bundle_builder and new manifest-driven AssetBuilder.
     *
     * The face_mapping is:
     *   1. Written to the legacy key ({site_id}:gnode:face_mapping) as before
     *   2. Converted to a manifest and written via setManifest('main', ...)
     *
     * The AssetBuilder's build_compat_bundle() reads face_mapping directly,
     * while build_from_manifest() reads the manifest. Either path produces
     * the same bundle at {site_id}:gnode:bundle:full.
     * @api
     */
    public function syncFaceMapping(array $faceMapping): bool
    {
        if (!$this->gNodeClient) {
            return false;
        }

        try {
            $siteId = $this->config['site_id'];

            // Step 1: Write legacy key (preserves backward compat)
            $faceMappingJson = json_encode($faceMapping);
            $legacyKey = "{$siteId}:gnode:face_mapping";
            $this->gNodeClient->luaSet($legacyKey, $faceMappingJson, 0);

            // Step 2: Convert face_mapping to manifest
            $manifest = $this->convertFaceMappingToManifest($faceMapping);
            if ($manifest) {
                $this->setManifest('main', $manifest);
            }

            // Step 3: Trigger rebuild
            $this->invalidateBundle('main');

            return true;
        } catch (\Throwable $e) {
            $this->logError('syncFaceMapping failed', $e);
            $this->stats['errors']++;
            return false;
        }
    }

    /**
     * Convert a legacy face_mapping array to a manifest structure
     *
     * Supports both cube (6-face) and tesseract (8-cell) layouts.
     */
    private function convertFaceMappingToManifest(array $faceMapping): ?array
    {
        $faces = $faceMapping['faces'] ?? [];
        $faceCount = count($faces);

        if ($faceCount === 0) {
            return null;
        }

        // Determine layout from face count
        $layout = $faceCount <= 6 ? 'cube' : 'tesseract';

        // Build slots from faces
        $slots = [];
        foreach ($faces as $index => $face) {
            $slot = [
                'id' => "face_{$index}",
                'position' => $this->getFacePosition($index, $layout),
                'content_type' => 'text/html',
            ];

            // Face content can be inline HTML or a reference
            if (isset($face['html'])) {
                $slot['content'] = $face['html'];
            } elseif (isset($face['content'])) {
                $slot['content'] = $face['content'];
            }

            if (isset($face['css'])) {
                $slot['css'] = $face['css'];
            }
            if (isset($face['js'])) {
                $slot['js'] = $face['js'];
            }
            if (isset($face['source'])) {
                $slot['source'] = $face['source'];
            }

            $slots[] = $slot;
        }

        // Build sections from additional mapping data
        $sections = [];

        if (isset($faceMapping['posts'])) {
            $sections['posts'] = [
                'source' => 'inline',
                'data' => $faceMapping['posts'],
            ];
        }

        if (isset($faceMapping['navigation'])) {
            $sections['navigation'] = [
                'source' => 'inline',
                'data' => $faceMapping['navigation'],
            ];
        }

        if (isset($faceMapping['metadata'])) {
            $sections['metadata'] = [
                'source' => 'inline',
                'data' => $faceMapping['metadata'],
            ];
        }

        // Optional bundles section (tesseract layouts)
        if (isset($faceMapping['bundles'])) {
            $sections['bundles'] = [
                'source' => 'inline',
                'data' => $faceMapping['bundles'],
            ];
        }

        return [
            'id' => 'main',
            'type' => 'inline',
            'version' => '1.0.0',
            'layout' => $layout,
            'slot_count' => $faceCount,
            'slots' => $slots,
            'sections' => $sections,
            'build_options' => [
                'compress' => true,
                'compression_level' => 9,
                'minify' => false,
                'ttl' => $this->config['bundle_ttl'],
            ],
        ];
    }

    /**
     * Map face index to position name
     */
    private function getFacePosition(int $index, string $layout): string
    {
        if ($layout === 'cube') {
            $positions = ['front', 'right', 'back', 'left', 'top', 'bottom'];
        } else {
            // Tesseract cell positions
            $positions = ['cell_0', 'cell_1', 'cell_2', 'cell_3', 'cell_4', 'cell_5', 'cell_6', 'cell_7'];
        }

        return $positions[$index] ?? "slot_{$index}";
    }

    // =========================================================================
    // DISCOVERY
    // =========================================================================

    /**
     * Get capability vector for gNode topology
     */
    public function getCapabilityVector(): array
    {
        return $this->capabilityVector;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Log error with consistent format
     */
    private function logError(string $message, \Throwable $e): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[gCore AssetManager] {$message}: {$e->getMessage()}");
        }
    }
}
