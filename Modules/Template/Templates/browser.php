<?php
declare(strict_types=1);
/**
 * Template Browser Page
 *
 * Lists all registered templates with filtering and discovery.
 *
 * @var array $templates List of template metadata
 * @var bool $isStubMode Whether running without gNode persistence
 * @var string $siteId Current site ID
 */

if (!defined('ABSPATH')) {
    exit;
}

// Categorize templates in a single pass
$formTemplates = [];
$themeTemplates = [];
$contentTemplates = [];
foreach ($templates as $tpl) {
    if (($tpl['is_form'] ?? false) || ($tpl['category'] ?? '') === 'form') {
        $formTemplates[] = $tpl;
    } elseif (($tpl['category'] ?? '') === 'theme' || ($tpl['source'] ?? '') !== '') {
        $themeTemplates[] = $tpl;
    } else {
        $contentTemplates[] = $tpl;
    }
}
?>
<div class="wrap gcore-template-wrap">
    <h1><?php _e('Template Manager', 'gcore'); ?></h1>

    <?php if ($isStubMode): ?>
    <div class="gcore-template-stub-notice">
        <strong><?php _e('Read-Only Mode', 'gcore'); ?></strong> &mdash;
        <?php _e('Template persistence requires gNode. You can browse and preview templates, but registration and deletion are not available.', 'gcore'); ?>
    </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="gcore-template-stats">
        <div class="gcore-template-stat">
            <div class="value"><?php echo count($templates); ?></div>
            <div class="label"><?php _e('Total', 'gcore'); ?></div>
        </div>
        <div class="gcore-template-stat" style="background: linear-gradient(135deg, #28a745, #1e7e34);">
            <div class="value"><?php echo count($formTemplates); ?></div>
            <div class="label"><?php _e('Forms', 'gcore'); ?></div>
        </div>
        <div class="gcore-template-stat" style="background: linear-gradient(135deg, #6c757d, #495057);">
            <div class="value"><?php echo count($themeTemplates); ?></div>
            <div class="label"><?php _e('Theme', 'gcore'); ?></div>
        </div>
        <div class="gcore-template-stat" style="background: linear-gradient(135deg, #17a2b8, #117a8b);">
            <div class="value"><?php echo count($contentTemplates); ?></div>
            <div class="label"><?php _e('Content', 'gcore'); ?></div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="gcore-template-card">
        <div class="gcore-template-toolbar">
            <div class="gcore-template-filter">
                <label for="template-filter"><?php _e('Filter:', 'gcore'); ?></label>
                <select id="template-filter">
                    <option value="all"><?php _e('All Templates', 'gcore'); ?></option>
                    <option value="form"><?php _e('Form Templates', 'gcore'); ?></option>
                    <option value="theme"><?php _e('Theme Templates', 'gcore'); ?></option>
                    <option value="content"><?php _e('Content Templates', 'gcore'); ?></option>
                </select>
                <input type="search" id="template-search" placeholder="<?php esc_attr_e('Search templates...', 'gcore'); ?>" class="regular-text" />
            </div>
            <div>
                <button type="button" class="button" id="btn-discover-templates">
                    <?php _e('Discover Theme Templates', 'gcore'); ?>
                </button>
                <?php if (!$isStubMode): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-templates&view=edit&template=_new')); ?>" class="button button-primary">
                    <?php _e('New Template', 'gcore'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Template List -->
        <?php if (empty($templates)): ?>
        <p class="description">
            <?php _e('No templates registered yet. Click "Discover Theme Templates" to scan your theme for .tera files, or create a new template.', 'gcore'); ?>
        </p>
        <?php else: ?>
        <table class="gcore-template-table" id="template-table">
            <thead>
                <tr>
                    <th><?php _e('Name', 'gcore'); ?></th>
                    <th><?php _e('Category', 'gcore'); ?></th>
                    <th><?php _e('Type', 'gcore'); ?></th>
                    <th><?php _e('Variables', 'gcore'); ?></th>
                    <th><?php _e('Actions', 'gcore'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $tpl):
                    $name = $tpl['name'] ?? $tpl['id'] ?? 'unnamed';
                    $category = $tpl['category'] ?? 'content';
                    $isForm = $tpl['is_form'] ?? false;
                    $varCount = count($tpl['variables'] ?? []);
                    $source = $tpl['source'] ?? '';
                ?>
                <tr data-category="<?php echo esc_attr($isForm ? 'form' : ($source ? 'theme' : 'content')); ?>"
                    data-name="<?php echo esc_attr($name); ?>">
                    <td>
                        <strong>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-templates&view=edit&template=' . urlencode($name))); ?>">
                                <?php echo esc_html($name); ?>
                            </a>
                        </strong>
                    </td>
                    <td>
                        <span class="gcore-template-badge <?php echo esc_attr($category); ?>">
                            <?php echo esc_html(ucfirst($category)); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($isForm): ?>
                        <span class="gcore-template-badge form"><?php _e('Form', 'gcore'); ?></span>
                        <?php else: ?>
                        <?php _e('Content', 'gcore'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($varCount); ?></td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=gcore-templates&view=edit&template=' . urlencode($name))); ?>" class="button button-small">
                            <?php _e('View', 'gcore'); ?>
                        </a>
                        <?php if (!$isStubMode): ?>
                        <button type="button" class="button button-small button-link-delete btn-delete-template" data-name="<?php echo esc_attr($name); ?>">
                            <?php _e('Delete', 'gcore'); ?>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Discovery Results (hidden, shown after scan) -->
    <div id="discovery-results" class="gcore-template-card" style="display: none;">
        <h2><?php _e('Discovery Results', 'gcore'); ?></h2>
        <div id="discovery-content"></div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var nonce = '<?php echo wp_create_nonce('gcore_template_nonce'); ?>';
    var isStubMode = <?php echo json_encode($isStubMode); ?>;

    // Filter templates
    $('#template-filter').on('change', function() {
        var filter = $(this).val();
        $('#template-table tbody tr').each(function() {
            if (filter === 'all') {
                $(this).show();
            } else {
                $(this).toggle($(this).data('category') === filter);
            }
        });
    });

    // Search templates
    $('#template-search').on('input', function() {
        var search = $(this).val().toLowerCase();
        $('#template-table tbody tr').each(function() {
            $(this).toggle($(this).data('name').toLowerCase().indexOf(search) !== -1);
        });
    });

    // Discover theme templates
    $('#btn-discover-templates').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Scanning...', 'gcore')); ?>');

        $.post(ajaxurl, {
            action: 'gcore_template_discover',
            nonce: nonce
        }, function(response) {
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Discover Theme Templates', 'gcore')); ?>');

            if (response.success) {
                var data = response.data;
                var html = '<p><strong>' + data.discovered + '</strong> template(s) found.';
                if (!isStubMode) {
                    html += ' <strong>' + data.registered + '</strong> registered.';
                } else {
                    html += ' Registration requires gNode (Pro).';
                }
                html += '</p>';

                if (data.templates && data.templates.length > 0) {
                    html += '<ul>';
                    data.templates.forEach(function(tpl) {
                        html += '<li><strong>' + tpl.name + '</strong> (' + tpl.source + ' theme)</li>';
                    });
                    html += '</ul>';
                    if (!isStubMode) {
                        html += '<p><a href="" class="button">Reload page to see new templates</a></p>';
                    }
                }

                $('#discovery-content').html(html);
                $('#discovery-results').show();
            } else {
                alert('Discovery failed: ' + (response.data.message || 'Unknown error'));
            }
        });
    });

    // Delete template
    $('.btn-delete-template').on('click', function() {
        var name = $(this).data('name');
        if (!confirm('<?php echo esc_js(__('Delete template', 'gcore')); ?> "' + name + '"?')) {
            return;
        }

        var $row = $(this).closest('tr');

        $.post(ajaxurl, {
            action: 'gcore_template_delete',
            nonce: nonce,
            name: name
        }, function(response) {
            if (response.success) {
                $row.fadeOut(300, function() { $(this).remove(); });
            } else {
                alert(response.data.message || 'Delete failed');
            }
        });
    });
});
</script>
