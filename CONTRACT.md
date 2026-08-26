# gCore — Integration Contract

PHP service-container framework: a singleton "manager-of-managers" that bootstraps, configures, and hands out 26 first-party managers (15 on-disk Base + 11 stub-only) to application/plugin builders. PSR-4 namespace `gCore`, ValKey-backed via gNode-Client, WordPress-aware. The build-with surface is the **manager classes** (and their `Modules/Core/Interfaces/Extensions/<Name>ManagerInterface` contracts); helpers/traits/adapters beneath them are internal plumbing.

> **Scope — Chapter 1 only.** This contract documents the CH1 build-with surface. Chapter-2 "Pro" managers (the `geodineum/gcore-<short>` packages) are **out of scope**: where a CH1 manager is a stub, this contract names that a Pro exists but does **not** document Pro methods or behavior.

> **Authoritative CH1 classifier.** A manager's CH1 status is determined by **on-disk reality** — `ls Modules/Managers/Base/` corroborated by `Modules/Managers/Stubs/register.php` (`$baseManagers` vs `$stubOnlyManagers`, loaded via composer `autoload.files` before any `getService()`). `config/services.yaml` is advisory metadata and is NOT the classifier (see §6.3). 15 Base/ directories exist on disk; 11 managers are stub-only.

---

## 1. THE CONTAINER — gCore

`gCore` is not itself a manager; it is the bootstrap/lifecycle/discovery container. It implements `ModuleInterface` per v1 design but is never resolved through itself.

### 1.1 Entry points

| Symbol | Signature | Source |
|---|---|---|
| `getInstance` | `static getInstance(): self` — singleton bootstrap entry | `Modules/Core/gCore.php` |
| `initialize` | `initialize(array $config = []): void` — config → gNode-Client early init → load topology (ValKey canonical, YAML fallback) → init required services by priority → init optionally-requested services. Brief bootstrap window allows `getService()` during manager init to break recursion | `Modules/Core/gCore.php` |
| `getService` | `getService(string $service = null, array $capabilities = []): object` — fetch manager by name/alias or by capability vector; lazy-loads; throws `InitializationException` on unknown service | `Modules/Core/gCore.php` |

### 1.2 Capability aliases — 16 aliases → 6 managers

`getService()` resolves these aliases (capability map at `gCore.php`):

| Alias(es) | Manager | Source |
|---|---|---|
| `security`, `auth`, `crypto` | SecurityManager | `gCore.php` |
| `cache`, `storage` | CacheManager | `gCore.php` |
| `template`, `rendering`, `tera` | TemplateManager | `gCore.php` alias map |
| `format`, `detection`, `conversion`, `validation` | FormatManager | `gCore.php` |
| `error`, `logging` | ErrorManager | `gCore.php` |
| `api`, `rest` | APIManager | `gCore.php` |

All other managers are reached by **class name** (`getService('CookieManager')`) or by `getInstance()`; the access path per manager is listed in §2–§4.

### 1.3 Discovery & introspection

| Method | Signature | Source |
|---|---|---|
| `findServiceByCapabilities` | `findServiceByCapabilities(array $capabilities): object` — gNode `geometricDiscover()` if available, else local capability→service map | `gCore.php` |
| `registerServiceCapabilities` | `registerServiceCapabilities(string $serviceName, array $capabilities, array $metadata = []): bool` | `gCore.php` |
| `hasService` / `isServiceActive` | `hasService(string $service): bool` / `isServiceActive(string $service): bool` | `gCore.php` |
| `getServiceStatus` / `getStatus` | `getServiceStatus(string $service): array` / `getStatus(): array` | `gCore.php` |
| `getServiceRegistry` | `getServiceRegistry(): array` | `gCore.php` |
| `getExtensionStatus` | `getExtensionStatus(): array` — per extension: `installed(bool), mode(full\|stub), class(FQCN), package` | `gCore.php` |
| `isExtensionInstalled` | `isExtensionInstalled(string $managerName): bool` | `gCore.php` |
| `getMissingExtensionPackages` | `getMissingExtensionPackages(): array` — manager name → composer install command | `gCore.php` |
| `shutdown` | `shutdown(bool $graceful = true): bool` — manager `shutdown()` in reverse priority order | `gCore.php` |

### 1.4 Storage accessors & universal injection

| Method | Signature | Source |
|---|---|---|
| `getStorageAdapter` | `getStorageAdapter(): ?gNodeStorageAdapter` — the SINGLE shared adapter | `gCore.php` |
| `getStorage` | `getStorage(): ?ValKeyStorage` — raw ValKey from gNode-Client | `gCore.php` |

On every manager init, gCore performs **universal config injection** (`site_id`, `node_id`, storage config, `gnode_client`; `gCore.php`) and **unified storage injection** of the singleton `gNodeStorageAdapter` (`gCore.php`) — regardless of whether the manager requested it.

### 1.5 base-vs-stub resolution — the ExtensionResolver rule (LOAD-BEARING)

Every manager name is mapped in `register.php` and resolved through `ExtensionResolver::resolve()` (`gCore.php`). The single rule that defines "CH1 status":

```
resolve(name):
  if force-disabled (gcore_disabled_extensions option) → return stub slot
  elif class_exists(ProClass)                          → return Pro
  else                                                 → return stub slot
```

- For **`$baseManagers`** the "stub slot" **IS the canonical Base class** (`Base/<Name>/<Name>.php`). No Pro package planned ⇒ absence of a Pro yields the **full Base impl**, never a degraded stub.
- For **`$stubOnlyManagers`** the "stub slot" is the no-op `Stubs/<Name>Stub.php`. A Pro ships in CH2 as `geodineum/gcore-<short>`.

Pro-class convention (`register.php`): class `gCore\<Short>\<Short>ManagerPro`, package `geodineum/gcore-<short>`, where `<Short>` = name minus `Manager`. Evidence: `Modules/Core/Utils/ExtensionResolver.php`; `Modules/Managers/Stubs/register.php`.

> **register.php nuance.** `$baseManagers` explicitly lists only the three managers that were moved to `Base/` via ExtensionResolver (`StateManager`, `WordPressManager`, `AssetManager`). The other 12 `Base/` directories are core-loaded (not extension-resolved) but are equally REAL BASE on disk. The classifier is the `Base/` directory's existence, not list membership.

---

## 2. BUILD-WITH — REAL BASE managers (CH1 = full impl; Base class is canonical, no Pro)

13 build-with Base managers. (The 15 `Base/` dirs = these 13 + InstallManager + BackupManager, documented in §4 as install/deploy-heavy.) All implement `ModuleInterface` (§6.4).

### 2.1 SecurityManager
- **Access:** `getService('security'|'auth'|'crypto'|'SecurityManager')`; `getInstance()`. Source: `gCore.php`; `Base/SecurityManager/SecurityManager.php`; `services.yaml` (category=core, priority=1).
- **Role:** role/capability management, CSRF/JWT/API-key auth, tiered distributed rate-limiting.
- **Key public methods:** `defineRole(string $role, array $permissions): bool`; `assignRole(string $user, string $role): bool`; `hasPermission(string $user, string $permission): bool`; `hasCapability(string $user, string $capability): bool`; `getUserCapabilities(string $user): array`; `generateCsrfToken(string $action='default', int $ttl=3600): string`; `validateCsrfToken(string $token, string $action='default'): bool`; `generateJWT(array $payload, array $options=[]): string`; `validateJWT(string $token, array $options=[]): array`; `createAPIKey(array $data=[]): array`; `validateAPIKey(string $apiKey, array $options=[]): array`; `revokeAPIKey(string $apiKey): bool`; `validateAPIRequest($request, array $options=[]): array`; `setgNodeClient($client): void`.
- **CH1:** REAL BASE — full impl. JWT/API-key validation complete (HS256/384/512, timing-safe, claim checks). Rate limiting degrades across tiers: gNode-Client FCALL → gNode `luaIncrBy` → StateManager counter → in-memory per-process. **In-memory-roles limitation by design** (roles/permissions not persisted beyond session + temporary distributed state); this is a documented Base limitation, **not** a Pro-gated stub. Do not expect a SecurityManager Pro (see §6.3).

### 2.2 ErrorManager
- **Access:** `getService('error'|'logging'|'ErrorManager')`. Source: `gCore.php`; `ErrorManager.php`; `services.yaml` (category=core, priority=2).
- **Role:** PHP error/exception/shutdown capture, dedup (MD5 of message+context), rate-limit, TTL-by-level, multi-tier storage (gNode ValKey zset premium vs free-tier adapters), admin email.
- **Key public methods:** `trackError(string $level, string $message, array $context=[]): bool`; `trackSystemEvent(string $level, string $message, array $context=[]): bool`; `handleError(int $level, string $message, string $file, int $line, array $context=[]): bool`; `handleException(\Throwable $e): void`; `handleShutdown(): void`; `notifyAdmin(string $subject, string $message, array $details=[]): bool`; `getRecentErrors(int $limit=10, int $offset=0): array`; `getErrorStats(): array`; `clearErrorHistory(): bool`; `log(string $level, string $message, array $context=[]): bool`; `logCriticalError(string $message, array $context=[], bool $broadcast=true): bool`.
- **CH1:** REAL BASE. gNode broadcast methods (`broadcastErrorAlert`, `listenForErrorAlerts`, `broadcastHealthIssue`, `broadcastRecovery`, `startErrorAlertMonitoring`) **degrade to false/[] without gNode** — not a stub.

### 2.3 CacheManager
- **Access:** `getService('cache'|'storage'|'CacheManager')`; `getInstance()`. Source: `gCore.php`; `CacheManager.php`; `services.yaml` (category=core, priority=3).
- **Role:** distributed cache, multi-site isolation (`cache:{site_id}:{node_id}:{key}`), dual-mode (free-tier `StorageFactory` vs gNode FCALL).
- **Free-tier core (work without gNode):** `set/get/delete/exists(string $key, ...)`; `increment/decrement(string $key, int $by=1): int|false`; `setNx(string $key, mixed $value, int $ttl=0): bool`; `getMultiple/setMultiple/deleteMultiple(...)`; `clear(): bool`; `getKeys(string $pattern): array`; `getMetrics(): array`.
- **Extension-gated (throw `StorageException` without gNode):** `batchSet/batchGet/batchDelete`; `storeContent`/`retrieveContent`; `storeTemplate`; `storeAssetBundle`; `broadcastInvalidate`/`broadcastClearAll`/`listenForInvalidations`; `enableNativeMode`/`disableNativeMode`/`isNativeMode`; format-validation (`registerFormat`, `validateData`, `setWithValidation`, ...); and the `stream*` family (`streamAdd`, `streamReadGroup`, `streamAck`, `streamClaim`, `streamTrim`, ... via the `StreamCapabilities` trait).
- **CH1:** REAL BASE. The extension-gating is **in-tree** (methods check `useGNode` and throw), not a stub split.

### 2.4 FormatManager
- **Access:** `getService('format'|'detection'|'conversion'|'validation'|'FormatManager')`; helper `gcore_get_format_manager()`. Source: `gCore.php`; `FormatManager.php`; `services.yaml` (category=base, priority=5, `gnode_required=true`).
- **Role:** thin `ModuleInterface` wrapper over gNode-Client's FormatManager with registry caching.
- **Key public methods:** `registerFormat(string $name, array $schema, array $patterns=[], array $metadata=[]): array`; `listFormats(): array`; `getFormat(string $name): ?array`; `deleteFormat(string $name): bool`; `detectFormat(string $message): array`; `detectAndValidate(string $message): array`; `convertFormat(string $message, string $sourceFormat, string $targetFormat, array $options=[]): array`; `autoConvertFormat(string $message, string $targetFormat, array $options=[]): array`; `validateMessage(string $message, string $formatName): array`; `registerFormats/detectFormats(array): array`.
- **CH1:** REAL BASE but `gnode_required=true` — **no local fallback**. All ops throw `StorageException` if `use_gnode=false` or gNode unavailable.

### 2.5 APIManager
- **Access:** `getService('api'|'rest'|'APIManager')`; `getInstance()`. Source: `gCore.php`; `APIManager.php`; `services.yaml` (category=base, priority=6).
- **Role:** REST routing, middleware, endpoint registration, rate-limit/CORS, standalone + integrated server modes.
- **Key public methods:** `addMiddleware(string $name, callable $handler): bool`; `registerEndpoint(string $method, string $path, callable $handler, array $options=[]): bool` (throws `ValidationException` on bad method/path); `start(int $port=null, string $host=null): bool`; `processRequest(): void`.
- **CH1:** REAL BASE, full. Auth middleware requires SecurityManager (hard **503** `auth_service_unavailable` if absent); rate-limiting soft-degrades without StateManager.

### 2.6 StateManager
- **Access:** `getService('StateManager')` (no alias); `getInstance()`. Source: `register.php $baseManagers`; `StateManager.php`; `services.yaml` (category=base, priority=4, `gnode_required=false`). Implements `\ArrayAccess` + `StateManagerInterface`.
- **Role:** distributed state, transactions, observers, history, atomic counters.
- **Key public methods:** `setState(string $key, mixed $value, bool $skipValidation): bool`; `getState(string $key, mixed $default): mixed`; `removeState/hasState(string $key)`; `increment/decrement(string $key, int $delta, ?int $ttl): int`; `compareAndSwap(string $key, mixed $expected, mixed $new): bool`; `subscribe(string $key, callable $cb, ?string $id): string` / `unsubscribe` / `publish`; `beginTransaction(int $timeout): string` / `commitTransaction(): bool` / `rollbackTransaction(): bool`; `getHistory(?string $key, int $limit): array`; `registerValidator(...)`; `addMiddleware(callable $mw, int $priority): string`; `restoreState()/persistState(): void`; plus `offsetGet/Set/Exists/Unset`.
- **CH1:** REAL BASE, full. **Graceful in-memory fallback** if gNode absent. No Pro variant.

### 2.7 WordPressManager
- **Access:** `getService('WordPressManager')`; `getInstance()`. Source: `register.php $baseManagers`; `WordPressManager.php`; `services.yaml` (category=base, priority=4, `gnode_required=false`). Implements `WordPressManagerInterface`.
- **Role:** DB cloning, DTAP env switching, GDPR PII scrub — operates directly on `$wpdb` (no gNode).
- **Key public methods:** `cloneDatabase(string $target_env, bool $drop_existing=false): array`; `swapDatabase(string $target_db): array`; `getProductionDbName(): string`; `getEnvironmentDbName(string $environment): string`; `scrubPII(bool $confirm=false, array $options=[]): array` (refuses production; requires explicit `$confirm`); `getScrubPreview(): array`; `isScrubSafe(): bool`; `getEnvironmentInfo(): array`.
- **CH1:** REAL BASE, full. PII scrub is irreversible (no rollback); standalone (non-WP) usage unsupported.

### 2.8 CookieManager
- **Access:** `getService('CookieManager')` or `CookieManager::getInstance()`. Source: `CookieManager.php`; `services.yaml` (category=feature, priority=12, deps `[ErrorManager, CacheManager]`).
- **Role:** GDPR cookie consent, AES-256-CBC encrypt-then-MAC (HMAC-SHA256), WordPress privacy integration, expiry tracking.
- **Key public methods:** `setCookie(string $name, $value, array $options=[], ?string $category=null): bool`; `getCookie(string $name, $default=null): mixed`; `deleteCookie(string $name): bool`; `getCookieExpiry/extendCookieExpiry/refreshCookie/getCookiesExpiringSoon/getExpiredCookies/cleanupExpiredTracking`; `hasConsent(string $category): bool`; `updateConsent(array $preferences): bool`; `displayConsentBanner(): void`; `registerExporter/exportPersonalData/registerEraser/erasePersonalData` (GDPR privacy tools).
- **CH1:** REAL BASE, full parity. WordPress-conditional UI (banner, admin page, privacy hooks) is a no-op outside `ABSPATH`. Hard deps on ErrorManager + CacheManager (`gCore.php`).

### 2.9 ResourceManager
- **Access:** `getService('ResourceManager')`; `getInstance()`. Source: `ResourceManager.php`; `services.yaml` (category=feature, priority=23, deps `[ErrorManager, CacheManager]`).
- **Role:** asset bundling, template-fragment handling, asset caching via gNode.
- **Key public methods:** `createAssetBundle(string $bundleId, array $assets, string $bundleType='mixed', bool $minify=true, ?int $ttl=null): array`; `loadAsset(string $assetId, bool $useCache=true): ?array`; `batchLoadAssets(array $assetIds): array`; `optimizeAsset(string $content, string $type, array $options=[]): string`; `storeTemplateFragment(...)`; `discoverTemplatesByCapability(array $capabilities, int $limit=10): array`; `renderTemplateString(string $template, array $variables=[], array $config=[]): string`; `loadResource/preloadResources/warmupCache/...`.
- **CH1:** REAL BASE, full. gNode-dependent methods (bundling, batch, template ops, discovery, rendering) **throw `StorageException` without gNode**; basic caching/minification/type-detection work without it.

### 2.10 AssetManager
- **Access:** `getService('AssetManager')`. Source: `register.php $baseManagers` (comment `#3`); `Base/AssetManager/AssetManager.php`; implements `AssetManagerInterface`. **FULL production, NOT a stub.**
- **Role:** CMS-agnostic asset storage, manifest-driven bundling, daemon-built bundle retrieval via gNode-Client.
- **Key public methods:** `storeAsset(string $assetId, string $content, string $contentType='text/html', array $options=[]): array`; `getAsset(string $assetId): ?array`; `deleteAsset/listAssets/assetExists`; `setManifest(string $manifestId, array $manifest): array`; `getManifest/deleteManifest/listManifests`; `getBundle(string $manifestId='main', bool $decompress=true): ?array`; `getBundleStatus(string $manifestId): ?array`; `invalidateBundle(string $manifestId='main'): bool`; `syncFaceMapping(array $faceMapping): bool`.
- **CH1:** REAL BASE. Falls back to in-memory-only (per-request) when gNode-Client unavailable; bundle retrieval requires gNode.

### 2.11 HtaccessManager
- **Access:** `HtaccessManager::getInstance()` (**no `getService` alias**). Source: `HtaccessManager.php`; `Base/HtaccessManager/`.
- **Role:** Apache `.htaccess` generation + locked read-modify-write + IP-block section scaffolding. Build-with but niche (consumed by IPBlockManager and install flows).
- **Key public methods:** `setupHtaccess(): bool`; `addHtaccessRule(string $rule, string $section='Custom'): bool`; `getHtaccessPath(): ?string`; `generateHtaccessRules(): string`; `ensureIPBlockSection(string $htaccessPath): void`.
- **CH1:** REAL BASE, full. All `.htaccess` mutations guarded by `LOCK_EX` (TOCTOU-safe). Apache-only; single-file scope.

### 2.12 IPBlockManager
- **Access:** `IPBlockManager::getInstance()` (**no `getService` alias**). Source: `IPBlockManager.php`; `Base/IPBlockManager/`.
- **Role:** Apache-level IP block/unblock/list/expire via `.htaccess`; delegates path + section to HtaccessManager (hard dep).
- **Key public methods:** `blockIP(string $ip, string $reason='', ?int $duration=null): bool` (validates IP, strips CR/LF/`#` to prevent comment injection); `unblockIP(string $ip): bool`; `getBlockedIPs(): array`; `cleanExpiredBlocks(): int`.
- **CH1:** REAL BASE, full. No `LOCK_EX` on individual block/unblock (race-prone under concurrency); requires Apache + `.htaccess`.

### 2.13 VersionManager
- **Access:** `VersionManager::getInstance()` **ONLY** — the `getService` alias is **WIP/absent in CH1**; `getInstance()` is the only supported access. Source: `VersionManager.php`; `Base/VersionManager/`.
- **Role:** version tracking + cache invalidation, multi-tenant, gNode atomic INCR/DECR fallback.
- **Key public methods:** `getVersion(string $group='core'): int`; `incrementVersion(string $group='core', int $amount=1): int`; `decrementVersion(...)`; `resetVersion(string $group='core', int $resetTo=1): int`; `getHistory(?string $group=null, int $limit=50): array`; `clearHistory`; `incrementAllVersions(): void`; `registerGroup(string $group, int $initial_version=1): bool`; `getPrefix(string $group='core'): string`; `generateKey(string $key, string $group='core'): string`.
- **CH1:** REAL BASE, full. Storage fallback chain: CacheManager → WordPress options → file. gNode INCR/DECR atomic when available, in-memory arithmetic otherwise.

---

## 3. BUILD-WITH — CH1-STUB managers (no `Base/` dir; no-op/degraded stub; Pro ships CH2)

11 stub-only managers. All accessed via `$gCore->getService('<Name>Manager')`, routed through `ExtensionResolver::resolve()` (`gCore.php`). All implement their `Modules/Core/Interfaces/Extensions/<Name>ManagerInterface` (except TranslateManagerStub, which implements the base `ModuleInterface`) and log a one-time `WP_DEBUG` upgrade notice. Only **stub** behavior is documented; the Pro package is named for reference only.

### 3.1 TemplateManager
- **Access:** `getService('template'|'rendering'|'tera'|'TemplateManager')`. Stub: `Stubs/TemplateManagerStub.php`.
- **CH1 stub:** basic `{{ var }}` substitution (`render`, `renderString`, `escapeHtml`) **works**; form security **works** (CSRF via CacheManager, honeypot, timing, `detectXSS`, `deepSanitize`). Template registration/persistence/Tera engine are no-op/null (`registerTemplate` returns error; `getTemplate`/`listTemplates` null/empty).
- **Pro (CH2):** `gCore\Template\TemplateManagerPro` (`geodineum/gcore-template`).

### 3.2 TopologyManager
- **Access:** `getService('TopologyManager')`. Stub: `Stubs/TopologyManagerStub.php`.
- **CH1 stub:** discovery/registration return `[]`/`false`; `getDimensions()` returns 23 read-only stub dimensions for UI only; visualization returns empty nodes/edges.
- **Pro (CH2):** `gCore\Topology\TopologyManagerPro`.

### 3.3 SEOManager
- **Access:** `getService('SEOManager')`. Stub: `Stubs/SEOManagerStub.php`.
- **CH1 stub:** in-memory meta/schema/sitemap/robots (no persistence); ALL GEO/AIO methods (`generateAIMeta`, `generateTLDR`, `extractEntities`, ...) return empty/error (need InferenceManager, also a stub in CH1 ⇒ GEO returns error).
- **Pro (CH2):** `gCore\SEO\SEOManagerPro`.

### 3.4 OptimizationManager
- **Access:** `getService('OptimizationManager')`. Stub: `Stubs/OptimizationManagerStub.php`.
- **CH1 stub:** full no-op/passthrough (lifecycle hooks no-op, `optimize*` return input unchanged); exclusion lists in-memory only; `forceCleanup()` returns a `stub_mode` result.
- **Pro (CH2):** `gCore\Optimization\OptimizationManagerPro`.

### 3.5 ManifestManager
- **Access:** `getService('ManifestManager')`. Stub: `Stubs/ManifestManagerStub.php`.
- **CH1 stub:** minimal PWA manifest + basic service-worker registration; icon validation hardcoded `valid:false`; install tracking/stats no-op; cache invalidation no-op.
- **Pro (CH2):** `gCore\Manifest\ManifestManagerPro`.

### 3.6 MetricsManager
- **Access:** `getService('MetricsManager')`. Stub: `Stubs/MetricsManagerStub.php`.
- **CH1 stub:** StateManager-backed counters + latency window **work when StateManager present** (`trackMetric`, `getCacheHitRatio`, `getLatencyStats`, `getOpsPerSecond`), else no-op. `getMetricDetails()` is **always** `[]` (Pro-only).
- **Pro (CH2):** `gCore\Metrics\MetricsManagerPro`.

### 3.7 AnalyticsManager
- **Access:** `getService('AnalyticsManager')`. Stub: `Stubs/AnalyticsManagerStub.php`.
- **CH1 stub:** StateManager-backed daily visitor/pageview counters + `getCacheEfficiency` **work when StateManager present** (privacy-first SHA-256 hash, no PII); `getVisitorJourneys`/`getTopPages`/`getVisitorResourceCosts`/`getMetricHistory` + multi-day all `[]` (today-only).
- **Pro (CH2):** `gCore\Analytics\AnalyticsManagerPro`.
- `services.yaml` (category=extension, priority=102) — **CORRECT**.

### 3.8 CommsManager — TWO distinct CH1 classes (do not conflate)
- **(a) The manager slot — STUB.** `getService('CommsManager')` / `getInstance()` resolves to `Stubs/CommsManagerStub.php`: a no-op multi-tenant notification manager (in-memory settings, empty history, channels disabled, daemon `not_available`, `testChannel`/`getStats` return stub markers). **Pro (CH2):** `gCore\Comms\CommsManagerPro` (`geodineum/gcore-comms`). `services.yaml` (extension) — CORRECT.
- **(b) The admin/dashboard class — REAL CH1.** `gCore\Modules\Comms\CommsManager` (`Modules/Comms/CommsManager.php`, implements `CommsManagerInterface`), used by `Modules/Comms/Admin/CommsAdmin.php`. This one **DOES** ValKey-stream I/O: reads `{site_id}:gnode:comms:{env}` (`getRecentMessages`, `getStats`, `getDaemonStatus`) and **produces** a test message via `testChannel` → `XADD {site_id}:gnode:comms:{env}`, which **stamps top-level `environment`** for the  non-prod gate (committed `38b6a21`). It adheres to the COMMS message contract (see Geodineum-COMMS/CONTRACT.md §4). This is the real CH1 comms surface a builder uses to drive a dashboard / fire a test send — separate from the inert manager-slot stub.

### 3.9 InferenceManager
- **Access:** `getService('InferenceManager')`. Stub: `Stubs/InferenceManagerStub.php`.
- **CH1 stub:** all ML methods (`generateText`, `chat`, `generateEmbeddings`, `batchInference`, model management) return `success=false` / `[]` / `false` with error `requires gcore-inference`. SEOManager GEO/AIO depends on this.
- **Pro (CH2):** `gCore\Inference\InferenceManagerPro`.
- `services.yaml` (category=extension) — **CORRECT**.

### 3.10 TranslateManager
- **Access:** `getService('TranslateManager')`. Stub: `Stubs/TranslateManagerStub.php`.
- **CH1 stub:** returns content untranslated, single default language; `setCurrentLanguage` false, `renderLanguageSwitcher` empty, `isAvailable()` false; all methods no-op.
- **Pro (CH2):** `gCore\Translate\TranslateManagerPro`.
- `services.yaml` (category=extension) — **CORRECT**.

### 3.11 EcommerceManager
- **Access:** `getService('EcommerceManager')`; `getInstance()`. Stub: `Stubs/EcommerceManagerStub.php`.
- **CH1 stub:** adapter auto-detect (shipped `WooCommerceAdapter`) + delegation for cart/product/inventory (`getCart`, `addToCart`, `getProduct`, `getStock`, ...); caching/analytics/checkout-security all no-op (`validateCheckoutRate` always true, `trackCartEvent` no-op, `getCartAbandonmentRate` returns -1.0).
- **No Pro package yet** — the stub is the ONLY implementation (`register.php`: "EcommerceManager has no Pro package yet").
- `services.yaml` (category=extension) — **CORRECT**.

> **Public extension point:** `EcommerceAdapterInterface` (`Modules/Core/Interfaces/EcommerceAdapterInterface.php`) is the public contract for third-party platform adapters. The shipped `WooCommerceAdapter` concrete class is internal.

---

## 4. INTERNAL — Install/Deploy-time managers (flagged, NOT hidden)

These have `Base/` dirs (so 15 Base dirs total) and remain PUBLIC build-with on the author's frame, but their **primary** lifecycle is install/deploy. Both also expose runtime-callable APIs, so neither is purely lifecycle.

### 4.1 InstallManager
- **Access:** `InstallManager::getInstance()` **ONLY** — no `services.yaml` entry and `loadServiceTopology` does not merge `geometric_topology.yaml`'s services, so `getService('InstallManager')` is **not wired in CH1** and throws. Source: `Base/InstallManager/InstallManager.php`. Registry entry at `geometric_topology.yaml`. Not in `register.php` (core-loaded, not extension-resolved).
- **Role:** extension install, file-integrity verification vs geodineum.com registry, warranty tracking, gNode distributed locking.
- **Runtime-callable APIs builders use:** `verifyIntegrity(bool $force=false): array`; `getWarrantyInfo(): array`; `getInstalledExtensions(): array`; `getAvailableExtensions(?string $type=null): array`; `validateLicense(string $licenseKey, ?string $product=null): bool`; `subscribeToNotifications(callable $callback): bool`.
- **Install/deploy APIs:** `installExtension`, `updateExtension`, `setupEnvironment`, `validateEnvironment`.

### 4.2 BackupManager
- **Access:** `BackupManager::getInstance()`. Source: `Base/BackupManager/BackupManager.php`. No `getService` alias; not in `services.yaml`; not in `register.php` (core-loaded). Listed in README as public "Backup hooks for Geodineum-BAK".
- **Role:** filesystem backup/restore/retention under `{base}/backups/`.
- **Runtime-callable APIs:** `createBackup(string $name, string $path): ?string`; `restoreBackup(string $backupPath, string $targetPath): bool` (destructive); `cleanOldBackups(int $retentionDays=30): int`.

---

## 5. INTERNAL PLUMBING (NOT build-with surface)

The manager **class** is the API; the helper/trait/adapter/value-object classes beneath it are internal. Listed once here, not per manager.

| Class / trait | Role | Source |
|---|---|---|
| `ManagerConfigTrait` | shared config/secret layering for ~all managers (`gcoreLoadConfig`/`gcoreSetConfig`/`gcoreResolveStorage`/`gcoreGetSecret` via `GCORE_MGR_CONFIG_*` / `GCORE_MGR_SECRETS_*` FCALL; DEFAULTS → ValKey per-site → `$config` merge) | `Modules/Core/Shared/ManagerConfigTrait.php` |
| `StateManagerAware` (trait) | lazy StateManager access + distributed counter/window/dedup/rate-limit helpers (used by APIManager, SecurityManager, ResourceManager, Metrics/Analytics stubs) | `Modules/Managers/Traits/StateManagerAware.php` |
| `ExtensionManagerErrorHandling` (trait) | extension-load error handling | `Modules/Core/Shared/ExtensionManagerErrorHandling.php` |
| `DependencyResolver` + `DependencyBundle` | The only real dependency machinery: dependency graph, topological load order, cycle detection with `CircularDependencyException`, strict/relaxed/auto-fix strategies. **CLI-only** — `DependencyBundle` is instantiated solely by `DependencyResolver`, which is reached solely from `admin/cli/dependency-resolver.php`. Never touched on the request path | `Modules/Core/Utils/DependencyResolver.php`; `Modules/Core/Shared/DependencyBundle.php` |
| `gNodeStorageAdapter` | wraps gNode-Client `getStorage()` with site namespacing + Lua; SINGLE shared instance injected to all managers | `Modules/Storage/gNodeStorageAdapter.php` (injected `gCore.php`) |
| `StorageFactory` + `StorageInterface` + `gNodeDetector` | free-tier adapter selection (WP Transients → APCu → Memory), adapter contract, gNode availability probe | `Modules/Storage/` (e.g. `CacheManager.php`) |
| `ExtensionResolver` + `register.php` | the stub↔Pro dispatcher (see §1.5); builders never call directly | `Modules/Core/Utils/ExtensionResolver.php`; `Modules/Managers/Stubs/register.php` |
| `ConfigLoader` | 4-tier config load with backfill: per-request static cache → APCu (keyed on the ValKey constellation-generation counter) → ValKey → `config/compiled.php` (OPcache'd) → YAML parse | `Modules/Core/Utils/ConfigLoader.php` |
| `TopologyParser` | Dependency pre-init at `initializeService()` — **hardcoded, not YAML-driven** (71 lines): `resolveDependencies()` returns edges for ErrorManager/CacheManager/SecurityManager/APIManager only and `[]` for every other service; `isServiceRequired()`/`getServiceType()`/`getServiceImplementation()` ignore `services.yaml` entirely. **No circular-dependency detection.** Single call site: `gCore.php` | `Modules/Core/Utils/TopologyParser.php` |
| `SelfContainedErrorHandler` | dependency-free logging used during early init before ErrorManager is available | `Modules/Core/Utils/SelfContainedErrorHandler.php` |
| Per-manager private helpers/value-objects | e.g. AssetManager `convertFaceMappingToManifest`/`getFacePosition`; BackupManager `copyDirectory`/`removeDirectory`/`getBackupDir`; HtaccessManager `withExclusiveLock`; InstallManager ~30 private helpers + `WARRANTY_STATUS`/`REQUIRED_DIRS` consts; StateManager `getStateHashKey`/`serialize`/`notifyObservers`; `WooCommerceAdapter` concrete impl | (in respective manager dirs) |

---

## 6. CONSUMES / REQUIRES & ADHERENCE

### 6.1 Consumes / requires
- **gNode-Client** (`gCore\gNode\gNodeClient`, PSR-4, separate package): injected early at `gCore.php` via `forSite(site_id, environment, overrides)`; provides ValKey storage, FCALL surface, broadcast, streams, format manager, capability dimensions. Optional per site — absence is a logged warning, gCore continues on YAML topology — but FormatManager (`gnode_required=true`) and all extension-gated methods fail without it.
- **ValKey** (Redis-compatible, via gNode-Client or free-tier `StorageInterface` adapter): the common backend. Per-site topology key `{site_id}:gcore:config:services`; manager config/secrets in `{site_id}:gcore:config:<Manager>` / `:secrets:<Manager>` via `GCORE_MGR_CONFIG_*` / `GCORE_MGR_SECRETS_*` Lua functions.
- **WordPress** (optional, `defined('ABSPATH')`): CookieManager/WordPressManager/VersionManager/StateManager register hooks and use `$wpdb`/options; UI and DB features no-op outside WP.

### 6.2 Dependency / init order (priority-ascending; `gCore.php` bootstrapRequiredServices)
SecurityManager(1) → ErrorManager(2) → CacheManager(3) → TemplateManager(4)/StateManager(4)/WordPressManager(4) → FormatManager(5)/TopologyManager(5) → APIManager(6) → SEOManager(7) → OptimizationManager(8) → ManifestManager(9); feature tier CookieManager(12)/MetricsManager(15)/ResourceManager(23); extension tier 100+.

> **`priority` orders initialization; the YAML `dependencies:` arrays do not.** `bootstrapRequiredServices` sorts by `priority` ascending and instantiates only the 7 services flagged `required: true` (Security, Error, Cache, Template, SEO, Optimization, Manifest) plus the separately-initialized `gnode_client`; everything else is lazy on first `getService()`. Per-service dependency pre-init goes through `TopologyParser::resolveDependencies()`, whose graph is hardcoded for four managers only — so a `dependencies:` entry in `services.yaml` is documentation, not a load-order instruction. **Nothing detects a dependency cycle at runtime** (§5: the cycle-detecting `DependencyResolver`/`DependencyBundle` pair is CLI-only).

Key runtime edges: CookieManager → ErrorManager + CacheManager (**hard**, `gCore.php`). APIManager → SecurityManager (**hard 503** if absent) + StateManager (soft). ResourceManager → CacheManager/OptimizationManager/MetricsManager/StateManager (all optional, graceful). SecurityManager → StateManager (rate-limit tier-2) + gNode-Client (tier-0/1). IPBlockManager → HtaccessManager (**hard**). VersionManager → CacheManager. SEOManager(stub) → InferenceManager(stub) ⇒ GEO returns error in CH1. InstallManager → ErrorManager + CacheManager + gNode-Client + geodineum.com registry.

### 6.3 Adherence flags
- **`services.yaml` role (assessed) + stale `class` reconciled.** `services.yaml` is NOT dead: `compile-config.php` compiles it into ValKey `{site_id}:gcore:config:services` (runtime fast-path) and it is the Tier-2 fallback (`gCore.php`); its `category`/`priority` fields are **load-bearing** — they drive init order (`gCore.php`). The `class:` field, however, is **vestigial for resolution**: `getService` reads it but `ExtensionResolver::resolve()` (from the authoritative `register.php`) **overrides** it (`gCore.php`) — which is exactly why the stale entries never broke anything. **RESOLVED 2026-06-22:** the 6 stub managers' `class:` was corrected from nonexistent `Base\…` paths to their actual `Stubs\…Stub` class, and `AssetManager` from the deleted `AssetManagerStub` to its real `Base\AssetManager` (matching `register.php`); a header note records that `register.php` is authoritative for class resolution. `category`/`priority` left intact (load-bearing). NB: re-run `compile-config.php` to refresh the ValKey copy.
- **SecurityManager — NOT a stub (audit flag was a false positive).** `register.php` files it in `$baseManagers`; the source carries no "Stub/Simplified" self-label — the only `free_tier`/in-memory references are its legitimate runtime ValKey-absent fallback (`SecurityManager.php`), not a stub marker. It is a **REAL Base manager**; in-memory roles when ValKey is absent are a documented Base limitation, not a Pro gate. No source change needed.
- **CommsManager vs COMMS wire contract (corrected).** The *manager-slot* stub (`getService('CommsManager')`) is inert. But the *admin/dashboard* class `gCore\Modules\Comms\CommsManager` (§3.8b) is a real CH1 producer/reader: it XADDs a test message and reads history on `{site_id}:gnode:comms:{env}`, adhering to the Geodineum-COMMS message contract.  **is implemented** end-to-end across the ecosystem: this class stamps top-level `environment` (`38b6a21`), and the Geodineum-COMMS daemon enforces the non-prod dry-run gate. (Bulk/programmatic comms dispatch is the CH2 `gcore-comms` Pro feature; CH1 exposes the admin test-send + dashboard reads.)

### 6.4 The universal lifecycle contract
Every manager implements **`ModuleInterface`** (`Modules/Core/Interfaces/ModuleInterface.php`): `getInstance(): self`, `initialize(array $config=[]): void`, `getConfig(): array`, `updateConfig(array $config): void`, `isInitialized(): bool`, `getStatus(): array`. This is the single build-with lifecycle contract — document/expect it once across all managers. Extension-tier managers additionally implement `Modules/Core/Interfaces/Extensions/<Name>ManagerInterface`, which **are** the public per-manager contracts. `MicroserviceInterface`/`MicroserviceFactoryInterface` (gCore health loop, `gCore.php`) are internal.

### 6.5 Extension-provided capabilities gCore consumes (cross-reference)
gCore is a **consumer** of gNode signed daemon extensions, not their owner — those extensions compile into the gNode daemon (`GNODE_EXT_DIR`) and define their own contracts (print with **`geodineum daemon contract <ext>`**). The managers that reach them:
- **TemplateManager / ResourceManager** → gNode-CMS template commands (`render_template`, `render_string`, `serve_fragment`) + content/asset commands.
- **AssetManager / ResourceManager** → gNode-CMS `GNODE_ASSET_*` FCALLs + `asset_bundle` / `manifest_*` (the daemon background bundle builder).
- **CommsManager** (`Modules/Comms/CommsManager`) → the Geodineum-COMMS message contract (`{site_id}:gnode:comms:{env}`), not a gNode extension.

These are wire dependencies on the **daemon's** surface; the authoritative definitions live in each extension's `CONTRACT.md` (`geodineum daemon contract cms|broker|observe|topo|signals`) and in `gNode/COMMAND_SCHEMA.md`, never duplicated here.
