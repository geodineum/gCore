<?php
declare(strict_types=1);
/**
 * Template Editor Page
 *
 * View, edit, and preview a single template.
 *
 * @var string $templateName Template identifier
 * @var string|null $content Template content
 * @var array|null $metadata Template metadata
 * @var array $variables Template variables
 * @var bool $isStubMode Whether running without gNode persistence
 * @var string $siteId Current site ID
 */

if (!defined('ABSPATH')) {
    exit;
}

$isNew = ($templateName === '_new');
$pageTitle = $isNew ? __('New Template', 'gcore') : sprintf(__('Template: %s', 'gcore'), $templateName);
$category = $metadata['category'] ?? 'content';
$isForm = $metadata['is_form'] ?? false;
?>
<div class="wrap gcore-template-wrap">
    <h1>
        <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-templates')); ?>" class="page-title-action" style="text-decoration: none;">&larr; <?php _e('Back to Templates', 'gcore'); ?></a>
        <?php echo esc_html($pageTitle); ?>
    </h1>

    <?php if ($isStubMode): ?>
    <div class="gcore-template-stub-notice">
        <strong><?php _e('Read-Only Mode', 'gcore'); ?></strong> &mdash;
        <?php _e('Template editing and saving require gNode. You can preview templates but not persist changes.', 'gcore'); ?>
    </div>
    <?php endif; ?>

    <?php if (!$isNew && $content === null && $metadata === null): ?>
    <div class="notice notice-error">
        <p><?php printf(__('Template "%s" was not found.', 'gcore'), esc_html($templateName)); ?></p>
    </div>
    <?php else: ?>

    <div class="gcore-template-card">
        <h2><?php _e('Template Content', 'gcore'); ?></h2>

        <!-- Metadata -->
        <div class="gcore-template-meta">
            <div>
                <label for="tpl-name"><?php _e('Name', 'gcore'); ?></label><br>
                <input type="text" id="tpl-name" class="regular-text" value="<?php echo esc_attr($isNew ? '' : $templateName); ?>" <?php echo $isNew ? '' : 'readonly'; ?> />
            </div>
            <div>
                <label for="tpl-category"><?php _e('Category', 'gcore'); ?></label><br>
                <select id="tpl-category" <?php echo $isStubMode ? 'disabled' : ''; ?>>
                    <option value="content" <?php selected($category, 'content'); ?>><?php _e('Content', 'gcore'); ?></option>
                    <option value="form" <?php selected($category, 'form'); ?>><?php _e('Form', 'gcore'); ?></option>
                    <option value="theme" <?php selected($category, 'theme'); ?>><?php _e('Theme', 'gcore'); ?></option>
                    <option value="email" <?php selected($category, 'email'); ?>><?php _e('Email', 'gcore'); ?></option>
                </select>
            </div>
            <div>
                <label>
                    <input type="checkbox" id="tpl-is-form" <?php checked($isForm); ?> <?php echo $isStubMode ? 'disabled' : ''; ?> />
                    <?php _e('Is Form Template', 'gcore'); ?>
                </label>
            </div>
            <div>
                <label><?php _e('Site', 'gcore'); ?></label><br>
                <code><?php echo esc_html($siteId); ?></code>
            </div>
        </div>

        <!-- Editor -->
        <textarea id="tpl-content" class="gcore-template-editor" <?php echo $isStubMode && !$isNew ? '' : ''; ?>><?php echo esc_textarea($content ?? ''); ?></textarea>

        <!-- Actions -->
        <div class="gcore-template-actions">
            <?php if (!$isStubMode): ?>
            <button type="button" class="button button-primary" id="btn-save-template">
                <?php echo $isNew ? __('Register Template', 'gcore') : __('Save Template', 'gcore'); ?>
            </button>
            <?php if (!$isNew): ?>
            <button type="button" class="button button-link-delete" id="btn-delete-template">
                <?php _e('Delete Template', 'gcore'); ?>
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="button" id="btn-preview-template">
                <?php _e('Preview', 'gcore'); ?>
            </button>
        </div>
    </div>

    <!-- Variables -->
    <div class="gcore-template-card">
        <h2><?php _e('Template Variables', 'gcore'); ?></h2>
        <?php if (!empty($variables)): ?>
        <table class="gcore-template-table">
            <thead>
                <tr>
                    <th><?php _e('Variable', 'gcore'); ?></th>
                    <th><?php _e('Default Value', 'gcore'); ?></th>
                    <th><?php _e('Preview Value', 'gcore'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($variables as $varName => $default): ?>
                <tr>
                    <td><code>{{ <?php echo esc_html($varName); ?> }}</code></td>
                    <td><?php echo esc_html(is_string($default) ? $default : json_encode($default)); ?></td>
                    <td>
                        <input type="text" class="regular-text tpl-var-input"
                            data-var="<?php echo esc_attr($varName); ?>"
                            value="<?php echo esc_attr(is_string($default) ? $default : ''); ?>"
                            placeholder="<?php echo esc_attr($varName); ?>" />
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="description">
            <?php _e('No variables defined. Add variables in the template using {{ variable_name }} syntax, or provide sample values below for preview.', 'gcore'); ?>
        </p>
        <div id="custom-variables">
            <p>
                <label><?php _e('Custom Variables (JSON)', 'gcore'); ?></label><br>
                <textarea id="tpl-custom-vars" class="large-text" rows="3" placeholder='{"name": "World", "title": "Hello"}'>{}</textarea>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Preview -->
    <div class="gcore-template-card">
        <h2><?php _e('Rendered Preview', 'gcore'); ?></h2>
        <div class="gcore-template-preview" id="template-preview">
            <p class="description"><?php _e('Click "Preview" to render the template with the variables above.', 'gcore'); ?></p>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo wp_create_nonce('gcore_template_nonce'); ?>';
    var isNew = <?php echo json_encode($isNew); ?>;

    function getVariables() {
        var vars = {};

        // From variable inputs
        $('.tpl-var-input').each(function() {
            vars[$(this).data('var')] = $(this).val();
        });

        // From custom JSON
        var customJson = $('#tpl-custom-vars').val();
        if (customJson) {
            try {
                var custom = JSON.parse(customJson);
                $.extend(vars, custom);
            } catch (e) {
                // Ignore invalid JSON
            }
        }

        return vars;
    }

    // Preview
    $('#btn-preview-template').on('click', function() {
        var $btn = $(this);
        var template = $('#tpl-content').val();
        var variables = getVariables();

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Rendering...', 'gcore')); ?>');

        $.post(ajaxurl, {
            action: 'gcore_template_render',
            nonce: nonce,
            template: template,
            variables: JSON.stringify(variables)
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Preview', 'gcore')); ?>');

            if (response.success) {
                // Render in sandboxed iframe to prevent XSS from template content
                var iframe = document.createElement('iframe');
                iframe.sandbox = 'allow-same-origin';
                iframe.style.cssText = 'width:100%;border:none;min-height:100px;';
                var container = document.getElementById('template-preview');
                container.innerHTML = '';
                container.appendChild(iframe);
                iframe.contentDocument.open();
                iframe.contentDocument.write(response.data.rendered);
                iframe.contentDocument.close();
                // Auto-resize iframe to content height
                iframe.onload = function() {
                    iframe.style.height = iframe.contentDocument.body.scrollHeight + 'px';
                };
            } else {
                $('#template-preview').text(response.data.message || 'Render failed');
            }
        });
    });

    // Save
    $('#btn-save-template').on('click', function() {
        var $btn = $(this);
        var name = $('#tpl-name').val().trim();
        var content = $('#tpl-content').val();
        var category = $('#tpl-category').val();
        var isForm = $('#tpl-is-form').is(':checked');

        if (!name) {
            alert('<?php echo esc_js(__('Template name is required', 'gcore')); ?>');
            return;
        }
        if (!content) {
            alert('<?php echo esc_js(__('Template content is required', 'gcore')); ?>');
            return;
        }

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'gcore')); ?>');

        $.post(ajaxurl, {
            action: 'gcore_template_register',
            nonce: nonce,
            name: name,
            content: content,
            metadata: JSON.stringify({
                category: category,
                is_form: isForm
            })
        }, function(response) {
            $btn.prop('disabled', false).text(isNew ? '<?php echo esc_js(__('Register Template', 'gcore')); ?>' : '<?php echo esc_js(__('Save Template', 'gcore')); ?>');

            if (response.success) {
                if (isNew) {
                    window.location.href = '<?php echo esc_url(admin_url('admin.php?page=gcore-templates&view=edit&template=')); ?>' + encodeURIComponent(name);
                } else {
                    // Show brief success
                    $btn.text('<?php echo esc_js(__('Saved!', 'gcore')); ?>');
                    setTimeout(function() {
                        $btn.text('<?php echo esc_js(__('Save Template', 'gcore')); ?>');
                    }, 2000);
                }
            } else {
                alert(response.data.message || 'Save failed');
            }
        });
    });

    // Delete
    $('#btn-delete-template').on('click', function() {
        var name = '<?php echo esc_js($templateName); ?>';
        if (!confirm('<?php echo esc_js(__('Delete this template permanently?', 'gcore')); ?>')) {
            return;
        }

        $.post(ajaxurl, {
            action: 'gcore_template_delete',
            nonce: nonce,
            name: name
        }, function(response) {
            if (response.success) {
                window.location.href = '<?php echo esc_url(admin_url('admin.php?page=gcore-templates')); ?>';
            } else {
                alert(response.data.message || 'Delete failed');
            }
        });
    });
});
</script>
