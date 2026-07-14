# gCore · AssetManager

CMS-agnostic asset and manifest storage with daemon-built bundle retrieval.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

AssetManager stores assets and manifests independently of any CMS and retrieves bundles the gNode daemon builds in the background. It is a full production manager, not a stub. Without gNode-Client it degrades to per-request in-memory storage; bundle retrieval needs the daemon.

## Usage

```php
$assets = $gCore->getService('AssetManager');

$assets->storeAsset('logo.svg', $svg, 'image/svg+xml');
$asset = $assets->getAsset('logo.svg');

$assets->setManifest('main', ['logo.svg', 'app.js']);
$bundle = $assets->getBundle('main');   // daemon-built bundle (requires gNode)
```

## Public API

Full generated index: [`PUBLIC_API.md` → `AssetManager`](../PUBLIC_API.md#assetmanager). At a glance:

- **Assets** — `storeAsset`, `getAsset`, `deleteAsset`, `listAssets`, `assetExists`
- **Manifests** — `setManifest`, `getManifest`, `deleteManifest`, `listManifests`
- **Bundles** — `getBundle`, `getBundleStatus`, `invalidateBundle`, `syncFaceMapping`

## Behavior & limits

- **Real Base — full production, not a stub.** No AssetManager Pro.
- **In-memory-only fallback** (per request) when gNode-Client is unavailable; **bundle retrieval requires gNode**.

## Contract

Integration detail: [`CONTRACT.md` §2.10](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
