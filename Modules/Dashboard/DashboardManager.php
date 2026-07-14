<?php
declare(strict_types=1);

namespace gCore\Modules\Dashboard;

use gCore\Modules\Core\Interfaces\ModuleInterface;

/**
 * Dashboard Module — gDash
 *
 * Marker class for gCore's Module registry. The Dashboard module is admin-only;
 * all runtime behaviour lives in Admin\DashboardAdmin (auto-loaded by
 * gcore-mu/wp-hooks.php on is_admin() requests).
 *
 * The module deliberately implements ModuleInterface but does no work in
 * initialize() — the dashboard never participates in the front-end request
 * path and has no per-request state.
 */
class DashboardManager implements ModuleInterface
{
    private static ?self $instance = null;

    private bool $initialized = false;

    private array $config = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function initialize(array $config = []): void
    {
        $this->config = $config;
        $this->initialized = true;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function getStatus(): array
    {
        return [
            'name'         => 'Dashboard',
            'version'      => '1.0.0',
            'initialized'  => $this->initialized,
            'admin_loaded' => class_exists('\\gCore\\Modules\\Dashboard\\Admin\\DashboardAdmin', false),
        ];
    }
}
