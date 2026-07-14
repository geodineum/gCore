<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Main;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Event Interface
 * 
 * Defines the contract for event handling systems.
 */
interface EventInterface {
    /**
     * Register event listener
     * 
     * @param string $event Event name
     * @param callable $callback Event handler
     * @param int $priority Priority (higher executes first)
     * @return bool Success status
     */
    public function addEventListener(string $event, callable $callback, int $priority = 10): bool;
    
    /**
     * Trigger event
     * 
     * @param string $event Event name
     * @param array $data Event data
     * @return array Results from all handlers
     */
    public function triggerEvent(string $event, array $data = []): array;
    
    /**
     * Remove event listener
     * 
     * @param string $event Event name
     * @param callable $callback Event handler
     * @return bool Success status
     */
    public function removeEventListener(string $event, callable $callback): bool;
}