# TranslateManager

Multilingual content translation and language management.

## Overview

TranslateManager provides translation, language detection, and locale switching for multi-language sites. The stub included in core returns content untranslated and reports only the default language. Install the extension package for actual translation capabilities.

## Access

```php
$manager = $gCore->getService('TranslateManager');
```

## Methods

| Method | Signature | Returns | Description |
|--------|-----------|---------|-------------|
| `translateContent` | `(string $content, string $targetLang, ?string $sourceLang = null)` | `string` | Translate content to target language. Stub: returns input unchanged. |
| `getString` | `(string $key, ?string $lang = null)` | `string` | Get a translated string by key. Stub: returns the key as-is. |
| `getSupportedLanguages` | `()` | `array` | Map of language codes to name/native/rtl info. Stub: default language only. |
| `getAvailableLanguages` | `()` | `array` | Languages with active translations. Stub: same as supported. |
| `getCurrentLanguage` | `()` | `string` | Current active language code. |
| `setCurrentLanguage` | `(string $langCode)` | `bool` | Switch active language. Stub: returns `false`. |
| `getDefaultLanguage` | `()` | `string` | Configured default language (default: `en`). |
| `renderLanguageSwitcher` | `(array $options = [])` | `string` | HTML for a language picker. Stub: returns empty string. |
| `isAvailable` | `()` | `bool` | Whether translation is functional. Stub: `false`. |
| `getCapabilities` | `()` | `array` | Feature flags: `manual_translations`, `auto_translation`, `pre_rendered_bundles`. |
| `hasCapability` | `(string $capability)` | `bool` | Check a specific capability. Stub: always `false`. |
| `getMode` | `()` | `string` | `'stub'` or `'full'`. |
| `getStatus` | `()` | `array` | Module status including mode, language count, and upgrade message. |

## Configuration

```php
$config = [
    'default_language' => 'en',
    'site_id'          => 'default',
    'node_id'          => 'stub',
];
```

## Status

Extension tier. Base: stub implementation included (passthrough, no translation). Full version requires an external extension package.
