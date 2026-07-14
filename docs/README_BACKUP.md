# gCore · BackupManager

Filesystem backup, restore, and retention (install/deploy-time).

Part of [gCore](../README.md) · first-party **Base** manager · primary lifecycle is install/deploy

## What it is

BackupManager creates, restores, and prunes filesystem backups under `{base}/backups/`. It is the backup hook surface for Geodineum-BAK.

## Usage

```php
use gCore\Modules\Managers\Base\BackupManager\BackupManager;

$backup = BackupManager::getInstance();   // no getService alias

$path = $backup->createBackup('pre-deploy', '/var/www/site/wp-content');
// ... later, if a rollback is needed (destructive — overwrites the target) ...
$backup->restoreBackup($path, '/var/www/site/wp-content');
$backup->cleanOldBackups(30);   // prune backups older than 30 days
```

## Public API

Full generated index: [`PUBLIC_API.md` → `BackupManager`](../PUBLIC_API.md#backupmanager). At a glance:

- **Backups** — `createBackup`, `restoreBackup`, `cleanOldBackups`

## Behavior & limits

- **Real Base — full implementation.** No BackupManager Pro.
- **`restoreBackup` is destructive** — it overwrites the target path.
- Access via `getInstance()` only; retention is by age in days.

## Contract

Integration detail: [`CONTRACT.md` §4.2](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
