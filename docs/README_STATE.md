# gCore · StateManager

Distributed state coordination — transactions, observers, history, and atomic counters.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

StateManager coordinates distributed state across requests: get/set with validation, atomic counters and compare-and-swap, an observer (pub/sub) layer, multi-step transactions, and history. It implements `\ArrayAccess`, so state reads and writes can also go through array syntax. When gNode is absent it falls back gracefully to in-memory state.

## Usage

```php
$state = $gCore->getService('StateManager');   // no capability alias

$state->setState('cart:42', ['items' => 3]);
$cart = $state->getState('cart:42', []);         // default if absent
$state['checkout_open'] = true;                  // \ArrayAccess sugar

$state->increment('orders:today');

$state->beginTransaction();
$state->setState('order:1001', ['status' => 'paid']);
$state->commitTransaction();
```

## Public API

Full generated index: [`PUBLIC_API.md` → `StateManager`](../PUBLIC_API.md#statemanager). At a glance:

- **State** — `setState`, `getState`, `removeState`, `hasState`, plus `offsetGet`/`offsetSet`/`offsetExists`/`offsetUnset`
- **Atomics** — `increment`, `decrement`, `compareAndSwap`
- **Observers** — `subscribe`, `unsubscribe`, `publish`
- **Transactions** — `beginTransaction`, `commitTransaction`, `rollbackTransaction`
- **History & lifecycle** — `getHistory`, `registerValidator`, `addMiddleware`, `restoreState`, `persistState`

## Behavior & limits

- **Real Base — full implementation.** No StateManager Pro.
- **Graceful in-memory fallback** when gNode is absent (per-process, not shared).

## Contract

Integration detail: [`CONTRACT.md` §2.6](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
