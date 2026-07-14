<?php
declare(strict_types=1);
/**
 * AssetManager Interface
 *
 * Contract for CMS-agnostic asset management with manifest-driven bundling.
 * Assets are content fragments (HTML, CSS, JS) stored in ValKey.
 * Manifests define how assets are assembled into compressed bundles
 * by the gNode daemon's background builder.
 *
 * Full implementations provide gNode-backed persistent storage and bundling.
 * Free-tier stubs provide graceful in-memory-only degradation.
 *
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Core\Interfaces;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Interface AssetManagerInterface
 *
 * Defines the contract for asset storage, manifest management, and bundle retrieval.
 * Implementations use gNode-Client to communicate with the gNode daemon via stream
 * commands (asset_store, manifest_set, etc.) backed by gNode_ASSET_* Lua functions.
 */
interface AssetManagerInterface extends ModuleInterface
{
    // =========================================================================
    // ASSET CRUD
    // =========================================================================

    /**
     * Store an asset (content fragment) in ValKey
     *
     * @param string $assetId   Unique asset identifier
     * @param string $content   Asset content (HTML, CSS, JS, JSON, etc.)
     * @param string $contentType MIME type (default: text/html)
     * @param array  $options   Optional: ttl, minify (bool), gzip (bool)
     * @return array  Result with status, size, etag
     */
    public function storeAsset(string $assetId, string $content, string $contentType = 'text/html', array $options = []): array;

    /**
     * Retrieve an asset by ID
     *
     * @param string $assetId Asset identifier
     * @return array|null Asset data with content and metadata, or null if not found
     */
    public function getAsset(string $assetId): ?array;

    /**
     * Delete an asset
     *
     * @param string $assetId Asset identifier
     * @return bool True if deleted
     */
    public function deleteAsset(string $assetId): bool;

    /**
     * List assets for the current site
     *
     * @param string|null $prefix Optional prefix filter
     * @return array List of asset metadata entries
     */
    public function listAssets(?string $prefix = null): array;

    /**
     * Check if an asset exists
     *
     * @param string $assetId Asset identifier
     * @return bool True if asset exists
     */
    public function assetExists(string $assetId): bool;

    // =========================================================================
    // MANIFEST OPERATIONS
    // =========================================================================

    /**
     * Create or update a manifest
     *
     * A manifest defines how assets are assembled into a compressed bundle.
     * The gNode daemon's background builder reads manifests and produces bundles.
     *
     * Manifest structure:
     *   id           - Manifest identifier (e.g., "main")
     *   type         - "inline" | "reference" | "hybrid"
     *   version      - Semver string (e.g., "1.0.0")
     *   layout       - "cube" | "tesseract" | "grid" | "custom"
     *   slot_count   - Number of content slots
     *   slots        - Array of slot definitions
     *   sections     - Named content sections (posts, navigation, metadata)
     *   build_options - Compression, minification, TTL settings
     *
     * @param string $manifestId  Manifest identifier
     * @param array  $manifest    Manifest definition
     * @return array Result with status
     */
    public function setManifest(string $manifestId, array $manifest): array;

    /**
     * Retrieve a manifest definition
     *
     * @param string $manifestId Manifest identifier
     * @return array|null Manifest data or null if not found
     */
    public function getManifest(string $manifestId): ?array;

    /**
     * Delete a manifest
     *
     * @param string $manifestId Manifest identifier
     * @return bool True if deleted
     */
    public function deleteManifest(string $manifestId): bool;

    /**
     * List all manifest IDs for the current site
     *
     * @return array List of manifest IDs
     */
    public function listManifests(): array;

    // =========================================================================
    // BUNDLE RETRIEVAL
    // =========================================================================

    /**
     * Retrieve a built bundle
     *
     * Bundles are produced by the gNode daemon's AssetBuilder from manifests.
     * They are gzip-compressed JSON stored at {site_id}:gnode:bundle:{manifest_id}.
     *
     * @param string $manifestId  Manifest ID (default: "main")
     * @param bool   $decompress  Whether to gzdecode the result (default: true)
     * @return array|null Decoded bundle data or null if not built yet
     */
    public function getBundle(string $manifestId = 'main', bool $decompress = true): ?array;

    /**
     * Get build status metadata for a manifest's bundle
     *
     * @param string $manifestId Manifest ID
     * @return array|null Build metadata (built_at, size, compressed_size, asset_count) or null
     */
    public function getBundleStatus(string $manifestId): ?array;

    /**
     * Invalidate a bundle, triggering a rebuild on the next builder cycle
     *
     * Publishes to the site's invalidation channel so the daemon rebuilds.
     *
     * @param string $manifestId Manifest ID (default: "main")
     * @return bool True if invalidation was published
     */
    public function invalidateBundle(string $manifestId = 'main'): bool;

    // =========================================================================
    // BACKWARD COMPATIBILITY BRIDGE
    // =========================================================================

    /**
     * Sync a legacy face_mapping to both the old key and a new manifest
     *
     * This bridge method allows CMS adapters (such as gCube) to continue
     * producing their existing face_mapping structure while also creating
     * a manifest for the new asset builder.
     *
     * @param array $faceMapping Legacy face mapping array
     * @return bool True if sync succeeded
     */
    public function syncFaceMapping(array $faceMapping): bool;

    // =========================================================================
    // DISCOVERY
    // =========================================================================

    /**
     * Get the capability vector for gNode topology registration
     *
     * @return array Capability dimensions with 0.0-1.0 values
     */
    public function getCapabilityVector(): array;
}
