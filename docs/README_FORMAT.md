# gCore · FormatManager

Data-format detection, validation, and conversion — delegated to the gNode daemon.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

FormatManager is a thin `ModuleInterface` wrapper over gNode-Client's format engine, with local registry caching. It registers named formats (schema + patterns), detects the format of a message, validates against a format, and converts between formats. It is `gnode_required` — there is **no local fallback**.

## Usage

```php
$format = $gCore->getService('format');   // aliases: 'detection', 'conversion', 'validation', 'FormatManager'

// Register a format, then detect + validate an incoming message against it
$format->registerFormat('order_v1', $schema);
$check = $format->detectAndValidate($incoming);
if ($check['valid'] ?? false) {
    $json = $format->convertFormat($incoming, 'order_v1', 'json');
}
```

## Public API

Full generated index: [`PUBLIC_API.md` → `FormatManager`](../PUBLIC_API.md#formatmanager). At a glance:

- **Registry** — `registerFormat`, `registerFormats`, `listFormats`, `getFormat`, `deleteFormat`
- **Detect & validate** — `detectFormat`, `detectFormats`, `detectAndValidate`, `validateMessage`
- **Convert** — `convertFormat`, `autoConvertFormat`

## Behavior & limits

- **Real Base — full implementation, but gNode is mandatory here.** Every operation throws `StorageException` if gNode is unavailable or `use_gnode` is false. Unlike other managers, there is no free-tier path.

## Contract

Integration detail: [`CONTRACT.md` §2.4](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
