<?php
/**
 * gDash panel — copy-paste skeleton for extension component developers.
 *
 * This file is **not** loaded at runtime; it's a teaching artifact that
 * shows the smallest surface an extension module needs to plug into the
 * Geodineum Dashboard.
 *
 * To adapt for your own component:
 *
 *   1. Copy this file into your component's repo (e.g. myext/admin/).
 *   2. Change the namespace to match your component.
 *   3. Replace the placeholder slug + title + body with your own.
 *   4. Hook the registration into your component's bootstrap.
 *
 * See ../README.md for the full contract specification.
 */

declare(strict_types=1);

namespace Geodineum\ExampleComponent\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class ExamplePanel
{
    /**
     * Register this panel with gDash.
     *
     * Call from your component's bootstrap, hooked at `plugins_loaded`
     * priority 20 (so the dashboard class has been loaded but
     * WordPress's `admin_menu` hook (priority 10) hasn't fired yet).
     */
    public static function bootstrap(): void
    {
        // Defensive: if gDash is not present (dashboard module uninstalled,
        // gCore not loaded yet, etc.), degrade silently. Your component
        // continues to work, just without the dashboard surface.
        if (!class_exists('gDash')) {
            return;
        }

        \gDash::register_panel([
            'slug'       => 'example-overview',
            'title'      => __('Example Component', 'example-component'),
            'menu_title' => __('Example', 'example-component'),
            'callback'   => [self::class, 'render'],
            'priority'   => 60,                 // appears below built-in pages
            'capability' => 'manage_options',
        ]);
    }

    /**
     * Render the panel body. Receives no arguments. WordPress hands you
     * the .wrap div outer markup — you supply the heading and content.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient capability.', 'example-component'));
        }
        ?>
        <div class="wrap gdash">
            <h1><?php esc_html_e('Example Component', 'example-component'); ?></h1>
            <p class="description">
                <?php esc_html_e(
                    'This is a panel skeleton. Replace this body with your component\'s observability surface.',
                    'example-component'
                ); ?>
            </p>

            <div class="gdash-stat-grid">
                <div class="gdash-stat">
                    <div class="gdash-stat-label"><?php esc_html_e('Status', 'example-component'); ?></div>
                    <div class="gdash-stat-value"><?php esc_html_e('OK', 'example-component'); ?></div>
                    <div class="gdash-stat-sub"><?php esc_html_e('Last check: just now', 'example-component'); ?></div>
                </div>
                <!-- … your real stat cards here … -->
            </div>
        </div>
        <?php
    }
}

// In your real component, hook this from your main bootstrap file:
//
//   add_action('plugins_loaded', [
//       \Geodineum\ExampleComponent\Admin\ExamplePanel::class,
//       'bootstrap',
//   ], 20);
//
// (Removed from this skeleton so it doesn't accidentally register if
// the file ever gets included.)
