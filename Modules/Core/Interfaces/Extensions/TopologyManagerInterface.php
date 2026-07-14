<?php
declare(strict_types=1);
/**
 * TopologyManager Interface
 *
 * Contract for service mesh topology visualization and management
 * using gNode's geometric discovery capabilities.
 *
 * Extension implementations provide:
 * - Service mesh visualization in 3D space
 * - N-dimensional capability space (23 dimensions)
 * - Smart registration with hash-based idempotency
 * - Geometric service discovery
 * - Custom dimension registration
 * - Real-time service relationships
 *
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
 * Interface TopologyManagerInterface
 *
 * Defines the contract for service mesh topology operations.
 * Implementations may use gNode for geometric discovery (extension) or
 * provide no-op stubs for graceful degradation (default).
 */
interface TopologyManagerInterface extends ModuleInterface
{
    // =========================================================================
    // GEOMETRIC DISCOVERY METHODS
    // =========================================================================

    /**
     * Discover services based on capability requirements
     *
     * Uses geometric distance in n-dimensional capability space.
     *
     * @param array $requirements Capability requirements [dimension => minScore]
     * @param int $limit Maximum number of services to return
     * @param int $distance Maximum distance in capability space (0 = exact match)
     * @return array Discovered services with distances
     */
    public function discoverServices(array $requirements, int $limit = 10, int $distance = 0): array;

    /**
     * Find services matching requirements
     *
     * @param array $requirements Capability requirements
     * @return array Matching services
     */
    public function findServices(array $requirements): array;

    /**
     * Get service details
     *
     * @param string $serviceId Service identifier
     * @return array Service details including capabilities and metadata
     */
    public function getServiceDetails(string $serviceId): array;

    /**
     * Calculate distance between two services in capability space
     *
     * @param array $service1Capabilities First service capabilities
     * @param array $service2Capabilities Second service capabilities
     * @return array Distance information ['distance' => float, 'dimensions' => array]
     */
    public function calculateDistance(array $service1Capabilities, array $service2Capabilities): array;

    /**
     * Get optimal load sequence for services
     *
     * @param string $group Service group name
     * @return array Ordered service list
     */
    public function getLoadSequence(string $group = 'default'): array;

    /**
     * Store complete topology
     *
     * @param array $topology Topology data
     * @param int $dimensions Number of dimensions
     * @return bool Success status
     */
    public function storeTopology(array $topology, int $dimensions = 9): bool;

    // =========================================================================
    // DIMENSION MANAGEMENT
    // =========================================================================

    /**
     * Register custom dimension
     *
     * @param string $name Dimension name
     * @param array $config Dimension configuration (label, type, min, max, unit)
     * @return bool Success status
     */
    public function registerDimension(string $name, array $config = []): bool;

    /**
     * Get all registered dimensions
     *
     * @return array Dimensions with metadata
     */
    public function getDimensions(): array;

    /**
     * Get dimension by name
     *
     * @param string $name Dimension name
     * @return array|null Dimension config or null
     */
    public function getDimension(string $name): ?array;

    /**
     * Get capability dimensions from gNode
     *
     * @return array gNode capability dimensions
     */
    public function getCapabilityDimensions(): array;

    // =========================================================================
    // TOPOLOGY VISUALIZATION
    // =========================================================================

    /**
     * Get topology data for 3D visualization
     *
     * @param array $selectedDimensions Dimensions to visualize ['x' => dim, 'y' => dim, 'z' => dim]
     * @param array $filters Optional filters
     * @return array Topology visualization data with nodes, edges, and statistics
     */
    public function getTopologyVisualization(array $selectedDimensions, array $filters = []): array;

    // =========================================================================
    // SERVICE REGISTRATION
    // =========================================================================

    /**
     * Smart registration with hash-based idempotency
     *
     * Only registers with gNode if the configuration has changed.
     * Prevents unnecessary re-registration while detecting config changes.
     *
     * @deprecated Local service registration is now handled by the gNode daemon's
     *             periodic service discovery (reads geometric_topology.yaml).
     *             This method remains for remote services or CLI one-time registration only.
     *
     * @param array $capabilities Capability vector for gNode topology
     * @param array $metadata Service metadata (type, site_id, etc.)
     * @param bool $force Force registration even if hash matches
     * @return bool Success status
     */
    public function smartRegister(array $capabilities = [], array $metadata = [], bool $force = false): bool;

    /**
     * Force re-registration (clears hash and registers)
     *
     * @deprecated Local service registration is now handled by the gNode daemon's
     *             periodic service discovery (reads geometric_topology.yaml).
     *             This method remains for remote services or CLI one-time registration only.
     *
     * @param array $capabilities Optional capability override
     * @param array $metadata Optional metadata override
     * @return bool Success status
     */
    public function forceRegister(array $capabilities = [], array $metadata = []): bool;

    /**
     * Deregister current service from gNode topology
     *
     * @return bool Success status
     */
    public function deregister(): bool;

    /**
     * Update capabilities dynamically (without full re-registration)
     *
     * @deprecated Local service registration is now handled by the gNode daemon's
     *             periodic service discovery (reads geometric_topology.yaml).
     *
     * @param array $capabilities New or updated capabilities
     * @param array $metadata Optional metadata updates
     * @return bool Success status
     */
    public function updateCapabilities(array $capabilities, array $metadata = []): bool;

    /**
     * Get registration status
     *
     * @return array Registration status (registered, hash, service_id, etc.)
     */
    public function getRegistrationStatus(): array;

    /**
     * Reload configuration and re-register if changed
     *
     * @deprecated Local service registration is now handled by the gNode daemon's
     *             periodic service discovery (reads geometric_topology.yaml).
     *
     * @param callable|null $configLoader Optional callback to load fresh config
     * @return array Result with 'changed' and 'registered' status
     */
    public function refreshRegistration(?callable $configLoader = null): array;

    /**
     * Check if service is currently registered in topology
     *
     * @return bool True if service exists in gNode topology
     */
    public function isRegisteredInTopology(): bool;

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
