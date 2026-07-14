<?php
declare(strict_types=1);
/**
 * TemplateManager Stub
 *
 * Graceful no-op implementation for default tier.
 * Provides basic PHP variable substitution without Tera engine.
 * Form security features operate with in-memory storage.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Stubs
 * @version     1.0.0
 * @since       3.0.0
 */

namespace gCore\Modules\Managers\Stubs;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Interfaces\Extensions\TemplateManagerInterface;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 4));
}

/**
 * Class TemplateManagerStub
 *
 * Free-tier stub implementation of TemplateManagerInterface.
 * Provides basic template rendering without Tera engine features.
 */
class TemplateManagerStub implements TemplateManagerInterface
{
    /** @var TemplateManagerStub Singleton instance */
    private static $instance = null;

    /** @var array Configuration settings */
    private $config = [];

    /** @var bool Initialization state */
    private $initialized = false;

    /** @var bool Whether upgrade notice has been logged */
    private static $upgradeNoticeLogged = false;

    /** @var mixed CacheManager for cross-request CSRF persistence */
    private $cacheManager = null;

    /** @var array Default configuration */
    private $defaultConfig = [
        'enabled' => true,
        'stub_mode' => true,
        'site_id' => 'default',
        'node_id' => 'stub',
        'csrf_ttl' => 3600,
        'min_submit_time' => 3,
    ];

    /** @var array Capability vector (minimal for stub) */
    private $capabilityVector = [
        'template' => 0.3,
        'rendering' => 0.3,
        'forms' => 0.4,
        'library' => 0.0,       // No persistence without gNode
        'tera_engine' => 0.0,
        'comms' => 0.0
    ];

    /** @var array Metrics tracking */
    private $metrics = [
        'templates_registered' => 0,
        'templates_fetched' => 0,
        'renders' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'form_submissions' => 0,
        'csrf_failures' => 0,
        'rate_limit_hits' => 0
    ];

    /** @var int Spam score threshold */
    private $spamThreshold = 50;

    /** @var array Disposable email domains */
    private $disposableEmailDomains = [];

    /** @var array Honeypot fields */
    private $honeypotFields = ['website_url', 'phone_number_2', 'company_fax'];

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
        $this->honeypotFields = $config['honeypot_fields'] ?? $this->honeypotFields;

        // Get CacheManager for cross-request CSRF token persistence
        try {
            $core = \gCore\Modules\Core\gCore::getInstance();
            $this->cacheManager = $core->getService('CacheManager');
        } catch (\Throwable $e) {
            // CacheManager not available yet during early init — CSRF will degrade gracefully
        }
        $this->initialized = true;

        $this->logUpgradeNotice();
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
            if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) { error_log('[gCore] TemplateManager stub active - the gcore-templates extension provides Tera engine'); }
        }
    }

    // =========================================================================
    // TEMPLATE RENDERING (basic PHP substitution)
    // =========================================================================

    /**
     * Render a template string with basic variable substitution
     */
    public function render(string $template, array $variables = []): string
    {
        $this->metrics['renders']++;
        $output = $template;

        foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
                $output = str_replace('{{ ' . $key . ' }}', htmlspecialchars((string)$value), $output);
                $output = str_replace('{{' . $key . '}}', htmlspecialchars((string)$value), $output);
            }
        }

        return $output;
    }

    /**
     * Render a string template (alias for render)
     */
    public function renderString(string $template, array $variables = []): string
    {
        return $this->render($template, $variables);
    }

    /**
     * Escape HTML entities for safe output
     */
    public function escapeHtml(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // =========================================================================
    // TEMPLATE LIBRARY (stub — no persistence without gNode)
    // =========================================================================

    /**
     * Register a template — requires gNode for persistence
     *
     * In stub mode, templates cannot be stored across requests.
     * Returns a clear error so callers know to upgrade.
     */
    public function registerTemplate(string $name, string $content, array $metadata = []): array
    {
        return [
            'success' => false,
            'error' => 'Template registration requires gNode (gcore-templates)',
            'stub_mode' => true,
        ];
    }

    /**
     * Get template content — always null in stub mode (no persistence)
     */
    public function getTemplate(string $name): ?string
    {
        return null;
    }

    /**
     * Get template metadata — always null in stub mode (no persistence)
     */
    public function getTemplateMetadata(string $name): ?array
    {
        return null;
    }

    /**
     * List all templates — always empty in stub mode
     */
    public function listTemplates(array $filters = []): array
    {
        return [];
    }

    /**
     * Get available templates — always empty in stub mode
     */
    public function getAvailableTemplates(?string $category = null): array
    {
        return [];
    }

    /**
     * Delete a template — always false in stub mode (nothing to delete)
     */
    public function deleteTemplate(string $name): bool
    {
        return false;
    }

    /**
     * Check if template is a form — always false in stub mode
     */
    public function isFormTemplate(string $name): bool
    {
        return false;
    }

    /**
     * Check if template exists — always false in stub mode
     */
    public function templateExists(string $name): bool
    {
        return false;
    }

    /**
     * Get template variables — always empty in stub mode
     */
    public function getTemplateVariables(string $name): array
    {
        return [];
    }

    // =========================================================================
    // FORM HANDLING (basic CSRF)
    // =========================================================================

    /**
     * Generate CSRF token — stored in CacheManager for cross-request persistence
     */
    public function generateCSRFToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl = $this->config['csrf_ttl'] ?? 3600;
        $siteId = $this->config['site_id'] ?? 'default';

        if ($this->cacheManager) {
            try {
                $this->cacheManager->set("csrf:{$siteId}:{$token}", '1', $ttl);
            } catch (\Throwable $e) {
                error_log("[gCore] TemplateManagerStub::generateCSRFToken cache failed: {$e->getMessage()}");
            }
        }

        return $token;
    }

    /**
     * Validate a CSRF token — checks CacheManager, single-use (deleted after validation)
     */
    public function validateCSRFToken(string $token): bool
    {
        $siteId = $this->config['site_id'] ?? 'default';
        $key = "csrf:{$siteId}:{$token}";

        if (!$this->cacheManager) {
            return false;
        }

        try {
            $exists = $this->cacheManager->exists($key);
            if ($exists) {
                $this->cacheManager->delete($key);
                return true;
            }
        } catch (\Throwable $e) {
            error_log("[gCore] TemplateManagerStub::validateCSRFToken failed: {$e->getMessage()}");
        }

        return false;
    }

    /**
     * Generate secure CSRF token
     */
    public function generateSecureCSRFToken(): string
    {
        return $this->generateCSRFToken();
    }

    // =========================================================================
    // FORM SECURITY (basic checks)
    // =========================================================================

    /**
     * Calculate spam score
     */
    public function calculateSpamScore(array $data, array $metadata = []): array
    {
        $score = 0;
        $reasons = [];

        // Check honeypots
        foreach ($this->honeypotFields as $field) {
            if (!empty($data[$field])) {
                $score += 100;
                $reasons[] = "Honeypot triggered: {$field}";
            }
        }

        // Check timing
        $formLoadTime = $metadata['form_load_time'] ?? null;
        $minTime = $this->config['min_submit_time'] ?? 3;

        if ($formLoadTime) {
            $elapsed = time() - (int)$formLoadTime;
            if ($elapsed < $minTime) {
                $score += 30;
                $reasons[] = "Submission too fast: {$elapsed}s";
            }
        }

        return [
            'score' => min($score, 100),
            'reasons' => $reasons,
            'is_spam' => $score >= $this->spamThreshold,
        ];
    }

    /**
     * Validate form security
     */
    public function validateFormSecurity(array $data, array $metadata = []): array
    {
        // Validate CSRF token if present
        $csrfToken = $data['_csrf_token'] ?? null;
        if ($csrfToken !== null && !$this->validateCSRFToken($csrfToken)) {
            return [
                'allowed' => false,
                'reason' => 'Invalid or expired CSRF token',
                'spam_score' => ['score' => 100, 'is_spam' => true, 'reasons' => ['csrf_invalid']]
            ];
        }

        $spamResult = $this->calculateSpamScore($data, $metadata);

        return [
            'allowed' => !$spamResult['is_spam'],
            'reason' => $spamResult['is_spam'] ? 'Spam detected' : null,
            'spam_score' => $spamResult
        ];
    }

    /**
     * Generate honeypot fields HTML
     */
    public function generateHoneypotFieldsHtml(): string
    {
        $html = '<div style="display:none !important;" aria-hidden="true">';
        foreach ($this->honeypotFields as $field) {
            $html .= "<input type=\"text\" name=\"{$field}\" tabindex=\"-1\">";
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Generate security JavaScript
     */
    public function generateSecurityJavaScript(string $formId): string
    {
        $timestamp = time();
        return <<<JS
<script>
(function() {
    var form = document.getElementById('{$formId}');
    if (!form) return;
    var loadTimeField = form.querySelector('input[name="_form_load_time"]');
    if (loadTimeField && !loadTimeField.value) {
        loadTimeField.value = {$timestamp};
    }
})();
</script>
JS;
    }

    /**
     * Generate security fields HTML
     */
    public function generateSecurityFieldsHtml(string $csrfToken): string
    {
        $timestamp = time();
        return <<<HTML
<input type="hidden" name="_csrf_token" value="{$csrfToken}">
<input type="hidden" name="_form_load_time" value="{$timestamp}">
HTML;
    }

    /**
     * Deep sanitize form data
     */
    public function deepSanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (strpos($key, '_') === 0) {
                $sanitized[$key] = $value;
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->deepSanitize($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = htmlspecialchars(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Detect XSS attempts
     */
    public function detectXSS(string $input): bool
    {
        $patterns = [
            '/<script\b[^>]*>/i',
            '/javascript\s*:/i',
            '/on\w+\s*=/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add disposable email domains
     */
    public function addDisposableEmailDomains(array $domains): void
    {
        $this->disposableEmailDomains = array_merge(
            $this->disposableEmailDomains,
            array_map('strtolower', $domains)
        );
    }

    /**
     * Set spam threshold
     */
    public function setSpamThreshold(int $threshold): void
    {
        $this->spamThreshold = max(0, min(100, $threshold));
    }

    /**
     * Get client security config
     */
    public function getClientSecurityConfig(): array
    {
        return [
            'honeypot_fields' => $this->honeypotFields,
            'min_submit_time' => $this->config['min_submit_time'] ?? 3,
        ];
    }

    /**
     * Log security event
     */
    public function logSecurityEvent(string $eventType, array $context = []): void
    {
        if (\gCore\Modules\Core\Utils\SelfContainedErrorHandler::shouldLog('info')) {
            error_log("[gCore] Security event: {$eventType} - " . json_encode($context));
        }
    }

    // =========================================================================
    // STATUS AND METRICS
    // =========================================================================

    public function getSiteId(): string
    {
        return $this->config['site_id'] ?? 'default';
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

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
            'gnode_available' => false,
            'tera_engine' => false,
            'templates_registered' => 0,
            'metrics' => $this->metrics,
            'site_id' => $this->config['site_id'] ?? 'default',
            'node_id' => $this->config['node_id'] ?? 'stub',
            'upgrade_message' => 'The gcore-templates extension provides Tera templating and COMMS integration',
        ];
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
