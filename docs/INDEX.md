# gCore Documentation Index

Complete documentation reference for the gCore framework.

## Setup Guides

| Guide | Description |
|-------|-------------|
| [Guide-Installation.md](Guide-Installation.md) | Complete installation instructions for all deployment modes |
| [Guide-WordPress.md](Guide-WordPress.md) | WordPress MU-plugin deployment and configuration |
| [Guide-Standalone.md](Guide-Standalone.md) | Standalone PHP library usage |
| [Guide-Docker.md](Guide-Docker.md) | Docker container deployment |
| [Guide-CLI.md](Guide-CLI.md) | Command-line interface tools and commands |

## Architecture & Concepts

| Document | Description |
|----------|-------------|
| [Introduction.md](Introduction.md) | Framework overview and philosophy |
| [Architectural-Overview.md](Architectural-Overview.md) | System architecture and design patterns |
| [DeveloperGuide.md](DeveloperGuide.md) | Development practices and conventions |
| [../CONTRACT.md](../CONTRACT.md) | Component integration contract (gNode, ValKey, manager surface) |

## Component Documentation

| Component | Description |
|-----------|-------------|
| [Component-CacheManager.md](Component-CacheManager.md) | Distributed caching with ValKey/Redis |
| [Component-ErrorManager.md](Component-ErrorManager.md) | Error handling, logging, and recovery |
| [Component-APIManager.md](Component-APIManager.md) | REST API management and middleware |

## Security

| Document | Description |
|----------|-------------|
| [Security.md](Security.md) | Security architecture and best practices |

## Examples

| Document | Description |
|----------|-------------|
| [MessageBroker-Guide.md](MessageBroker-Guide.md) | Message broker implementation example |

## Manager Documentation

All 22 managers have a README in `managers/<Name>/`:

| Manager | Doc |
|---------|-----|
| [AnalyticsManager](managers/AnalyticsManager/README_AnalyticsManager.md) | `managers/AnalyticsManager/` |
| [APIManager](managers/APIManager/README_APIManager.md) | `managers/APIManager/` |
| [AssetManager](managers/AssetManager/README_AssetManager.md) | `managers/AssetManager/` |
| [CacheManager](managers/CacheManager/README_CacheManager.md) | `managers/CacheManager/` |
| [CommsManager](managers/CommsManager/README_CommsManager.md) | `managers/CommsManager/` |
| [CookieManager](managers/CookieManager/README_CookieManager.md) | `managers/CookieManager/` |
| [ErrorManager](managers/ErrorManager/README_ErrorManager.md) | `managers/ErrorManager/` |
| [FormatManager](managers/FormatManager/README_FormatManager.md) | `managers/FormatManager/` |
| [InferenceManager](managers/InferenceManager/README_InferenceManager.md) | `managers/InferenceManager/` |
| [InstallManager](managers/InstallManager/README_InstallManager.md) | `managers/InstallManager/` |
| [ManifestManager](managers/ManifestManager/README_ManifestManager.md) | `managers/ManifestManager/` |
| [MetricsManager](managers/MetricsManager/README_MetricsManager.md) | `managers/MetricsManager/` |
| [OptimizationManager](managers/OptimizationManager/README_OptimizationManager.md) | `managers/OptimizationManager/` |
| [ResourceManager](managers/ResourceManager/README_ResourceManager.md) | `managers/ResourceManager/` |
| [SecurityManager](managers/SecurityManager/README_SecurityManager.md) | `managers/SecurityManager/` |
| [SEOManager](managers/SEOManager/README_SEOManager.md) | `managers/SEOManager/` |
| [StateManager](managers/StateManager/README_StateManager.md) | `managers/StateManager/` |
| [TemplateManager](managers/TemplateManager/README_TemplateManager.md) | `managers/TemplateManager/` |
| [TopologyManager](managers/TopologyManager/README_TopologyManager.md) | `managers/TopologyManager/` |
| [TranslateManager](managers/TranslateManager/README_TranslateManager.md) | `managers/TranslateManager/` |
| [VersionManager](managers/VersionManager/README_VersionManager.md) | `managers/VersionManager/` |
| [WordPressManager](managers/WordPressManager/README_WordPressManager.md) | `managers/WordPressManager/` |

## File Naming Convention

- `Guide-*.md` - Setup and usage guides
- `Component-*.md` - Core component documentation
- `managers/<Name>/README_<Name>.md` - Per-manager docs
