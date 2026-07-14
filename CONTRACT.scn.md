# gCore :: CONTRACT primer (SCN)

> one-line: SCN primer — TRUTH = code on disk, point-in-time compression. Companion: CONTRACT.md (authoritative). CH1 surface only; Pro managers = CH2 (excluded).

## ::ROLE
SPR: gCore = manager-of-managers PHP framework (WP + standalone) over gNode-Client + ValKey.
→ singleton service container bootstraps + lifecycles + discovers a roster of first-party managers.
→ stateless yet state-aware: ValKey-backed config/state, per-site isolation, graceful degradation when gNode absent.
→ ALL first-party managers = PUBLIC build-with surface ∀ manager. CH1 status splits on ON-DISK reality, not services.yaml.

## ::ANCHOR
container = gCore::getInstance() (gCore.php) → initialize() (gCore.php) → getService(name|alias) (gCore.php).
capability aliases = 8 aliases → 6 managers (gCore.php):
  security|auth|crypto → SecurityManager
  cache|storage → CacheManager
  template|rendering|tera → TemplateManager
  format|detection|conversion|validation → FormatManager
  error|logging → ErrorManager
  api|rest → APIManager
classifier = register.php $baseManagers vs $stubOnlyManagers (Modules/Managers/Stubs/register.php), corroborated by `ls Modules/Managers/Base/`.
  REAL BASE = Base/<Name>/<Name>.php exists; ExtensionResolver 'stub' slot IS the canonical Base class (no Pro).
  CH1 STUB = no Base/ dir; 'stub' slot = no-op Stubs/<Name>Stub.php; Pro ships CH2 via geodineum/gcore-<short>.
≠ classifier: services.yaml is advisory metadata, NOT the authoritative classifier (see §6.3).
roster = 13 REAL-BASE build-with + 11 CH1-STUB build-with + 2 install/deploy (also build-with) = 26 documented.
  15 on-disk Base/ dirs = 13 build-with-base + InstallManager + BackupManager.
key types: ModuleInterface (universal lifecycle ∀ manager: getInstance/initialize/getConfig/updateConfig/isInitialized/getStatus); per-manager <Name>ManagerInterface in Modules/Core/Interfaces/Extensions/ (the public per-manager contracts).

## ::ARCHITECTURE
service container init (gCore.php):
  config (config/default.yaml + runtime) → gNode-Client early init (gCore.php) → load topology (ValKey {site_id}:gcore:config:services canonical (gCore.php) | services.yaml fallback (gCore.php)) → microservice factories → bootstrapRequiredServices (priority-ascending) → optionally-requested services → universal config injection (gCore.php: site_id/node_id/storage/gnode_client) → gNodeStorageAdapter singleton injection (gCore.php).
lazy-load: getService() on uninitialized → initializeService() registers before initialize() (recursion-guard).
base-vs-stub resolution = ExtensionResolver::resolve() (ExtensionResolver.php), invoked at gCore.php:
  if force-disabled → stub ; elif class_exists(ProClass) → Pro ; else → stub.
  ∀ $baseManagers: 'stub' slot = Base class ⇒ absent-Pro yields FULL Base impl (NOT degraded).
  ∀ $stubOnlyManagers: 'stub' slot = no-op Stub.
  THIS single rule defines 'CH1 status'.
design philosophy: bootstrap-once-per-request singleton; stateless components, state in ValKey; capability-vector geometric discovery (gCore.php); strict topology dep-resolution (TopologyParser throws on circular).

## ::IO
builder boots: gCore::getInstance()->initialize($config) → $gCore->getService('<alias|Name>').
  getService aliases → 6 managers (see ::ANCHOR). Non-aliased Base managers → getService('<Name>Manager').
  getInstance()-only managers (no getService alias): HtaccessManager, IPBlockManager, VersionManager (getService WIP/absent CH1), BackupManager.
each manager surfaces: ModuleInterface lifecycle + getCapabilityVector() + its <Name>ManagerInterface methods.
gCore introspection: getExtensionStatus() (installed/mode full|stub/class/package), getMissingExtensionPackages(), getServiceRegistry(), getStatus().

## ::CONTRACT
PROVIDES (build-with manager APIs, accessed via container/getInstance):
  13 REAL-BASE: SecurityManager · ErrorManager · CacheManager · FormatManager · APIManager · StateManager · WordPressManager · CookieManager · ResourceManager · AssetManager · HtaccessManager · IPBlockManager · VersionManager.
  11 CH1-STUB (interface honored, behavior degraded): TemplateManager · TopologyManager · SEOManager · OptimizationManager · ManifestManager · MetricsManager · AnalyticsManager · CommsManager · InferenceManager · TranslateManager · EcommerceManager.
  2 install/deploy (build-with, lifecycle-flagged): InstallManager · BackupManager.
CONSUMES:
  gNode-Client → ValKey FCALL (GCORE_MGR_CONFIG_*, GCORE_MGR_SECRETS_*, GNODE_CACHE_*, GNODE_STREAM_*, GNODE_TRANSACTION_*, GNODE_LOCK_*) | streams | broadcast.
  ValKey → per-site keyspace {site_id}:gcore:config:<Manager>, secrets keyspace, cache/state/stream namespaces.
  WP → $wpdb (WordPressManager), hooks/admin/privacy-tools (CookieManager, VersionManager, OptimizationManager), transients (free-tier StorageFactory).
INTERNAL (plumbing classes, NOT build-with): ExtensionResolver + register.php · gNodeStorageAdapter · StorageFactory/StorageInterface/gNodeDetector · ManagerConfigTrait · StateManagerAware · ExtensionManagerErrorHandling + DependencyBundle · ConfigLoader/TopologyParser · SelfContainedErrorHandler · per-manager private helpers/value-objects · concrete WooCommerceAdapter.

## ::MANAGERS
— REAL BASE (CH1 = full impl; Base class canonical, no Pro) —
SecurityManager · public · getService(security|auth|crypto|SecurityManager) · roles/caps (in-memory CH1 by design), CSRF/JWT(HS256/384/512)/API-key, tiered rate-limit (T0 gNode FCALL→T1 luaIncrBy→T2 StateManager→T3 in-proc degraded). NOTE: self-labels 'Stub' but register.php = $baseManagers, no gcore-security ⇒ REAL BASE; do NOT promise Pro. services.yaml core p1.
ErrorManager · public · getService(error|logging|ErrorManager) · PHP error/exception/shutdown capture, dedup(MD5), rate-limit, TTL-by-level, multi-tier storage (gNode zset premium | StorageInterface free), admin email. gNode broadcast methods → false/[] without gNode (degrade, not stub). services.yaml core p2.
CacheManager · public · getService(cache|storage|CacheManager) · distributed cache, multi-site namespacing (cache:{site_id}:{node_id}:{key}), dual-mode (free-tier StorageFactory: WP-Transients→APCu→Memory | gNode FCALL). Extension-gated methods (streams, broadcast, content/template/asset, format-validation) throw StorageException without gNode. services.yaml core p3.
FormatManager · public · getService(format|detection|conversion|validation|FormatManager) · thin wrapper over gNode-Client FormatManager: register/detect/convert/validate + registry caching. gnode_required=true: ALL ops throw StorageException if use_gnode=false (no local fallback). services.yaml base p5.
APIManager · public · getService(api|rest|APIManager) · REST routing (exact + :param regex), middleware, endpoint registration, CORS, rate-limit (StateManagerAware soft-degrade), auth-delegate→SecurityManager. Standalone(PHP built-in server) + integrated modes. Full CH1. services.yaml base p6.
StateManager · public · getService(StateManager) (no alias) · distributed state, transactions(GNODE_TRANSACTION_*), observers, history, atomic counters, ArrayAccess. gnode_required=false: graceful in-memory cache fallback. Full CH1. services.yaml base p4.
WordPressManager · public · getService(WordPressManager) · DB cloning, DTAP env switching (wp-config swap), GDPR PII scrub. Operates on $wpdb, no gNode. Full CH1. services.yaml base p4.
CookieManager · public · getService(CookieManager) | CookieManager::getInstance() · GDPR consent, AES-256-CBC encrypt-then-MAC(HMAC-SHA256), WP privacy integration, expiry tracking. Full CH1 parity. requires ErrorManager+CacheManager (gCore.php). CookieManager.php; services.yaml feature p12.
ResourceManager · public · getService(ResourceManager) · asset bundling, template-fragment handling, asset caching via gNode. gNode-dependent methods throw StorageException without gNode; basic caching/optimization work without. ResourceManager.php; services.yaml feature p23.
AssetManager · public · getService(AssetManager) · CMS-agnostic asset storage, manifest-driven bundling, bundle retrieval via gNode-Client; graceful in-memory-only fallback. REAL BASE, NOT a stub. AssetManager.php.
HtaccessManager · public · HtaccessManager::getInstance() (no getService alias) · Apache .htaccess gen + locked read-modify-write(LOCK_EX, TOCTOU-safe) + IP-block section scaffolding. Build-with but niche: consumed by IPBlockManager + install flows. Full CH1. HtaccessManager.php.
IPBlockManager · public · IPBlockManager::getInstance() (no getService alias) · Apache-level IP block/unblock/list/expire via .htaccess; delegates path+section to HtaccessManager. comment-injection defense (strip CR/LF/#). Full CH1. IPBlockManager.php.
VersionManager · public · VersionManager::getInstance() ONLY (getService alias WIP/absent CH1) · version tracking + cache invalidation, multi-tenant, gNode atomic INCR/DECR fallback to in-memory. Full CH1. FLAG: document getInstance() as ONLY supported access. VersionManager.php.

— CH1 STUB (no Base/ dir; no-op/degraded; Pro ships CH2; logs one-time WP_DEBUG upgrade notice; routed via ExtensionResolver::resolve() at gCore.php) —
  document ONLY stub behavior; name Pro exists, do NOT document Pro methods.
TemplateManager · public · getService(template|rendering|tera|TemplateManager) · CH1 STUB: basic {{var}} substitution + form security (CSRF via CacheManager, honeypot, timing, XSS patterns, deepSanitize) WORK; template registration/persistence/Tera engine = no-op/null. Pro: gCore\Template\TemplateManagerPro (gcore-template).
TopologyManager · public · getService(TopologyManager) · CH1 STUB: discovery/registration return []/false; returns 23 read-only stub dimensions (UI-only). Pro: gCore\Topology\TopologyManagerPro.
SEOManager · public · getService(SEOManager) · CH1 STUB: in-memory meta/schema/sitemap/robots (no persistence); ALL GEO/AIO methods empty/error (need InferenceManager). Pro: gCore\SEO\SEOManagerPro.
OptimizationManager · public · getService(OptimizationManager) · CH1 STUB: full no-op/passthrough; exclusion lists in-memory only. Pro: gCore\Optimization\OptimizationManagerPro.
ManifestManager · public · getService(ManifestManager) · CH1 STUB: minimal PWA manifest + basic SW registration; icon-validation hardcoded false; install-tracking no-op. Pro: gCore\Manifest\ManifestManagerPro.
MetricsManager · public · getService(MetricsManager) · CH1 STUB: StateManager-backed counters/latency-window WORK when StateManager present, else no-op; getMetricDetails always [] (Pro-only). Pro: gCore\Metrics\MetricsManagerPro.
AnalyticsManager · public · getService(AnalyticsManager) · CH1 STUB: StateManager-backed daily visitor/pageview counters + cache-efficiency WORK when StateManager present; journeys/top-pages/resource-costs/multi-day all []. privacy-first sha256 hash, no PII. Pro: gCore\Analytics\AnalyticsManagerPro. services.yaml extension p102 (CORRECT).
CommsManager · public · TWO classes: (a) getService(CommsManager)=Stubs/CommsManagerStub INERT (no-op, channels disabled, daemon not_available) → Pro gCore\Comms\CommsManagerPro (CH2 bulk dispatch); (b) gCore\Modules\Comms\CommsManager (Modules/Comms/, impl CommsManagerInterface, used by CommsAdmin) = REAL CH1: reads {site}:gnode:comms:{env} (getRecentMessages/getStats/getDaemonStatus) + XADD test-send stamping top-level environment ( L1, 38b6a21). adheres COMMS msg contract. services.yaml extension (CORRECT).
InferenceManager · public · getService(InferenceManager) · CH1 STUB: all ML methods success=false error 'requires gcore-inference'. Pro: gCore\Inference\InferenceManagerPro. services.yaml extension (CORRECT). SEOManager GEO/AIO depends on this.
TranslateManager · public · getService(TranslateManager) · CH1 STUB: returns content untranslated, single default language, all methods no-op, isAvailable()=false. Pro: gCore\Translate\TranslateManagerPro. services.yaml extension (CORRECT).
EcommerceManager · public · getService(EcommerceManager) · CH1 STUB: adapter auto-detect (WooCommerceAdapter shipped) + delegation for cart/product/inventory; caching/analytics/checkout-security all no-op. NO Pro package yet — stub IS the ONLY impl. services.yaml extension (CORRECT).

— INSTALL/DEPLOY-TIME (build-with, FLAGGED not hidden; PRIMARY lifecycle = install/deploy, runtime APIs exposed) —
InstallManager · public · InstallManager::getInstance() ONLY · extension install, file-integrity vs geodineum.com registry, warranty tracking, gNode distributed locking. install/deploy-heavy BUT runtime verifyIntegrity()/getWarrantyInfo() callable by builders. NOT in services.yaml and loadServiceTopology does not merge geometric_topology.yaml services, so getService(InstallManager) is NOT wired in CH1 (throws); registry in geometric_topology.yaml; NO register.php entry (core-loaded, not extension-resolved). InstallManager.php.
BackupManager · public · BackupManager::getInstance() · filesystem backup/restore/retention under {base}/backups/. backup/restore lifecycle + Geodineum-BAK hooks (README public 'Backup hooks for Geodineum-BAK'); createBackup/restoreBackup/cleanOldBackups runtime-callable. No getService alias; not in services.yaml; not in register.php (core-loaded). BackupManager.php.

— INTERNAL-LIFECYCLE/PLUMBING SET (NOT build-with) —
ExtensionResolver + register.php (THE stub↔Pro dispatcher; architecturally load-bearing) · gNodeStorageAdapter (single shared instance injected gCore.php) · StorageFactory + StorageInterface + gNodeDetector (free-tier adapter selection behind Cache/Error/State) · ManagerConfigTrait (config/secret layering DEFAULTS→ValKey-per-site→$config; ManagerConfigTrait.php) · StateManagerAware trait (counter/window/dedup/rate-limit helpers; used by API/Security/Resource/Metrics-stub/Analytics-stub) · ExtensionManagerErrorHandling + DependencyBundle · ConfigLoader + TopologyParser · SelfContainedErrorHandler (dependency-free early-init logging) · per-manager private helpers/value-objects (Asset convertFaceMappingToManifest/getFacePosition; Backup copyDirectory/removeDirectory/getBackupDir; Htaccess withExclusiveLock; Install ~30 helpers + WARRANTY_STATUS/REQUIRED_DIRS; State getStateHashKey/serialize/notifyObservers) · EcommerceAdapterInterface = public extension-point, concrete WooCommerceAdapter = internal.

## ::USECASES
→ boot a WP or standalone app on gNode/ValKey with one container, get auth + cache + state + REST + error-capture out of the box.
→ build REST API (APIManager) with middleware + SecurityManager auth + StateManager rate-limit.
→ multi-tenant cache/state with per-site isolation + gNode distributed coherence (or free-tier fallback).
→ GDPR cookie consent + DTAP DB clone/scrub (CookieManager, WordPressManager).
→ asset/manifest bundling + .htaccess hardening + IP block firewall + version-keyed cache busting.
→ install/verify extensions against geodineum.com registry; filesystem backup/restore.
→ CH1-stub managers give a stable build-with interface today; drop in CH2 Pro package later with no code change.

## ::LIMITATIONS
CH1 stub degradations: 11 stub managers honor interface but no-op/degrade core features (see ::MANAGERS); SEO GEO/AIO blocked (InferenceManager stub → error); Metrics/Analytics need StateManager else no-op; Comms MANAGER-SLOT stub inert, but the admin class gCore\Modules\Comms\CommsManager is a real CH1 stream reader + test-send producer; Ecommerce stub IS only impl (no Pro yet).
REAL-BASE in-tree limitations (NOT stubs): SecurityManager roles in-memory-only (no ValKey persistence CH1); FormatManager throws without gNode (no local fallback); CacheManager/ResourceManager extension-gated methods throw StorageException without gNode; ErrorManager broadcast → false/[] without gNode; StateManager TTL-on-counter not applied via FCALL, history = single JSON string not LIST, deletion via __DELETED__ marker; VersionManager no getService alias CH1.
gotchas: services.yaml `class:` is VESTIGIAL for resolution — ExtensionResolver(register.php) OVERRIDES it (gCore.php); but `category`/`priority` ARE load-bearing (init order, gCore.php) + services.yaml is the compile-config→ValKey source + Tier-2 fallback (NOT dead). 7 stale `class:` reconciled to register.php 2026-06-22 (re-run compile-config to refresh ValKey). Classify managers from register.php/on-disk, never services.yaml class. SecurityManager = REAL BASE (audit 'self-labels Stub' was a FALSE POSITIVE — only legit free_tier ValKey-absent fallback).  non-prod gate IS implemented end-to-end (Modules/Comms/CommsManager stamps environment 38b6a21 + Geodineum-COMMS daemon enforces dry-run); only bulk/programmatic dispatch is CH2 gcore-comms.
failure modes: APIManager auth → hard 503 if SecurityManager absent (not graceful). IPBlockManager↔HtaccessManager hard dep. CookieManager hard dep ErrorManager+CacheManager. singleton-only (no DI). circular topology → TopologyParser throws (strict, no fallback). one gCore instance/process (no clone/wakeup).

## ::GRAPH
DEPENDS_ON: gCore → gNode-Client → ValKey ; gCore → ConfigLoader/TopologyParser/ExtensionResolver ; ∀ ValKey-managers → gNodeStorageAdapter (singleton) ; FormatManager → gNode-Client (hard) ; CookieManager → ErrorManager + CacheManager (hard) ; APIManager → SecurityManager (hard 503) + StateManager (soft) ; SecurityManager → StateManager (T2 rate-limit) + gNode-Client (T0/T1) ; IPBlockManager → HtaccessManager (hard) ; VersionManager → CacheManager ; ResourceManager → CacheManager+OptimizationManager+MetricsManager+StateManager (all soft) ; SEOManager(stub) → InferenceManager(stub) ⇒ GEO/AIO=error ; InstallManager → ErrorManager+CacheManager+gNode-Client+geodineum.com.
PROVIDES_TO: gCore.getService → builder app/plugin code ; managers → <Name>ManagerInterface contracts (Modules/Core/Interfaces/Extensions/) ; HtaccessManager → IPBlockManager + install flows ; BackupManager → Geodineum-BAK hooks ; InstallManager.subscribeToNotifications → gNode PubSub (gcore:install:notifications:{site_id}).
ADHERES_TO: ModuleInterface ∀ manager (universal lifecycle) ; init/priority-ascending load order (gCore.php bootstrapRequiredServices): Security(1)→Error(2)→Cache(3)→Template/State/WordPress(4)→Format/Topology(5)→API(6)→SEO(7)→Optimization(8)→Manifest(9); feature Cookie(12)/Metrics(15)/Resource(23); extension 100+ ; CommsManager settings ⊨ Rust ChannelConfig serde SHAPE (CH1 shape-only, no I/O) ; classifier ⊨ register.php $baseManagers/$stubOnlyManagers (services.yaml NON-authoritative, advisory metadata).
ISOLATED_FROM (CH2 Pro, EXCLUDED): all gcore-<short> Pro packages — TemplateManagerPro, TopologyManagerPro, SEOManagerPro, OptimizationManagerPro, ManifestManagerPro, MetricsManagerPro, AnalyticsManagerPro, CommsManagerPro, InferenceManagerPro, TranslateManagerPro, (no EcommerceManagerPro yet) ; CH2 Pro managers (out of scope) ; Pro method surfaces — named only, never documented here.

## ::LATENT
- "CH1 status = register.php on-disk classifier, NOT services.yaml (advisory metadata)"
- "ExtensionResolver one rule: $baseManagers absent-Pro ⇒ FULL Base ; $stubOnlyManagers ⇒ no-op Stub"
- "ALL first-party managers are PUBLIC build-with surface; stub ≠ private"
- "REAL-BASE degradations are in-tree gNode-gating/in-memory-by-design, NOT stub-vs-Pro splits"
- "getService aliases (8→6) vs getInstance()-only managers (Htaccess/IPBlock/Version/Backup)"
- "universal config + gNodeStorageAdapter singleton injection at gCore.php; ModuleInterface ∀ manager"
- "SecurityManager self-labels Stub = drift; it is REAL BASE, no Pro promised"
