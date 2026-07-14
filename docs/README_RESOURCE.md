# gCore · ResourceManager

Asset bundling, template-fragment handling, and asset caching via gNode.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

ResourceManager builds and loads asset bundles, handles template fragments, and caches assets through gNode. Basic caching, minification, and type detection work standalone; the bundling, batch, template, discovery, and rendering paths use the daemon.

## Usage

```php
$resources = $gCore->getService('ResourceManager');

// Bundle a set of assets, then load one back and minify another
$resources->createAssetBundle('theme-css', [
    'reset.css' => $reset,
    'main.css'  => $main,
], 'css');

$asset = $resources->loadAsset('theme-css');
$min   = $resources->optimizeAsset($css, 'css');
```

## Public API

Full generated index: [`PUBLIC_API.md` → `ResourceManager`](../PUBLIC_API.md#resourcemanager). At a glance:

- **Bundles** — `createAssetBundle`, `loadAsset`, `batchLoadAssets`, `optimizeAsset`
- **Templates** — `storeTemplateFragment`, `discoverTemplatesByCapability`, `renderTemplateString`
- **Resources** — `loadResource`, `preloadResources`, `warmupCache`

## Behavior & limits

- **Real Base — full implementation.** No ResourceManager Pro.
- **gNode-dependent methods throw `StorageException` without gNode** — bundling, batch loads, template operations, discovery, and rendering. Basic caching, minification, and type detection do not.

## Contract

Integration detail: [`CONTRACT.md` §2.9](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
