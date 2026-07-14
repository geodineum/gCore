# gCore · InstallManager

Extension installation and file-integrity verification (install/deploy-time).

Part of [gCore](../README.md) · first-party **Base** manager · primary lifecycle is install/deploy

## What it is

InstallManager installs extensions, verifies file integrity against the geodineum.com registry, tracks warranty state, and uses gNode distributed locking during installs. Its primary role is install/deploy, but it exposes runtime-callable APIs a builder may use.

## Usage

```php
use gCore\Modules\Managers\Base\InstallManager\InstallManager;

$install = InstallManager::getInstance();   // getService not wired in Chapter 1

$report    = $install->verifyIntegrity();           // files vs geodineum.com registry
$installed = $install->getInstalledExtensions();
$available = $install->getAvailableExtensions();
```

## Public API

Full generated index: [`PUBLIC_API.md` → `InstallManager`](../PUBLIC_API.md#installmanager). At a glance:

- **Runtime-callable** — `verifyIntegrity`, `getWarrantyInfo`, `getInstalledExtensions`, `getAvailableExtensions`, `validateLicense`, `subscribeToNotifications`
- **Install / deploy** — `installExtension`, `updateExtension`, `setupEnvironment`, `validateEnvironment`

## Behavior & limits

- **Real Base — full implementation.** No InstallManager Pro.
- **Access via `getInstance()` only** — not wired through `getService` in Chapter 1.
- Full function needs the **geodineum.com registry** and **gNode-Client**.

## Contract

Integration detail: [`CONTRACT.md` §4.1](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
