<?php
declare(strict_types=1);
/**
 * PlanningManager Interface
 *
 * Contract for planning and booking: an organisation's time grid, the people
 * and rooms with calendars, rounds of availability collected through personal
 * links, sessions, short-notice fill-in, rates and subscriptions.
 *
 * Deliberately narrow: one dispatch method and one tick. The engine behind it
 * is a composition of single-responsibility managers in the extension, which
 * grows without this public shape changing. The default stub refuses every
 * action, so a site without the extension can never appear to have taken a
 * booking or stored an availability it did not.
 *
 * @optional
 * @package     gCore
 * @subpackage  Modules\Core\Interfaces\Extensions
 * @version     1.0.0
 * @since       3.1.0
 */

namespace gCore\Modules\Core\Interfaces\Extensions;

use gCore\Modules\Core\Interfaces\ModuleInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

interface PlanningManagerInterface extends ModuleInterface
{
    /**
     * Run one action.
     *
     * @param string $siteId The organisation's site
     * @param string $action e.g. "round.create", "rules.set", "invitation.submit"
     * @param array  $input  The action's parameters
     * @param array  $actor  Who asks: ['kind' => 'planner'|'agent'|'system', 'id' => ...,
     *                       'session' => capability session for agents]
     * @return array ['ok' => bool, 'result' => mixed] or ['ok' => false, 'error' => string, 'code' => string]
     */
    public function handle(string $siteId, string $action, array $input, array $actor): array;

    /**
     * Move time-driven work forward (offer waves, reminders, deadlines). Idempotent:
     * calling it twice in a minute does the work once.
     *
     * @return array what was done, per kind
     */
    public function tick(string $siteId): array;

    /** The actions this implementation offers, for discovery and admin UIs. */
    public function actions(): array;
}
