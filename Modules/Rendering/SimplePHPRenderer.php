<?php
declare(strict_types=1);
/**
 * SimplePHPRenderer - Minimal PHP Template Renderer
 *
 * Free-tier template renderer providing basic variable substitution.
 * This is intentionally limited to encourage extension installs.
 *
 * Features (Default Tier):
 * - {{ variable }} substitution
 * - HTML escaping helper
 * - renderString() for inline templates
 *
 * Extension-only (requires gNode with Tera):
 * - Template inheritance ({% extends %}, {% block %})
 * - Control flow ({% for %}, {% if %})
 * - Filters ({{ var | filter }})
 * - Macros and includes
 * - Template compilation and caching
 *
 * @package     gCore
 * @subpackage  Rendering
 * @version     1.0.0
 */

namespace gCore\Modules\Rendering;

if (!defined('ABSPATH')) {
    if (!defined('GCORE_STANDALONE')) {
        define('GCORE_STANDALONE', true);
    }
}

/**
 * SimplePHPRenderer
 *
 * Minimal template renderer for default-tier gCore installations.
 * Provides basic variable substitution without advanced features.
 */
class SimplePHPRenderer
{
    /**
     * Render metrics
     * @var array
     */
    private $metrics = [
        'renders' => 0,
        'substitutions' => 0
    ];

    /**
     * Render a template string with variable substitution
     *
     * Supports only {{ variable }} syntax.
     * No loops, conditionals, inheritance, or filters.
     *
     * @param string $template Template content with {{ variable }} placeholders
     * @param array $variables Associative array of variable => value
     * @return string Rendered output with variables substituted
     */
    public function render(string $template, array $variables = []): string
    {
        $this->metrics['renders']++;
        $substitutions = 0;

        // Simple pattern: {{ variable_name }}
        // Allows alphanumeric, underscore, and dot for nested access
        $rendered = preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_\.]*)\s*\}\}/',
            function ($matches) use ($variables, &$substitutions) {
                $key = $matches[1];
                $substitutions++;

                // Handle dotted notation (e.g., user.name)
                if (strpos($key, '.') !== false) {
                    return $this->resolveNestedVariable($key, $variables);
                }

                // Simple variable lookup
                return array_key_exists($key, $variables)
                    ? $this->escapeOutput($variables[$key])
                    : '';
            },
            $template
        );

        $this->metrics['substitutions'] += $substitutions;

        return $rendered;
    }

    /**
     * Render a string template (alias for render)
     *
     * @param string $template Template string
     * @param array $variables Variables for substitution
     * @return string Rendered output
     */
    public function renderString(string $template, array $variables = []): string
    {
        return $this->render($template, $variables);
    }

    /**
     * Escape HTML entities for safe output
     *
     * @param string $string String to escape
     * @return string Escaped string
     */
    public function escapeHtml(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Check if this renderer is available
     *
     * @return bool Always true for PHP renderer
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Get the renderer type
     *
     * @return string Renderer type identifier
     */
    public function getType(): string
    {
        return 'php';
    }

    /**
     * Get rendering metrics
     *
     * @return array Render statistics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Reset metrics
     *
     * @return void
     */
    public function resetMetrics(): void
    {
        $this->metrics = [
            'renders' => 0,
            'substitutions' => 0
        ];
    }

    /**
     * Resolve nested variable using dot notation
     *
     * @param string $key Dot-separated key (e.g., "user.profile.name")
     * @param array $variables Variables array
     * @return string Resolved value or empty string
     */
    private function resolveNestedVariable(string $key, array $variables): string
    {
        $parts = explode('.', $key);
        $value = $variables;

        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return '';
            }
        }

        return $this->escapeOutput($value);
    }

    /**
     * Escape output value for HTML
     *
     * @param mixed $value Value to escape
     * @return string Safe output string
     */
    private function escapeOutput($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '[Object]';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Get information about renderer capabilities
     *
     * @return array Capability information
     */
    public function getCapabilities(): array
    {
        return [
            'variable_substitution' => true,
            'nested_variables' => true,
            'html_escaping' => true,
            'loops' => false,        // Extension-only
            'conditionals' => false, // Extension-only
            'inheritance' => false,  // Extension-only
            'filters' => false,      // Extension-only
            'macros' => false,       // Extension-only
            'includes' => false,     // Extension-only
            'compilation' => false,  // Extension-only
            'tier' => 'free'
        ];
    }
}
