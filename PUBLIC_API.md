# gCore — Public API

> **Generated — do not edit by hand.** Regenerate with `php scripts/gen-public-api.php`.
> Extracted from public method signatures + docblock summaries on the build-with
> surface. [`CONTRACT.md`](CONTRACT.md) is the authoritative prose contract; where the
> two differ, the code (and this generated index) win.

## Container

### `gCore`
<sub>`Modules/Core/gCore.php`</sub>

- `getInstance(): self` — Get singleton instance
- `initialize(array $config = []): void` — Initialize core system
- `isServiceActive(string $service): bool` — Check if service is active
- `getService(string $service = null, array $capabilities = []): object` — Get service instance
- `getStorage(): ?\gCore\gNode\Storage\ValKeyStorage` — Get the shared gNode storage instance
- `getStorageAdapter(): ?\gCore\Modules\Storage\gNodeStorageAdapter` — Get the shared gNode storage adapter (singleton)
- `getExtensionStatus(): array` — Get status of all extension packages
- `isExtensionInstalled(string $managerName): bool` — Check if a specific extension package is installed
- `getMissingExtensionPackages(): array` — Get installation instructions for missing extension packages
- `findServiceByCapabilities(array $capabilities): object` — Find service based on capability requirements
- `registerServiceCapabilities(string $serviceName, array $capabilities, array $metadata = []): bool` — Register capabilities for a service
- `hasService(string $service): bool` — Check if a service exists in the registry
- `getServiceStatus(string $service): array` — Get service status information
- `getStatus(): array` — Get overall system status
- `getServiceRegistry(): array` — Get the service registry (primarily for REST API)
- `shutdown(bool $graceful = true): bool` — Shutdown gCore framework with proper resource cleanup

## Universal lifecycle (every manager implements this)

### `ModuleInterface`
<sub>`Modules/Core/Interfaces/ModuleInterface.php`</sub>

- `getInstance(): self` — Get singleton instance
- `initialize(array $config = []): void` — Initialize the module
- `getConfig(): array` — Get module configuration
- `updateConfig(array $config): void` — Update module configuration
- `isInitialized(): bool` — Check if module is initialized
- `getStatus(): array` — Get module status information

## First-party (Base) managers

### `APIManager`
<sub>`Modules/Managers/Base/APIManager/APIManager.php`</sub>

- `addMiddleware(string $name, callable $handler): bool` — Add middleware
- `registerEndpoint(string $method, string $path, callable $handler, array $options = []): bool` — Register API endpoint
- `start(int $port = null, string $host = null): bool` — Start the API server
- `processRequest(): void` — Process the current HTTP request

### `AssetManager`
<sub>`Modules/Managers/Base/AssetManager/AssetManager.php`</sub>

- `storeAsset(string $assetId, string $content, string $contentType = 'text/html', array $options = []): array` — Store an asset
- `getAsset(string $assetId): ?array` — Retrieve an asset
- `deleteAsset(string $assetId): bool` — Delete an asset
- `listAssets(?string $prefix = null): array` — List assets
- `assetExists(string $assetId): bool` — Check if asset exists
- `setManifest(string $manifestId, array $manifest): array` — Create or update a manifest
- `getManifest(string $manifestId): ?array` — Retrieve a manifest
- `deleteManifest(string $manifestId): bool` — Delete a manifest
- `listManifests(): array` — List manifest IDs
- `getBundle(string $manifestId = 'main', bool $decompress = true): ?array` — Retrieve a built bundle
- `getBundleStatus(string $manifestId): ?array` — Get build status for a manifest's bundle
- `invalidateBundle(string $manifestId = 'main'): bool` — Invalidate a bundle to trigger rebuild
- `syncFaceMapping(array $faceMapping): bool` — Sync legacy face_mapping to both old key and new manifest

### `BackupManager`
<sub>`Modules/Managers/Base/BackupManager/BackupManager.php`</sub>

- `createBackup(string $name, string $path): ?string` — Create a timestamped backup of a file or directory
- `restoreBackup(string $backupPath, string $targetPath): bool` — Restore a backup over a target path. For directory backups, the
- `cleanOldBackups(int $retentionDays = 30): int` — Remove backups older than $retentionDays. Returns the count removed

### `CacheManager`
<sub>`Modules/Managers/Base/CacheManager/CacheManager.php`</sub>

- `batchSet(array $items): array` — Batch set multiple cache values using gNode executeBatch
- `batchGet(array $keys): array` — Batch get multiple cache values using gNode executeBatch
- `batchDelete(array $keys): array` — Batch delete multiple cache values using gNode executeBatch
- `storeContent( string $key, string $content, string $contentType = 'text/html', bool $minify = true, int $ttl = 0 ): array` — Store content with automatic minification and compression
- `retrieveContent(string $key): ?array` — Retrieve stored content with automatic decompression
- `storeTemplate( string $id, string $content, array $dependencies = [], array $variables = [], ?int $ttl = null ): array` — Store template fragment with dependency tracking
- `storeAssetBundle( string $bundleId, array $assets, string $bundleType = 'mixed', bool $minify = true, ?int $ttl = null ): array` — Store asset bundle with automatic optimization
- `broadcastInvalidate(array $keys, string $reason = 'manual'): string|false` — Broadcast cache invalidation message to all nodes
- `broadcastClearAll(string $reason = 'manual'): string|false` — Broadcast clear all cache message to all nodes
- `listenForInvalidations(int $count = 10, int $blockMs = 100): array` — Listen for cache invalidation broadcasts from other nodes
- `enableNativeMode(): bool` — Enable native RESP3 mode for lower protocol overhead
- `disableNativeMode(): bool` — Disable native RESP3 mode
- `isNativeMode(): bool` — Check if native RESP3 mode is enabled
- `registerFormat(string $formatId, array $schema): bool` — Register a custom data format for validation
- `validateData(string $formatId, $data): bool` — Validate data against a registered format
- `setWithValidation(string $key, $value, string $formatId, int $ttl = 0): bool` — Set cache value with format validation
- `set(string $key, $value, int $ttl = 0): bool` — Set cache item
- `get(string $key)` — Get cache item
- `delete(string $key): bool` — Delete cache item
- `exists(string $key): bool` — Check if cache item exists
- `increment(string $key, int $by = 1)` — Increment cache value
- `decrement(string $key, int $by = 1)` — Decrement cache value
- `setNx(string $key, $value, int $ttl = 0): bool` — Set if not exists
- `getMultiple(array $keys): array` — Get multiple cache items
- `setMultiple(array $items, int $ttl = 0): bool` — Set multiple cache items
- `deleteMultiple(array $keys): bool` — Delete multiple cache items
- `clear(): bool` — Clear cache
- `getKeys(string $pattern): array` — Get keys matching a pattern
- `getMetrics(): array` — Get cache metrics (enhanced with gNode stats when available)
- `streamAdd(string $stream, array $data, array $options = []): string|false` — Add entry to stream with automatic backpressure and consumer notification
- `streamReadGroup( string $stream, string $group, string $consumer, int $count = 100, int $block = 0, string $id = '>' ): array` — Read from stream using consumer group
- `streamAck(string $stream, string $group, string|array $id): bool` — Acknowledge processed stream entry
- `streamClaim(string $stream, string $group, string $consumer, int $min_idle_time, array $ids): array` — Claim pending entries for consumer
- `streamTrim(string $stream, int $maxlen): int` — Trim stream to specific length

### `CookieManager`
<sub>`Modules/Managers/Base/CookieManager/CookieManager.php`</sub>

- `setCookie(string $name, $value, array $options = [], ?string $category = null): bool` — Set cookie with security options and expiry tracking
- `getCookie(string $name, $default = null)` — Get cookie value (with decryption if enabled)
- `deleteCookie(string $name): bool` — Delete a cookie
- `getCookieExpiry(string $name): ?array` — Get expiry information for a cookie
- `extendCookieExpiry(string $name, int $additionalTime): bool` — Extend a cookie's expiry
- `refreshCookie(string $name): bool` — Refresh a cookie (reset TTL to original duration)
- `getCookiesExpiringSoon(int $withinSeconds = 86400): array` — Get all cookies expiring within a time window
- `getExpiredCookies(): array` — Get all expired cookies
- `cleanupExpiredTracking(): int` — Clean up expired cookie tracking
- `hasConsent(string $category): bool` — Check if user has consent for category
- `updateConsent(array $preferences): bool` — Update consent preferences
- `displayConsentBanner(): void` — Display consent banner (WordPress-specific)
- `registerExporter(array $exporters): array` — Register data exporter (WordPress-specific)
- `exportPersonalData(string $email_address): array` — Export personal data (WordPress-specific)
- `registerEraser(array $erasers): array` — Register data eraser (WordPress-specific)
- `erasePersonalData(string $email_address): array` — Erase personal data (WordPress-specific)

### `ErrorManager`
<sub>`Modules/Managers/Base/ErrorManager/ErrorManager.php`</sub>

- `handleError( int $level, string $message, string $file, int $line, array $context = [] ): bool` — Handle PHP errors
- `handleException(\Throwable $exception): void` — Handle exceptions
- `handleShutdown(): void` — Handle script shutdown (capture fatal errors)
- `trackError(string $level, string $message, array $context = []): bool` — Track error with context
- `trackSystemEvent(string $level, string $message, array $context = []): bool` — Track system event
- `notifyAdmin(string $subject, string $message, array $details = []): bool` — Send notification to admin
- `getRecentErrors(int $limit = 10, int $offset = 0): array` — Get recent errors
- `getErrorStats(): array` — Get error statistics
- `clearErrorHistory(): bool` — Clear error history
- `log(string $level, string $message, array $context = []): bool` — Log a message
- `logCriticalError(string $message, array $context = [], bool $broadcast = true): bool` — Broadcast critical error and log locally

### `FormatManager`
<sub>`Modules/Managers/Base/FormatManager/FormatManager.php`</sub>

- `registerFormat(string $name, array $schema, array $patterns = [], array $metadata = []): array` — Register a format. Delegates to gNode-Client's FM for caching
- `listFormats(): array` — List all registered formats. Client FM caches results
- `getFormat(string $name): ?array` — Get a specific format definition. Uses client FM cache
- `deleteFormat(string $name): bool` — Delete a format. No client FM equivalent — uses executeCommand
- `detectFormat(string $message): array` — Detect message format. Client FM tries local cache first
- `detectAndValidate(string $message): array` — Detect format and validate in one step
- `convertFormat( string $message, string $sourceFormat, string $targetFormat, array $options = [] ): array` — Convert message between formats
- `autoConvertFormat(string $message, string $targetFormat, array $options = []): array` — Auto-detect source format and convert
- `validateMessage(string $message, string $formatName): array` — Validate message against a format's schema
- `registerFormats(array $formats): array`
- `detectFormats(array $messages): array`

### `HtaccessManager`
<sub>`Modules/Managers/Base/HtaccessManager/HtaccessManager.php`</sub>

- `setupHtaccess(): bool` — Set up .htaccess with gCore security rules
- `addHtaccessRule(string $rule, string $section = 'Custom'): bool` — Add a custom rule under a named gCore .htaccess section
- `getHtaccessPath(): ?string` — Resolve the .htaccess file path: configured → WordPress ABSPATH →
- `generateHtaccessRules(): string` — Canonical gCore security ruleset (includes the empty IP-block section
- `ensureIPBlockSection(string $htaccessPath): void` — Idempotently ensure the IP-block section exists in .htaccess so

### `IPBlockManager`
<sub>`Modules/Managers/Base/IPBlockManager/IPBlockManager.php`</sub>

- `blockIP(string $ip, string $reason = '', ?int $duration = null): bool` — Add IP to .htaccess block list
- `unblockIP(string $ip): bool` — Remove IP from .htaccess block list
- `getBlockedIPs(): array` — Get list of blocked IPs with metadata
- `cleanExpiredBlocks(): int` — Clean expired IP blocks. Should be called periodically (e.g. daily cron)

### `InstallManager`
<sub>`Modules/Managers/Base/InstallManager/InstallManager.php`</sub>

- `getAvailableExtensions(?string $type = null): array` — Get available extensions from geodineum.com registry
- `installExtension(string $extensionId, ?string $licenseKey = null): array` — Install an extension from geodineum.com
- `updateExtension(string $extensionId): array` — Update an installed extension
- `getInstalledExtensions(): array` — Get installed extensions
- `verifyIntegrity(bool $force = false): array` — Verify system integrity against geodineum.com hash registry
- `getWarrantyInfo(): array` — Get warranty information
- `validateLicense(string $licenseKey, ?string $product = null): bool` — Validate a license key
- `setupEnvironment(): array` — First-run environment setup
- `validateEnvironment(): array` — Validate environment requirements
- `subscribeToNotifications(callable $callback): bool` — Subscribe to installation notifications

### `ResourceManager`
<sub>`Modules/Managers/Base/ResourceManager/ResourceManager.php`</sub>

- `createAssetBundle( string $bundleId, array $assets, string $bundleType = 'mixed', bool $minify = true, ?int $ttl = null ): array` — Create an asset bundle using native gNode assetBundle method
- `loadAsset(string $assetId, bool $useCache = true): ?array` — Load an asset by identifier
- `batchLoadAssets(array $assetIds): array` — Batch load multiple assets using gNode executeBatch
- `optimizeAsset(string $content, string $type, array $options = []): string` — Optimize an asset (minify, compress)
- `storeTemplateFragment( string $templateId, string $content, array $dependencies = [], array $variables = [], ?int $ttl = null ): array` — Store template fragment using native gNode templateFragment method
- `discoverTemplatesByCapability(array $capabilities, int $limit = 10): array` — Discover templates by capability using gNode geometric discovery
- `renderTemplateString( string $template, array $variables = [], array $config = [] ): string` — Render template string with variables using gNode
- `loadResource(string $url, string $type = 'auto'): ?array` — Load a resource by URL or identifier
- `preloadResources(array $resources): array` — Preload resources for performance
- `warmupCache(array $resources): int` — Warmup cache with resources

### `SecurityManager`
<sub>`Modules/Managers/Base/SecurityManager/SecurityManager.php`</sub>

- `defineRole(string $role, array $permissions): bool` — Define a role with permissions
- `assignRole(string $user, string $role): bool` — Assign role to user
- `hasPermission(string $user, string $permission): bool` — Check if user has permission
- `hasCapability(string $user, string $capability): bool` — Check if user has capability
- `getUserCapabilities(string $user): array` — Get user capabilities
- `validateCsrfToken(string $token, string $action = 'default'): bool` — Validate CSRF token
- `generateCsrfToken(string $action = 'default', int $ttl = 3600): string` — Generate CSRF token
- `validateJWT(string $token, array $options = []): array` — Validate a JWT token
- `generateJWT(array $payload, array $options = []): string` — Generate a JWT token
- `validateAPIKey(string $apiKey, array $options = []): array` — Validate an API key
- `createAPIKey(array $data = []): array` — Create a new API key
- `revokeAPIKey(string $apiKey): bool` — Revoke an API key
- `setgNodeClient($client): void` — Set the gNode-Client for centralized rate limiting with metrics
- `validateAPIRequest($request, array $options = []): array` — Validate an API request with rate limiting and parameter validation

### `StateManager`
<sub>`Modules/Managers/Base/StateManager/StateManager.php`</sub>

- `setState(string $key, $value, bool $skipValidation = false): bool` — Set state value with validation, middleware, and history tracking
- `getState(string $key, $default = null)` — Get state value
- `removeState(string $key): void` — Remove state value
- `hasState(string $key): bool` — Check if state key exists
- `increment(string $key, int $delta = 1, ?int $ttl = null): int` — Atomically increment a counter
- `decrement(string $key, int $delta = 1, ?int $ttl = null): int` — Atomically decrement a counter
- `compareAndSwap(string $key, $expected, $new): bool` — Compare-and-swap operation (atomic conditional update)
- `subscribe(string $key, callable $callback, ?string $observerId = null): string` — Subscribe to state changes for a specific key
- `unsubscribe(string $key, string $observerId): bool` — Unsubscribe from state changes
- `publish(string $key, $value): void` — Publish state change via gNode pub/sub for distributed observers
- `beginTransaction(int $timeout = 300): string` — Begin a new transaction
- `commitTransaction(): bool` — Commit the current transaction
- `rollbackTransaction(): bool` — Rollback the current transaction
- `getHistory(?string $key = null, int $limit = 50): array` — Get state change history
- `registerValidator(string $key, callable $validator, ?string $validatorId = null): string` — Register a validator for a state key
- `addMiddleware(callable $middleware, int $priority = 100): string` — Add middleware to the state change pipeline
- `restoreState(): void` — Restore state from persistent storage
- `persistState(): void` — Persist state to storage (bulk write)
- `offsetExists($offset): bool`
- `offsetGet($offset): mixed`
- `offsetSet($offset, $value): void`
- `offsetUnset($offset): void`

### `VersionManager`
<sub>`Modules/Managers/Base/VersionManager/VersionManager.php`</sub>

- `getVersion(string $group = 'core'): int` — Get version for a specific group
- `incrementVersion(string $group = 'core', int $amount = 1): int` — Increment version for a specific group
- `decrementVersion(string $group = 'core', int $amount = 1): int` — Decrement version for a specific group
- `resetVersion(string $group = 'core', int $resetTo = 1): int` — Reset version for a specific group to initial value
- `getHistory(?string $group = null, int $limit = 50): array` — Get version history for a group
- `clearHistory(?string $group = null): bool` — Clear version history
- `incrementAllVersions(): void` — Increment all versions
- `registerGroup(string $group, int $initial_version = 1): bool` — Register a new cache group
- `getPrefix(string $group = 'core'): string` — Get prefix for a group
- `generateKey(string $key, string $group = 'core'): string` — Generate full cache key with multi-tenant isolation

### `WordPressManager`
<sub>`Modules/Managers/Base/WordPressManager/WordPressManager.php`</sub>

- `cloneDatabase(string $target_env, bool $drop_existing = false): array` — {@inheritdoc}
- `swapDatabase(string $target_db): array` — {@inheritdoc}
- `getProductionDbName(): string` — {@inheritdoc}
- `getEnvironmentDbName(string $environment): string` — {@inheritdoc}
- `scrubPII(bool $confirm = false, array $options = []): array` — {@inheritdoc}
- `getScrubPreview(): array` — {@inheritdoc}
- `isScrubSafe(): bool` — {@inheritdoc}
- `getEnvironmentInfo(): array` — {@inheritdoc}

