# gCore · SecurityManager

Authentication, authorization, and request protection for gCore: roles and capabilities, CSRF tokens, JWTs, API keys, and tiered rate-limiting.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — there is no SecurityManager Pro)

## What it is

SecurityManager is the security surface every other manager and consumer builds on. It manages roles and per-user capabilities, issues and validates CSRF tokens, JWTs (HS256/384/512, timing-safe, with claim checks) and API keys, and applies distributed rate-limiting. It is a real Base manager: absence of a Pro package yields the full implementation, never a degraded stub.

## Usage

```php
$security = $gCore->getService('security');   // aliases: 'auth', 'crypto', 'SecurityManager'

// Define a role and grant it to a user
$security->defineRole('editor', ['edit_posts', 'publish_posts']);
$security->assignRole('user_42', 'editor');

// Gate an action, protected by a CSRF token
if ($security->hasPermission('user_42', 'publish_posts')) {
    $token = $security->generateCsrfToken('publish');
    // ... render the form with $token; on submit: ...
    if ($security->validateCsrfToken($_POST['csrf'] ?? '', 'publish')) {
        // safe to proceed
    }
}
```

## Public API

The full, always-accurate method index is generated into
[`PUBLIC_API.md` → `SecurityManager`](../PUBLIC_API.md#securitymanager) (signatures + summaries).
At a glance, grouped by concern (names only — the index and the docblocks carry the signatures):

- **Roles & capabilities** — `defineRole`, `assignRole`, `hasPermission`, `hasCapability`, `getUserCapabilities`, `addCapability`, `removeCapability`
- **CSRF** — `generateCsrfToken`, `validateCsrfToken`, `revokeCsrfToken`
- **JWT** — `generateJWT`, `validateJWT`
- **API keys** — `createAPIKey`, `validateAPIKey`, `revokeAPIKey`, `validateAPIRequest`

## Behavior & limits

- **Real Base — full implementation.** No SecurityManager Pro exists or is planned.
- **Roles are in-memory without ValKey.** When ValKey is unavailable, roles and permissions are held for the request only and do not persist — a documented Base limitation, not a Pro gate.
- **Rate-limiting degrades across tiers:** gNode-Client FCALL → gNode `luaIncrBy` → StateManager counter → in-process memory.
- **JWT** validation is complete: HS256/384/512, timing-safe comparison, standard claim checks.

## Contract

Integration detail — access paths, full method signatures, dependency edges — is in
[`CONTRACT.md` §2.1](../CONTRACT.md). The universal `ModuleInterface` lifecycle
(`getInstance` / `initialize` / `getConfig` / `updateConfig` / `isInitialized` / `getStatus`)
applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
