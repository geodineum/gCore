# gCore · CacheManager

Distributed cache over ValKey with per-site isolation and a dual free-tier / gNode mode.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

CacheManager is gCore's distributed cache. Keys are isolated per site and node (`cache:{site_id}:{node_id}:{key}`), and it runs in two modes from one class: a **free-tier** path (a `StorageFactory` adapter — WP Transients → APCu → memory) that works without gNode, and a **gNode** path (FCALL Lua, streams, pub/sub) that adds batching, content/asset storage, invalidation broadcasts, and format validation. The gNode-only methods are gated in-tree, not split into a separate stub.

## Usage

```php
$cache = $gCore->getService('cache');   // aliases: 'storage', 'CacheManager'

$cache->set('user:42:profile', $profile, 3600);   // value, TTL in seconds
$profile = $cache->get('user:42:profile');

$views = $cache->increment('page:42:views');
if (!$cache->exists('homepage:warm')) {
    $cache->setMultiple(['nav' => $nav, 'footer' => $footer], 300);
}
```

## Public API

Full generated index: [`PUBLIC_API.md` → `CacheManager`](../PUBLIC_API.md#cachemanager). At a glance:

- **Free-tier core (works without gNode)** — `set`, `get`, `delete`, `exists`, `increment`, `decrement`, `setNx`, `getMultiple`, `setMultiple`, `deleteMultiple`, `clear`, `getKeys`, `getMetrics`
- **Extension-gated (require gNode)** — `batchSet`/`batchGet`/`batchDelete`, `storeContent`/`retrieveContent`, `storeTemplate`, `storeAssetBundle`, `broadcastInvalidate`/`broadcastClearAll`/`listenForInvalidations`, native-mode toggles, format validation (`registerFormat`, `validateData`, `setWithValidation`), and the `stream*` family

## Behavior & limits

- **Real Base — full implementation.** No CacheManager Pro.
- **Free-tier methods work on ValKey/adapters alone**; the extension-gated methods throw `StorageException` when gNode is unavailable.
- **Per-site isolation** — every key is namespaced by site and node.

## Contract

Integration detail: [`CONTRACT.md` §2.3](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
