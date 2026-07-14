<?php
declare(strict_types=1);
/**
 * Geodineum Template Admin Interface
 *
 * WordPress admin pages for managing templates.
 * Follows CommsAdmin pattern: singleton, AJAX handlers, submenu under gcore-dashboard.
 *
 * Works in dual mode:
 * - Pro: Full CRUD via TemplateManagerPro (ValKey-backed persistence)
 * - Stub: Read-only browser, write operations return "requires gNode"
 *
 * @package gCore
 * @subpackage Modules\Template\Admin
 */

namespace gCore\Modules\Template\Admin;

use gCore\Modules\Core\Interfaces\Extensions\TemplateManagerInterface;

class TemplateAdmin
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var TemplateManagerInterface */
    private TemplateManagerInterface $manager;

    /** @var string Current site ID */
    private string $siteId;

    /** @var bool Whether running in stub mode (no persistence) */
    private bool $isStubMode;

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize admin
     */
    private function __construct()
    {
        $this->manager = $this->resolveTemplateManager();
        $this->siteId = $this->manager->getSiteId();

        // Detect stub mode from capability vector
        $capabilities = $this->manager->getCapabilityVector();
        $this->isStubMode = ($capabilities['library'] ?? 0) < 0.1;

        // Register admin hooks
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // AJAX handlers
        add_action('wp_ajax_gcore_template_list', [$this, 'ajaxListTemplates']);
        add_action('wp_ajax_gcore_template_get', [$this, 'ajaxGetTemplate']);
        add_action('wp_ajax_gcore_template_register', [$this, 'ajaxRegisterTemplate']);
        add_action('wp_ajax_gcore_template_delete', [$this, 'ajaxDeleteTemplate']);
        add_action('wp_ajax_gcore_template_render', [$this, 'ajaxRenderTemplate']);
        add_action('wp_ajax_gcore_template_discover', [$this, 'ajaxDiscoverThemeTemplates']);
    }

    /**
     * Resolve the active TemplateManager implementation
     */
    private function resolveTemplateManager(): TemplateManagerInterface
    {
        // Try Pro first
        $proClass = '\gCore\Template\TemplateManagerPro';
        if (class_exists($proClass)) {
            $pro = $proClass::getInstance();
            if ($pro && $pro->isInitialized()) {
                return $pro;
            }
        }

        // Fall back to Stub
        $stubClass = '\gCore\Modules\Managers\Stubs\TemplateManagerStub';
        return $stubClass::getInstance();
    }

    /**
     * Register admin menus
     */
    public function registerMenus(): void
    {
        add_submenu_page(
            'gcore-dashboard',
            __('Templates', 'gcore'),
            __('Templates', 'gcore'),
            'manage_options',
            'gcore-templates',
            [$this, 'renderBrowser']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, 'gcore-templates')) {
            return;
        }

        wp_add_inline_style('wp-admin', $this->getAdminStyles());
    }

    /**
     * Render the template browser page
     */
    public function renderBrowser(): void
    {
        $view = sanitize_text_field($_GET['view'] ?? 'list');
        $templateName = sanitize_text_field($_GET['template'] ?? '');

        if ($view === 'edit' && $templateName) {
            $this->renderEditor($templateName);
            return;
        }

        $templates = $this->manager->listTemplates();
        $isStubMode = $this->isStubMode;
        $siteId = $this->siteId;

        include __DIR__ . '/../Templates/browser.php';
    }

    /**
     * Render the template editor page
     */
    private function renderEditor(string $templateName): void
    {
        $content = $this->manager->getTemplate($templateName);
        $metadata = $this->manager->getTemplateMetadata($templateName);
        $variables = $this->manager->getTemplateVariables($templateName);
        $isStubMode = $this->isStubMode;
        $siteId = $this->siteId;

        include __DIR__ . '/../Templates/editor.php';
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: List all templates
     */
    public function ajaxListTemplates(): void
    {
        check_ajax_referer('gcore_template_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $category = sanitize_text_field($_POST['category'] ?? '');
        $filters = $category ? ['category' => $category] : [];

        try {
            $templates = $this->manager->listTemplates($filters);
            wp_send_json_success([
                'templates' => $templates,
                'count' => count($templates),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Get single template
     */
    public function ajaxGetTemplate(): void
    {
        check_ajax_referer('gcore_template_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $name = sanitize_text_field($_POST['name'] ?? '');
        if (!$name) {
            wp_send_json_error(['message' => 'Template name required']);
        }

        try {
            $content = $this->manager->getTemplate($name);
            $metadata = $this->manager->getTemplateMetadata($name);
            $variables = $this->manager->getTemplateVariables($name);

            if ($content === null && $metadata === null) {
                wp_send_json_error(['message' => 'Template not found']);
            }

            wp_send_json_success([
                'name' => $name,
                'content' => $content,
                'metadata' => $metadata,
                'variables' => $variables,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Register/update a template (Pro only)
     */
    public function ajaxRegisterTemplate(): void
    {
        check_ajax_referer('gcore_template_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $name = sanitize_text_field($_POST['name'] ?? '');
        $content = wp_unslash($_POST['content'] ?? '');
        $metadataJson = wp_unslash($_POST['metadata'] ?? '{}');

        // GC-D2.08 (Commit 1.2.d): cap input size + decode depth before
        // json_decode. See CommsAdmin::ajaxSaveSettings for rationale.
        if (strlen($metadataJson) > 65536) {
            wp_send_json_error(['message' => 'Metadata payload too large (max 64 KiB)']);
        }
        $metadata = json_decode($metadataJson, true, 32) ?: [];

        if (!$name || !$content) {
            wp_send_json_error(['message' => 'Template name and content are required']);
        }

        try {
            $result = $this->manager->registerTemplate($name, $content, $metadata);

            if ($result['success'] ?? false) {
                wp_send_json_success([
                    'message' => 'Template registered',
                    'result' => $result,
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['error'] ?? 'Registration failed',
                ]);
            }
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Delete a template (Pro only)
     */
    public function ajaxDeleteTemplate(): void
    {
        check_ajax_referer('gcore_template_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $name = sanitize_text_field($_POST['name'] ?? '');
        if (!$name) {
            wp_send_json_error(['message' => 'Template name required']);
        }

        try {
            $deleted = $this->manager->deleteTemplate($name);

            if ($deleted) {
                wp_send_json_success(['message' => 'Template deleted']);
            } else {
                wp_send_json_error([
                    'message' => $this->isStubMode
                        ? 'Template deletion requires gNode (Pro)'
                        : 'Template not found or could not be deleted',
                    ]);
            }
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Preview render a template with sample variables
     */
    public function ajaxRenderTemplate(): void
    {
        check_ajax_referer('gcore_template_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $template = wp_unslash($_POST['template'] ?? '');
        $variablesJson = wp_unslash($_POST['variables'] ?? '{}');
        $variables = json_decode($variablesJson, true) ?: [];

        if (!$template) {
            wp_send_json_error(['message' => 'Template content required']);
        }

        try {
            $rendered = $this->manager->render($template, $variables);
            wp_send_json_success([
                'rendered' => $rendered,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Discover templates from theme directories
     */
    public function ajaxDiscoverThemeTemplates(): void
    {
        check_ajax_referer('gcore_template_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        try {
            $discovered = [];
            $registered = 0;

            // Scan parent theme templates
            $parentDir = get_template_directory() . '/templates';
            if (is_dir($parentDir)) {
                $discovered = array_merge($discovered, $this->scanTeraFiles($parentDir, 'parent'));
            }

            // Scan child theme templates (if different from parent)
            $childDir = get_stylesheet_directory() . '/templates';
            if ($childDir !== $parentDir && is_dir($childDir)) {
                $discovered = array_merge($discovered, $this->scanTeraFiles($childDir, 'child'));
            }

            // Register discovered templates (Pro mode only — stub will return error signals)
            foreach ($discovered as $tpl) {
                $result = $this->manager->registerTemplate(
                    $tpl['name'],
                    $tpl['content'],
                    [
                        'category' => 'theme',
                        'source' => $tpl['source'],
                        'file' => $tpl['file'],
                    ]
                );
                if ($result['success'] ?? false) {
                    $registered++;
                }
            }

            wp_send_json_success([
                'discovered' => count($discovered),
                'registered' => $registered,
                'templates' => array_map(fn($t) => [
                    'name' => $t['name'],
                    'source' => $t['source'],
                    'file' => $t['file'],
                ], $discovered),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Scan a directory for .tera template files
     */
    private function scanTeraFiles(string $directory, string $source): array
    {
        $templates = [];
        $files = glob($directory . '/*.tera');

        if (!$files) {
            return [];
        }

        foreach ($files as $file) {
            $basename = basename($file, '.tera');
            $content = file_get_contents($file);

            if ($content !== false) {
                $templates[] = [
                    'name' => $basename,
                    'content' => $content,
                    'source' => $source,
                    'file' => $file,
                ];
            }
        }

        return $templates;
    }

    /**
     * Get admin CSS styles
     */
    private function getAdminStyles(): string
    {
        return <<<CSS
.gcore-template-wrap {
    max-width: 1200px;
}
.gcore-template-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
}
.gcore-template-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e5e5;
}
.gcore-template-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.gcore-template-stat {
    background: linear-gradient(135deg, #2271b1, #135e96);
    color: #fff;
    padding: 20px;
    border-radius: 4px;
    text-align: center;
}
.gcore-template-stat .value {
    font-size: 2em;
    font-weight: bold;
}
.gcore-template-stat .label {
    opacity: 0.9;
}
.gcore-template-table {
    width: 100%;
    border-collapse: collapse;
}
.gcore-template-table th,
.gcore-template-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #e5e5e5;
}
.gcore-template-table th {
    background: #f9f9f9;
}
.gcore-template-table tr:hover {
    background: #f9f9f9;
}
.gcore-template-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
}
.gcore-template-badge.form {
    background: #d4edda;
    color: #155724;
}
.gcore-template-badge.content {
    background: #cce5ff;
    color: #004085;
}
.gcore-template-badge.theme {
    background: #e2e3e5;
    color: #383d41;
}
.gcore-template-badge.stub {
    background: #fff3cd;
    color: #856404;
}
.gcore-template-editor {
    width: 100%;
    min-height: 300px;
    font-family: monospace;
    font-size: 13px;
    padding: 10px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    resize: vertical;
}
.gcore-template-preview {
    background: #f9f9f9;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    min-height: 100px;
}
.gcore-template-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 15px;
}
.gcore-template-meta label {
    font-weight: 600;
}
.gcore-template-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}
.gcore-template-stub-notice {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    padding: 12px 20px;
    margin-bottom: 20px;
    color: #856404;
}
.gcore-template-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.gcore-template-filter {
    display: flex;
    gap: 10px;
    align-items: center;
}
CSS;
    }
}

// Auto-initialize if in WordPress admin
if (is_admin() && defined('ABSPATH')) {
    add_action('plugins_loaded', function() {
        TemplateAdmin::getInstance();
    }, 20);
}
