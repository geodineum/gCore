# gCore · APIManager

REST routing, a middleware pipeline, and endpoint registration with rate-limiting and CORS.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

APIManager provides a REST layer: register endpoints with method + path + handler, compose a middleware pipeline (auth, rate-limit, CORS), and run either as a standalone HTTP server or integrated into an existing request lifecycle.

## Usage

```php
$api = $gCore->getService('api');   // aliases: 'rest', 'APIManager'

$api->addMiddleware('auth', fn($req) => $req);   // your auth check

$api->registerEndpoint('GET', '/v1/orders/{id}', function ($req) {
    return ['id' => $req['id'], 'status' => 'paid'];
}, ['middleware' => ['auth']]);

$api->processRequest();   // dispatch the current HTTP request
```

## Public API

Full generated index: [`PUBLIC_API.md` → `APIManager`](../PUBLIC_API.md#apimanager). At a glance:

- **Endpoints** — `registerEndpoint` (throws `ValidationException` on an invalid method or path)
- **Middleware** — `addMiddleware`
- **Server** — `start`, `processRequest`

## Behavior & limits

- **Real Base — full implementation.** No APIManager Pro.
- **Auth middleware requires SecurityManager** — a request through an authenticated endpoint returns a hard `503 auth_service_unavailable` if SecurityManager is absent.
- **Rate-limiting soft-degrades** without StateManager (falls back rather than failing).

## Contract

Integration detail: [`CONTRACT.md` §2.5](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
