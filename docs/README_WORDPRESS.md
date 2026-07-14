# gCore · WordPressManager

Database cloning, DTAP environment switching, and GDPR PII scrubbing.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

WordPressManager operates directly on `$wpdb` (no gNode) to clone databases, switch DTAP environments (testing / staging / production), and scrub personally-identifiable information from non-production copies.

## Usage

```php
$wp = $gCore->getService('WordPressManager');

// Clone production into a staging DB, then scrub PII from the copy
$wp->cloneDatabase('staging');
if ($wp->isScrubSafe()) {
    $wp->scrubPII(true);   // irreversible; refuses to run on production
}
$info = $wp->getEnvironmentInfo();
```

## Public API

Full generated index: [`PUBLIC_API.md` → `WordPressManager`](../PUBLIC_API.md#wordpressmanager). At a glance:

- **Database** — `cloneDatabase`, `swapDatabase`, `getProductionDbName`, `getEnvironmentDbName`
- **PII** — `scrubPII` (refuses production; requires explicit `$confirm`), `getScrubPreview`, `isScrubSafe`
- **Environment** — `getEnvironmentInfo`

## Behavior & limits

- **Real Base — full implementation.** No WordPressManager Pro.
- **PII scrub is irreversible** — there is no rollback; it refuses to run against production and requires an explicit confirm flag.
- **WordPress-only** — standalone (non-WordPress) usage is unsupported.

## Contract

Integration detail: [`CONTRACT.md` §2.7](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
