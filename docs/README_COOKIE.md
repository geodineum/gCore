# gCore · CookieManager

GDPR cookie consent with encrypted cookies and WordPress privacy tools.

Part of [gCore](../README.md) · first-party **Base** manager (full implementation — no Pro)

## What it is

CookieManager handles GDPR cookie consent, stores cookies encrypted (AES-256-CBC, encrypt-then-MAC with HMAC-SHA256), tracks expiry, and integrates with WordPress privacy (exporters and erasers). It has hard dependencies on ErrorManager and CacheManager.

## Usage

```php
$cookies = $gCore->getService('CookieManager');

// Only set an analytics cookie if the visitor consented to that category
if ($cookies->hasConsent('analytics')) {
    $cookies->setCookie('sid', $sessionId, [], 'analytics');
}
$sid = $cookies->getCookie('sid');
```

## Public API

Full generated index: [`PUBLIC_API.md` → `CookieManager`](../PUBLIC_API.md#cookiemanager). At a glance:

- **Cookies** — `setCookie`, `getCookie`, `deleteCookie`
- **Expiry** — `getCookieExpiry`, `extendCookieExpiry`, `refreshCookie`, `getCookiesExpiringSoon`, `getExpiredCookies`, `cleanupExpiredTracking`
- **Consent** — `hasConsent`, `updateConsent`, `displayConsentBanner`
- **GDPR privacy** — `registerExporter`, `exportPersonalData`, `registerEraser`, `erasePersonalData`

## Behavior & limits

- **Real Base — full parity.** No CookieManager Pro.
- **WordPress-conditional UI** (consent banner, admin page, privacy hooks) is a no-op outside `ABSPATH`.
- **Hard dependencies** on ErrorManager and CacheManager.

## Contract

Integration detail: [`CONTRACT.md` §2.8](../CONTRACT.md). The universal `ModuleInterface` lifecycle applies here as to every manager.

---

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

Built by **Niels Erik Toren** — [support the work](../README.md#author--support).
