<?php
declare(strict_types=1);
/**
 * TemplateManager Interface
 *
 * Contract for unified template management including:
 * - Template rendering (Tera engine or PHP fallback)
 * - Template library with registration and discovery
 * - Form handling with CSRF, rate limiting, honeypot
 * - COMMS stream integration
 *
 * Extension implementations provide full Tera templating via gNode.
 * Default stubs provide basic PHP variable substitution.
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
 * Interface TemplateManagerInterface
 *
 * Defines the contract for template management operations.
 */
interface TemplateManagerInterface extends ModuleInterface
{
    // =========================================================================
    // TEMPLATE RENDERING
    // =========================================================================

    /**
     * Render a template string with variable substitution
     *
     * @param string $template Template content with placeholders
     * @param array $variables Associative array of variables
     * @return string Rendered output
     */
    public function render(string $template, array $variables = []): string;

    /**
     * Render a string template (alias for render)
     *
     * @param string $template Template string
     * @param array $variables Variables for substitution
     * @return string Rendered output
     */
    public function renderString(string $template, array $variables = []): string;

    /**
     * Escape HTML entities for safe output
     *
     * @param string $string String to escape
     * @return string Escaped string
     */
    public function escapeHtml(string $string): string;

    // =========================================================================
    // TEMPLATE LIBRARY
    // =========================================================================

    /**
     * Register a template with the system
     *
     * @param string $name Template identifier
     * @param string $content Template content
     * @param array $metadata Optional metadata (category, is_form, variables, etc.)
     * @return array Result with ['success' => bool, 'id' => string, 'mode' => string]
     */
    public function registerTemplate(string $name, string $content, array $metadata = []): array;

    /**
     * Get template content by name
     *
     * @param string $name Template identifier
     * @return string|null Template content or null if not found
     */
    public function getTemplate(string $name): ?string;

    /**
     * Get template metadata by name
     *
     * @param string $name Template identifier
     * @return array|null Template metadata or null if not found
     */
    public function getTemplateMetadata(string $name): ?array;

    /**
     * List all available templates
     *
     * @param array $filters Optional filters (category, is_form, etc.)
     * @return array List of template metadata
     */
    public function listTemplates(array $filters = []): array;

    /**
     * Get available templates (alias for listTemplates with category filter)
     *
     * @param string|null $category Optional category filter
     * @return array Available templates
     */
    public function getAvailableTemplates(?string $category = null): array;

    /**
     * Delete a template
     *
     * @param string $name Template identifier
     * @return bool Success status
     */
    public function deleteTemplate(string $name): bool;

    /**
     * Check if a template is a form template
     *
     * @param string $name Template identifier
     * @return bool
     */
    public function isFormTemplate(string $name): bool;

    /**
     * Check if a template exists
     *
     * @param string $name Template identifier
     * @return bool
     */
    public function templateExists(string $name): bool;

    /**
     * Get template variables with defaults
     *
     * @param string $name Template identifier
     * @return array Variables array
     */
    public function getTemplateVariables(string $name): array;

    // =========================================================================
    // FORM HANDLING
    // =========================================================================

    /**
     * Generate a new CSRF token
     *
     * @return string CSRF token
     */
    public function generateCSRFToken(): string;

    /**
     * Generate secure CSRF token using SecurityManager
     *
     * @return string Cryptographically secure token
     */
    public function generateSecureCSRFToken(): string;

    // =========================================================================
    // FORM SECURITY
    // =========================================================================

    /**
     * Calculate spam score for a form submission
     *
     * @param array $data Sanitized form data
     * @param array $metadata Request metadata (IP, timing, etc.)
     * @return array ['score' => int, 'reasons' => array, 'is_spam' => bool]
     */
    public function calculateSpamScore(array $data, array $metadata = []): array;

    /**
     * Full security validation for form submission
     *
     * @param array $data Form data
     * @param array $metadata Request metadata
     * @return array ['allowed' => bool, 'reason' => string|null, 'spam_score' => array]
     */
    public function validateFormSecurity(array $data, array $metadata = []): array;

    /**
     * Generate honeypot fields HTML for templates
     *
     * @return string HTML for honeypot fields
     */
    public function generateHoneypotFieldsHtml(): string;

    /**
     * Generate JavaScript security component for templates
     *
     * @param string $formId Form element ID
     * @return string JavaScript code
     */
    public function generateSecurityJavaScript(string $formId): string;

    /**
     * Generate hidden security fields HTML for templates
     *
     * @param string $csrfToken CSRF token
     * @return string HTML for hidden security fields
     */
    public function generateSecurityFieldsHtml(string $csrfToken): string;

    /**
     * Deep sanitize form data
     *
     * @param array $data Raw form data
     * @return array Sanitized data
     */
    public function deepSanitize(array $data): array;

    /**
     * Check for XSS attempts in input
     *
     * @param string $input Raw input
     * @return bool True if XSS detected
     */
    public function detectXSS(string $input): bool;

    /**
     * Add additional disposable email domains
     *
     * @param array $domains Array of domain names
     */
    public function addDisposableEmailDomains(array $domains): void;

    /**
     * Set spam threshold
     *
     * @param int $threshold Score threshold (0-100)
     */
    public function setSpamThreshold(int $threshold): void;

    /**
     * Get security configuration for JavaScript security component
     *
     * @return array Configuration for client-side security
     */
    public function getClientSecurityConfig(): array;

    /**
     * Log security event
     *
     * @param string $eventType Event type
     * @param array $context Event context
     */
    public function logSecurityEvent(string $eventType, array $context = []): void;

    // =========================================================================
    // STATUS AND METRICS
    // =========================================================================

    /**
     * Get site ID
     *
     * @return string
     */
    public function getSiteId(): string;

    /**
     * Get metrics
     *
     * @return array Metrics data
     */
    public function getMetrics(): array;

    /**
     * Get capability vector for service discovery
     *
     * @return array Capability vector
     */
    public function getCapabilityVector(): array;
}
