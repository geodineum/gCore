# TemplateManager

Unified template management system for gCore. Consolidates template registration, rendering, form handling, and security into a single coherent manager.

## Overview

TemplateManager provides template capabilities:
- **Template Registration**: Register and manage templates by string ID
- **Template Rendering**: Tera engine (when extension loaded) or PHP fallback (default-tier)
- **Form Handling**: Secure form processing with CSRF, rate limiting, honeypot
- **COMMS Integration**: Stream form submissions to COMMS for processing

## Access via gCore

```php
$gCore = \gCore\Modules\Core\gCore::getInstance();
$templates = $gCore->getService('TemplateManager');
```

## Key Methods

### Rendering

```php
// Render a template string
$html = $templates->render('Hello, {{ name }}!', ['name' => 'World']);

// Register a template
$templates->registerTemplate('my-template', $templateContent, [
    'dependencies' => [],
    'ttl' => 3600
]);
```

### Forms

```php
// Process form submission (from traits)
$result = $templates->processFormSubmission($formId, $data);

// Generate CSRF token
$token = $templates->generateCsrfToken('contact-form');
```

## Operating Modes

| Mode | Rendering | Features |
|------|-----------|----------|
| **Extension** | Tera engine via gNode | Full template syntax: loops, conditionals, inheritance, filters |
| **Free-tier** | PHP fallback | Basic `{{ variable }}` substitution |

## Capability Vector

```php
[
    'template' => 1.0,
    'rendering' => 0.95,
    'forms' => 0.9,
    'library' => 0.85,
    'tera_engine' => 0.9,
    'comms' => 0.7
]
```

## Configuration

```php
$templates->initialize([
    'site_id' => 'my-site',
    'node_id' => 'node1',
    'default_ttl' => 3600,
    'csrf_ttl' => 3600,
    'rate_limit' => [
        'submissions_per_hour' => 10,
        'submissions_per_day' => 50
    ],
    'honeypot_fields' => ['website_url', 'phone_number_2'],
    'min_submit_time' => 3
]);
```

## Built-in Templates

- `contact-form` - Standard contact form
- `newsletter-signup` - Newsletter subscription form

## Migration from TemplateLibrary/TemplateRenderer

TemplateManager replaces both TemplateLibrary and TemplateRenderer. Update your code:

```php
// Old
$library = $gCore->getService('TemplateLibrary');
$renderer = $gCore->getService('TemplateRenderer');

// New
$templates = $gCore->getService('TemplateManager');
// All functionality available through single manager
```

## Dependencies

- **Required**: None (operates in default-tier mode without dependencies)
- **Optional**: CacheManager, SecurityManager, gNode-Client

## Status

```php
$status = $templates->getStatus();
// Returns: initialized, site_id, mode, metrics, etc.
```
