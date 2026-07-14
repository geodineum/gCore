# gCore · IPBlockManager

Apache-level IP block / unblock / expire via `.htaccess`.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

IPBlockManager blocks, unblocks, lists, and expires IP addresses at the Apache layer, delegating the file path and section handling to HtaccessManager (a hard dependency). Blocked IPs are validated and sanitized (CR/LF and `#` stripped) to prevent `.htaccess` comment injection.

## Usage

```php
use gCore\Modules\Managers\Base\IPBlockManager\IPBlockManager;

$ipblock = IPBlockManager::getInstance();   // no getService alias

$ipblock->blockIP('203.0.113.7', 'abuse', 3600);   // reason, optional TTL in seconds
$blocked = $ipblock->getBlockedIPs();
$ipblock->cleanExpiredBlocks();                      // run periodically (e.g. daily cron)
```

## Public API

Full generated index: [`PUBLIC_API.md` → `IPBlockManager`](../PUBLIC_API.md#ipblockmanager). At a glance:

- **Block** — `blockIP`, `unblockIP`
- **Query** — `getBlockedIPs`, `cleanExpiredBlocks`

## Behavior & limits

- **Real Base — full implementation.** No IPBlockManager Pro.
- **No `LOCK_EX` on individual block/unblock** — race-prone under heavy concurrency.
- **Requires Apache + `.htaccess`** and HtaccessManager; access via `getInstance()` only.

## Contract

Integration detail: [`CONTRACT.md` §2.12](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
