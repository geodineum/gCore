# gCore · HtaccessManager

Locked `.htaccess` rule composition and write-out.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

HtaccessManager generates Apache `.htaccess` content, applies a locked read-modify-write so concurrent edits can't corrupt the file, and scaffolds an IP-block section. It is build-with but niche — mostly consumed by IPBlockManager and the install flows.

## Usage

```php
use gCore\Modules\Managers\Base\HtaccessManager\HtaccessManager;

$htaccess = HtaccessManager::getInstance();   // no getService alias

$htaccess->setupHtaccess();                                    // write the gCore ruleset
$htaccess->addHtaccessRule('Header set X-Frame-Options DENY', 'Security');
$path = $htaccess->getHtaccessPath();
```

## Public API

Full generated index: [`PUBLIC_API.md` → `HtaccessManager`](../PUBLIC_API.md#htaccessmanager). At a glance:

- **Setup** — `setupHtaccess`, `getHtaccessPath`, `generateHtaccessRules`
- **Rules** — `addHtaccessRule`, `ensureIPBlockSection`

## Behavior & limits

- **Real Base — full implementation.** No HtaccessManager Pro.
- **All mutations are `LOCK_EX`-guarded** (TOCTOU-safe).
- **Apache-only, single-file scope**; access via `getInstance()` only.

## Contract

Integration detail: [`CONTRACT.md` §2.11](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
