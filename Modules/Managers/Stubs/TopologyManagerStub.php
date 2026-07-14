<?php
declare(strict_types=1);
/**
 * TopologyManager Stub
 *
 * Graceful no-op implementation for default tier.
 * Provides all TopologyManagerInterface methods but returns empty/stub responses.
 * No actual gNode integration or topology visualization without the matching extension.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\TopologyManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class TopologyManagerStub
 *
 * Free-tier stub implementation of TopologyManagerInterface.
 * All topology methods return empty arrays or default values.
 */
class TopologyManagerStub implements TopologyManagerInterface
{
    /** @var TopologyManagerStub Singleton instance */
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

    /** @var array Default dimensions (minimal stub set) */
    private $dimensions = [];

    /** @var array Capability vector (minimal for stub) */
    private $capabilityVector = [
        'topology_visualization' => 0.0,
        'service_discovery' => 0.0,
        'mesh_management' => 0.0,
        'dimension_analysis' => 0.0,
        'relationship_mapping' => 0.0
    ];

    /** @var string Stub error message */
    private const STUB_ERROR = 'Topology management requires gcore-topology full with gNode backend';

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
        $this->initializeDefaultDimensions();
        $this->initialized = true;

        $this->logUpgradeNotice();
    }

    /**
     * Initialize minimal default dimensions
     */
    private function initializeDefaultDimensions(): void
    {
        // Provide minimal dimension info for display purposes
        $defaultDims = [
            'protocol', 'native_format', 'api_version', 'contract_stability',     // 0-3
            'clearance_required', 'auth_method', 'data_sensitivity', 'service_scope', // 4-7
            'domain_primary', 'domain_secondary', 'specialization',                    // 8-10
            'throughput_tier', 'latency_class', 'reliability_tier',                    // 11-13
            'pipeline_stage', 'execution_priority', 'current_load',                    // 14-16
            'service_tier', 'environment',                                             // 17-18
            'user_x', 'user_y', 'user_z',                                             // 19-21
            'registration_order'                                                       // 22
        ];

        foreach ($defaultDims as $index => $name) {
            $this->dimensions[$name] = [
                'index' => $index,
                'name' => $name,
                'label' => ucwords(str_replace('_', ' ', $name)),
                'type' => 'capability',
                'min' => 0.0,
                'max' => 1.0,
                'unit' => 'score',
                'custom' => false,
                'stub_mode' => true,
            ];
        }
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
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] TopologyManager stub active - the gcore-topology extension provides service mesh visualization'); }
        }
    }

    // =========================================================================
    // GEOMETRIC DISCOVERY METHODS (all return empty)
    // =========================================================================

    /**
     * Discover services (stub: returns empty)
     */
    public function discoverServices(array $requirements, int $limit = 10, int $distance = 0): array
    {
        return [];
    }

    /**
     * Find services (stub: returns empty)
     */
    public function findServices(array $requirements): array
    {
        return [];
    }

    /**
     * Get service details (stub: returns empty)
     */
    public function getServiceDetails(string $serviceId): array
    {
        return [
            'error' => self::STUB_ERROR,
            'stub_mode' => true,
        ];
    }

    /**
     * Calculate distance (stub: returns zero distance)
     */
    public function calculateDistance(array $service1Capabilities, array $service2Capabilities): array
    {
        return [
            'distance' => 0,
            'dimensions' => [],
            'stub_mode' => true,
        ];
    }

    /**
     * Get load sequence (stub: returns empty)
     */
    public function getLoadSequence(string $group = 'default'): array
    {
        return [];
    }

    /**
     * Store topology (stub: returns false)
     */
    public function storeTopology(array $topology, int $dimensions = 9): bool
    {
        return false;
    }

    // =========================================================================
    // DIMENSION MANAGEMENT (returns minimal stub data)
    // =========================================================================

    /**
     * Register dimension (stub: returns false)
     */
    public function registerDimension(string $name, array $config = []): bool
    {
        return false;
    }

    /**
     * Get dimensions (stub: returns default dimension list)
     */
    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    /**
     * Get dimension (stub: returns dimension if exists)
     */
    public function getDimension(string $name): ?array
    {
        return $this->dimensions[$name] ?? null;
    }

    /**
     * Get capability dimensions (stub: returns empty)
     */
    public function getCapabilityDimensions(): array
    {
        return [];
    }

    // =========================================================================
    // TOPOLOGY VISUALIZATION (stub data for UI display)
    // =========================================================================

    /**
     * Get topology visualization (stub: returns empty visualization)
     */
    public function getTopologyVisualization(array $selectedDimensions, array $filters = []): array
    {
        return [
            'nodes' => [],
            'edges' => [],
            'current_node' => null,
            'dimensions' => [
                'x' => $this->getDimension($selectedDimensions['x'] ?? 'protocol'),
                'y' => $this->getDimension($selectedDimensions['y'] ?? 'throughput_tier'),
                'z' => $this->getDimension($selectedDimensions['z'] ?? 'current_load'),
            ],
            'statistics' => [
                'total_services' => 0,
                'total_relationships' => 0,
                'current_service' => null,
            ],
            'stub_mode' => true,
            'upgrade_message' => 'The gcore-topology extension provides 3D service mesh visualization',
        ];
    }

    // =========================================================================
    // SERVICE REGISTRATION (all return false/stub status)
    // =========================================================================

    /**
     * Smart register (stub: returns false)
     */
    public function smartRegister(array $capabilities = [], array $metadata = [], bool $force = false): bool
    {
        return false;
    }

    /**
     * Force register (stub: returns false)
     */
    public function forceRegister(array $capabilities = [], array $metadata = []): bool
    {
        return false;
    }

    /**
     * Deregister (stub: returns false)
     */
    public function deregister(): bool
    {
        return false;
    }

    /**
     * Update capabilities (stub: returns false)
     */
    public function updateCapabilities(array $capabilities, array $metadata = []): bool
    {
        return false;
    }

    /**
     * Get registration status (stub: not registered)
     */
    public function getRegistrationStatus(): array
    {
        return [
            'registered' => false,
            'hash' => null,
            'registered_at' => null,
            'service_id' => null,
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'stub_mode' => true,
        ];
    }

    /**
     * Refresh registration (stub: returns not changed)
     */
    public function refreshRegistration(?callable $configLoader = null): array
    {
        return [
            'changed' => false,
            'registered' => false,
            'previous_hash' => null,
            'new_hash' => null,
            'stub_mode' => true,
        ];
    }

    /**
     * Is registered in topology (stub: returns false)
     */
    public function isRegisteredInTopology(): bool
    {
        return false;
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
            'current_service_id' => null,
            'dimensions_active' => count($this->dimensions),
            'statistics' => [
                'services_registered' => 0,
                'services_discovered' => 0,
                'dimensions_active' => count($this->dimensions),
                'topology_updates' => 0,
                'cache_hits' => 0,
                'cache_misses' => 0,
            ],
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'upgrade_message' => 'The gcore-topology extension provides service mesh visualization and gNode integration',
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
