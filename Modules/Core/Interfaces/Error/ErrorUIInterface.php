<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Interfaces\Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Error UI Interface
 * 
 * Defines the contract for error UI systems.
 */
interface ErrorUIInterface {
    /**
     * Display error message
     * 
     * @param string $message Error message
     * @param array $context Additional context data
     * @return void
     */
    public function displayError(string $message, array $context = []): void;
    
    /**
     * Render error page
     * 
     * @param int $code HTTP status code
     * @param string $message Error message
     * @param array $context Additional context data
     * @return string Rendered HTML
     */
    public function renderErrorPage(int $code, string $message, array $context = []): string;
}