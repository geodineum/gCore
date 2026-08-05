<p align="center">
  <a href="https://geodineum.com">
    <img src="https://geodineum.com/wp-content/uploads/2026/07/logo_geodineum_launch.png" alt="Geodineum" width="128">
  </a>
</p>

# gCore

A PHP service-container framework: application code stays stateless, with all state held in ValKey.

Built by **Niels Erik Toren** · distributed as `geodineum/gcore` (Composer)

---

## What it is

gCore is a singleton *manager-of-managers*: one container that bootstraps, configures, and hands out a roster of first-party managers - cache, state, security, format, REST API, and more - to application and plugin builders. Components are stateless yet state-aware: configuration and state live in ValKey, namespaced per site, so any node can serve any request.

It runs in three shapes - a WordPress MU-plugin, a standalone PHP library, or Docker - over a ValKey backend, optionally fronted by the gNode daemon. Where gNode is absent, managers degrade gracefully rather than fail. It is one component of the Geodineum ecosystem, alongside gNode, gNode-Client, and gTemplate.

## Public build surface

The build-with surface is the **container** and the **manager classes** - nothing beneath them.

- **Container** - `gCore::getInstance()`, then `initialize(array $config)`, then `getService(string $name)`. This is the only entry point; it returns a manager by name, by capability alias, or by capability vector.
- **Managers** - every first-party manager is reached through `getService()`. A manager's class and its `Modules/Core/Interfaces/Extensions/<Name>ManagerInterface` are its public contract; every manager also implements the universal `ModuleInterface` lifecycle.
- **Internal** - the helpers, traits, adapters, value objects, the `ExtensionResolver`, and the storage plumbing beneath the managers are not part of the surface and may change between releases.

The complete per-symbol index - every public method, its signature, and a one-line summary - is **generated** from source into **[`PUBLIC_API.md`](PUBLIC_API.md)** (`php scripts/gen-public-api.php`). Prose and behavior live in **[`CONTRACT.md`](CONTRACT.md)**; this README never re-hosts either.

## Managers

Each manager is a singleton reached through `getService()`. The **first-party (Base)** managers ship complete in this repo - each has a focused guide under `docs/`. The **extension** managers ship inert stubs here and light up when their Chapter-2 package (its own repo, `geodineum/gcore-<name>`) is installed.

### First-party (Base) - full implementation

| Manager | What it does | Guide |
|---|---|---|
| SecurityManager | Roles/capabilities, CSRF, JWT, API keys, tiered rate-limiting | [docs/README_SECURITY.md](docs/README_SECURITY.md) |
| CacheManager | Distributed cache over ValKey, per-site isolation, dual free-tier / gNode mode | [docs/README_CACHE.md](docs/README_CACHE.md) |
| StateManager | Distributed state, transactions, observers, atomic counters | [docs/README_STATE.md](docs/README_STATE.md) |
| ErrorManager | Error/exception/shutdown capture, dedup, level-TTL, admin alerts | [docs/README_ERROR.md](docs/README_ERROR.md) |
| FormatManager | Data-format detection + conversion (delegates to gNode) | [docs/README_FORMAT.md](docs/README_FORMAT.md) |
| APIManager | REST routing, middleware pipeline, rate-limit / CORS | [docs/README_API.md](docs/README_API.md) |
| ResourceManager | Asset bundling, template fragments, asset caching | [docs/README_RESOURCE.md](docs/README_RESOURCE.md) |
| AssetManager | CMS-agnostic asset + manifest storage, daemon-built bundle retrieval | [docs/README_ASSET.md](docs/README_ASSET.md) |
| CookieManager | GDPR consent, encrypted cookies, WordPress privacy tools | [docs/README_COOKIE.md](docs/README_COOKIE.md) |
| VersionManager | Version tracking + cache invalidation, multi-tenant | [docs/README_VERSION.md](docs/README_VERSION.md) |
| WordPressManager | DB cloning, DTAP env switching, GDPR PII scrub | [docs/README_WORDPRESS.md](docs/README_WORDPRESS.md) |
| HtaccessManager | Locked `.htaccess` composition + write-out | [docs/README_HTACCESS.md](docs/README_HTACCESS.md) |
| IPBlockManager | Apache-level IP block/unblock/expire via `.htaccess` | [docs/README_IPBLOCK.md](docs/README_IPBLOCK.md) |
| InstallManager | Extension install + file-integrity verification (install/deploy) | [docs/README_INSTALL.md](docs/README_INSTALL.md) |
| BackupManager | Filesystem backup/restore/retention (install/deploy) | [docs/README_BACKUP.md](docs/README_BACKUP.md) |

### Extension managers - stub in Chapter 1, Pro in Chapter 2

Inert until their package is installed; calling one returns a `stub_mode` marker. Each Pro implementation ships in Chapter 2 - see the ecosystem overview at **[geodineum.com](https://geodineum.com/chapter-2)**.

| Manager | Chapter-2 package | What the Pro adds |
|---|---|---|
| TemplateManager | `geodineum/gcore-template` | Tera templating via the gNode daemon |
| SEOManager | `geodineum/gcore-seo` | Persisted SEO + AI/GEO meta generation |
| InferenceManager | `geodineum/gcore-inference` | LLM inference via the gNode LLM tier |
| TranslateManager | `geodineum/gcore-translate` | Translation pipeline via Geodine |
| AnalyticsManager | `geodineum/gcore-analytics` | Visitor journeys, multi-day analytics |
| MetricsManager | `geodineum/gcore-metrics` | Full metric detail + history |
| OptimizationManager | `geodineum/gcore-optimization` | Real asset/DB optimization passes |
| ManifestManager | `geodineum/gcore-manifest` | Full PWA manifest + install tracking |
| TopologyManager | `geodineum/gcore-topology` | Live geometric topology + visualization |
| CommsManager | `geodineum/gcore-comms` | Programmatic multi-channel dispatch |
| EcommerceManager | *(stub only - pluggable via `EcommerceAdapterInterface`)* | - |

> The Chapter-1 comms **dashboard/test-send** surface (`Modules/Comms/CommsManager`) is real and separate from the inert `CommsManager` stub slot - see `CONTRACT.md` §3.8.

## Capabilities

- **Service container** - lazy-loads and lifecycles a roster of first-party managers behind a single `getService()` call, in dependency order.
- **ValKey-native primitives** - distributed cache, state coordination, security (roles, CSRF, JWT, API keys, tiered rate-limiting), error tracking, versioning, cookie consent, asset bundling, `.htaccess` / IP blocking, and a REST layer.
- **Geometric service discovery** - services are found by capability vector, not by name: nearest-neighbour search in the gNode daemon when present, a local capability→manager heuristic otherwise.
- **Open-core** - eleven manager slots ship as inert stubs and light up when their Chapter-2 `geodineum/gcore-<name>` package is installed, with no change in the calling code.
- **Per-site isolation** - every key is namespaced by site, so one ValKey backend serves many sites.

## Contract

The precise integration surface - container entry points, capability aliases, each manager's access path / signatures / behavior, the base-vs-stub resolution rule, and what gCore consumes and requires - is documented in **[`CONTRACT.md`](CONTRACT.md)**. Agents should prime from **[`CONTRACT.scn.md`](CONTRACT.scn.md)**.

## Quick start

Standalone - the minimal path that always runs:

```php
require '/path/to/gCore/bootstrap.php';

$gCore = \gCore\Modules\Core\gCore::getInstance();
$gCore->initialize([
    'site_id' => 'my_app',
    'node_id' => 'node1',
    'storage' => ['host' => '127.0.0.1', 'port' => 47445],
]);

$cache = $gCore->getService('cache');   // by capability alias
$cache->set('greeting', 'hello', 300);
echo $cache->get('greeting');           // hello
```

The WordPress MU-plugin and Docker deployment paths are described in `CONTRACT.md` and `docs/`.

## Limits worth knowing

- **ValKey is required.** All persistent state lives there; there is no file or SQLite fallback.
- **gNode is optional, but some managers need it.** `FormatManager` and the extension-gated cache, stream, and broadcast methods throw without a reachable daemon.
- **The stub managers are inert until their package is installed.** Calling one in Chapter 1 returns a `stub_mode` marker, not a result; the full implementation ships as a separate Chapter-2 package.
- **`ext-igbinary` is a hard requirement** - gCore will not bootstrap without it.

## Collaborate

Contributions are welcome. Open issues and pick up work on the ecosystem board
at [geodineum.com](https://geodineum.com); issues tagged `good-first-issue` are
a good place to start.

- Fork, branch, and open a pull request against `main`.
- Any change to a wire contract must update **both** `CONTRACT.md` and
  `CONTRACT.scn.md` in the same commit.
- A change to a signed extension must be re-signed in the same commit.

## Author & support

Built by **Niels Erik Toren**.

If you want to support the work:

| Currency | Address |
|---|---|
| Bitcoin (BTC) | `bc1qwf78fjgapt2gcts4mwf3gnfkclvqgtlg4gpu4d` |
| Ethereum (ETH) | `0xf38b517Dd2005d93E0BDc1e9807665074c5eC731` / `nierto.eth` |
| Monero (XMR) | `8BPaSoq1pEJH4LgbGNQ92kFJA3oi2frE4igHvdP9Lz2giwhFo2VnNvGT8XABYasjtoVY2Qb3LVHv6CP3qwcJ8UnyRtjWRZ5` |

## Disclaimer

This software is provided **"as is"**, without warranty of any kind, express or
implied. Use of this software is entirely at your own risk. In no event shall the
author or contributors be held liable for any damages arising from the use or
inability to use this software.

## License

Licensed under either of

* [Apache License, Version 2.0](LICENSE-APACHE)
* [MIT License](LICENSE-MIT)

at your option.
