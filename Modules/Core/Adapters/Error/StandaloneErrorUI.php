<?php
declare(strict_types=1);
namespace gCore\Modules\Core\Adapters\Error;

if (!defined('ABSPATH')) exit('No direct script access allowed');

use gCore\Modules\Core\Interfaces\Error\ErrorUIInterface;

class StandaloneErrorUI implements ErrorUIInterface {
    /** @var array Configuration */
    private $config;
    
    /** @var string Template path */
    private $template_path;
    
    /** @var array UI settings */
    private $ui_settings = [
        'show_details' => true,
        'theme' => 'light',
        'max_errors' => 10
    ];
    
    /**
     * Constructor
     */
    public function __construct(array $config = []) {
        $this->config = array_merge([
            'template_path' => __DIR__ . '/templates',
            'render_errors' => true
        ], $config);
        
        $this->template_path = $this->config['template_path'];
        
        if (isset($config['ui_settings'])) {
            $this->ui_settings = array_merge($this->ui_settings, $config['ui_settings']);
        }
    }
    
    /**
     * Register customizer settings
     */
    public function registerCustomizerSettings($wp_customize): void {
        // Create settings only if WP Customizer is available
        if (!($wp_customize instanceof \WP_Customize_Manager)) {
            return;
        }
        
        // Add error section
        $wp_customize->add_section('gcore_error_section', [
            'title' => 'Error Handling',
            'priority' => 120
        ]);
        
        // Add error display setting
        $wp_customize->add_setting('gcore_error_display', [
            'default' => $this->ui_settings['show_details'],
            'transport' => 'refresh'
        ]);
        
        $wp_customize->add_control('gcore_error_display', [
            'label' => 'Show Error Details',
            'section' => 'gcore_error_section',
            'type' => 'checkbox'
        ]);
        
        // Add error theme setting
        $wp_customize->add_setting('gcore_error_theme', [
            'default' => $this->ui_settings['theme'],
            'transport' => 'refresh'
        ]);
        
        $wp_customize->add_control('gcore_error_theme', [
            'label' => 'Error Theme',
            'section' => 'gcore_error_section',
            'type' => 'select',
            'choices' => [
                'light' => 'Light',
                'dark' => 'Dark',
                'system' => 'System Default'
            ]
        ]);
    }
    
    /**
     * Render error page
     */
    public function renderErrorPage(array $stats): void {
        $theme = $this->ui_settings['theme'];
        $show_details = $this->ui_settings['show_details'];
        $errors = array_slice($stats['errors'] ?? [], 0, $this->ui_settings['max_errors']);
        
        // Don't render if disabled
        if (!$this->config['render_errors']) {
            return;
        }
        
        // Output error page
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=utf-8');
        
        // Include template if it exists
        $template_file = "{$this->template_path}/error-page.php";
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            // Fallback inline template
            echo $this->getFallbackErrorTemplate($errors, $theme, $show_details);
        }
        
        // Stop further execution
        exit;
    }
    
    /**
     * Add error reporting admin page
     */
    public function addErrorReportingPage(string $capability): void {
        // Only add admin page if we're in a WP context
        if (!function_exists('add_menu_page')) {
            return;
        }
        
        add_submenu_page(
            'gcore-dashboard',
            'Error Reports',
            'Error Reports',
            $capability,
            'gcore-error-reports',
            [$this, 'renderErrorReportingPage']
        );
    }
    
    /**
     * Render error reporting admin page
     * This is used internally by addErrorReportingPage
     */
    public function renderErrorReportingPage(): void {
        // Check permissions first
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Include admin page template
        $template_file = "{$this->template_path}/error-admin.php";
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            // Fallback message
            echo '<div class="wrap"><h1>Error Reports</h1><p>Error reporting template not found.</p></div>';
        }
    }
    
    /**
     * Get fallback error template
     * Used when the template file is not available
     */
    private function getFallbackErrorTemplate(array $errors, string $theme, bool $show_details): string {
        $bg_color = ($theme === 'dark') ? '#222' : '#f8f8f8';
        $text_color = ($theme === 'dark') ? '#eee' : '#333';
        $border_color = ($theme === 'dark') ? '#444' : '#ddd';
        
        $error_html = '';
        if ($show_details && !empty($errors)) {
            $error_html .= '<div class="error-details">';
            foreach ($errors as $error) {
                $message = htmlspecialchars($error['message'] ?? 'Unknown error');
                $file = htmlspecialchars($error['file'] ?? 'Unknown');
                $line = $error['line'] ?? 'Unknown';
                
                $error_html .= "<div class='error-item'>";
                $error_html .= "<h3>{$message}</h3>";
                $error_html .= "<p>File: {$file}, Line: {$line}</p>";
                $error_html .= "</div>";
            }
            $error_html .= '</div>';
        }
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Occurred</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: {$text_color};
            background: {$bg_color};
            padding: 20px;
            margin: 0;
        }
        .error-container {
            max-width: 800px;
            margin: 40px auto;
            background: {$bg_color};
            border: 1px solid {$border_color};
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            margin-top: 0;
            color: #c00;
        }
        .error-details {
            margin-top: 30px;
            border-top: 1px solid {$border_color};
            padding-top: 20px;
        }
        .error-item {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid {$border_color};
        }
        .error-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Error Occurred</h1>
        <p>An error has occurred while processing your request.</p>
        <p>Please try again later or contact the administrator if the problem persists.</p>
        {$error_html}
    </div>
</body>
</html>
HTML;
    }
}