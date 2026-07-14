# gCore · ErrorManager

Error, exception, and shutdown capture with deduplication, level-based TTL, and admin alerts.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

ErrorManager captures PHP errors, exceptions, and fatal shutdowns; deduplicates them (hash of message + context), rate-limits, and stores them with a time-to-live that scales by severity. Storage is multi-tier — a gNode ValKey sorted-set when available, free-tier adapters otherwise — and it can email an administrator on threshold breaches.

## Usage

```php
$errors = $gCore->getService('error');   // aliases: 'logging', 'ErrorManager'

$errors->trackError('warning', 'Payment retried', ['order' => 1001]);

try {
    // ... risky work ...
} catch (\Throwable $e) {
    $errors->handleException($e);
}

$recent = $errors->getRecentErrors(10);
```

## Public API

Full generated index: [`PUBLIC_API.md` → `ErrorManager`](../PUBLIC_API.md#errormanager). At a glance:

- **Capture** — `trackError`, `trackSystemEvent`, `handleError`, `handleException`, `handleShutdown`, `log`, `logCriticalError`
- **Query** — `getRecentErrors`, `getErrorStats`, `clearErrorHistory`
- **Alerts** — `notifyAdmin`

## Behavior & limits

- **Real Base — full implementation.** No ErrorManager Pro.
- **gNode broadcast methods degrade without gNode** — `broadcastErrorAlert`, `listenForErrorAlerts`, `broadcastHealthIssue`, `broadcastRecovery`, and `startErrorAlertMonitoring` return `false`/`[]` rather than failing.

## Contract

Integration detail: [`CONTRACT.md` §2.2](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
