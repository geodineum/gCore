<?php
declare(strict_types=1);
/**
 * PlanningManager Stub
 *
 * Free-tier implementation. Refuses every action rather than returning a
 * plausible success: a site that believes it stored someone's availability or
 * confirmed a booking it never kept is worse than one that cannot plan at all.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.1.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\Extensions\PlanningManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

class PlanningManagerStub implements PlanningManagerInterface
{
    /** @var PlanningManagerStub|null */
    private static $instance = null;

    /** @var array */
    private $config = ['enabled' => false, 'stub_mode' => true];

    /** @var bool */
    private $initialized = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function initialize(array $config = []): void { $this->initialized = true; }

    public function getConfig(): array { return $this->config; }

    public function updateConfig(array $config): void {}

    public function isInitialized(): bool { return $this->initialized; }

    public function getStatus(): array
    {
        return ['initialized' => $this->initialized, 'stub_mode' => true,
                'message' => 'Planning requires the geodineum/gcore-planning extension'];
    }

    public function handle(string $siteId, string $action, array $input, array $actor): array
    {
        return ['ok' => false, 'code' => 'unavailable',
                'error' => 'planning is not available on this site (requires geodineum/gcore-planning)'];
    }

    public function tick(string $siteId): array { return []; }

    public function actions(): array { return []; }

    public function getCapabilityVector(): array { return ['planning' => 0.0]; }

    public function shutdown(): void { $this->initialized = false; }
}
