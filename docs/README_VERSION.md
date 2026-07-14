# gCore · VersionManager

Version tracking and cache invalidation, multi-tenant.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

VersionManager tracks version counters per group and per tenant, and drives cache invalidation off them. It uses gNode atomic `INCR`/`DECR` when available and falls back through a storage chain otherwise.

## Usage

```php
use gCore\Modules\Managers\Base\VersionManager\VersionManager;

$versions = VersionManager::getInstance();   // getService not wired in Chapter 1

$v = $versions->getVersion('templates');        // current version int
$versions->incrementVersion('templates');       // bump → invalidates derived caches
$key = $versions->generateKey('home', 'templates');   // version-scoped cache key
```

## Public API

Full generated index: [`PUBLIC_API.md` → `VersionManager`](../PUBLIC_API.md#versionmanager). At a glance:

- **Versions** — `getVersion`, `incrementVersion`, `decrementVersion`, `resetVersion`, `incrementAllVersions`
- **Groups** — `registerGroup`, `getPrefix`, `generateKey`
- **History** — `getHistory`, `clearHistory`

## Behavior & limits

- **Real Base — full implementation.** No VersionManager Pro.
- **Access via `getInstance()` only** — the `getService` alias is not wired in Chapter 1.
- **Storage fallback chain:** CacheManager → WordPress options → file; gNode atomic increments when the daemon is present.

## Contract

Integration detail: [`CONTRACT.md` §2.13](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
