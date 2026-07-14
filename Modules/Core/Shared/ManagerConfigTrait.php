<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Shared;

/**
 * Shared config-access helpers for gCore managers.
 *
 * Bridge between PHP-side managers and the GCORE_MGR_CONFIG_* FCALL
 * surface (gnode_gcore_config.lua, registered with ValKey at daemon
 * startup). Provides a uniform read/write contract so individual
 * managers don't reimplement the same fcall + JSON-encode dance.
 *
 * Architectural goal: kill the scattered YAML-files / hardcoded-
 * array_merge config pattern. Every manager that uses this trait
 * reads its effective config from ValKey at initialize() and writes
 * runtime updates through it — one canonical source of truth across
 * the ecosystem (mirrors how gNode's own config lives in
 * gnode_config.lua's {site}:config:{category} keyspace).
 *
 * Encoding contract on the wire:
 *   - Arrays / objects → JSON-encoded string
 *   - Booleans         → 'true' / 'false' string (NOT '1'/'0' — avoids
 *                        bool-vs-int ambiguity when decoded)
 *   - Numbers          → numeric string (int vs float decided at decode
 *                        time by presence of '.')
 *   - Strings          → bare string
 *   - null             → empty string / absence (caller must use default)
 *
 * Decode is the inverse, with JSON-first preference for values whose
 * first character is '{' or '[' (unambiguous container markers).
 *
 * Usage:
 *   class MyManager {
 *       use \gCore\Modules\Core\Shared\ManagerConfigTrait;
 *
 *       public function initialize(array $config = []): void {
 *           $siteId = $config['site_id'] ?? 'default';
 *           $storage = $this->resolveStorage();  // returns fcall-capable client
 *           $this->config = array_merge(
 *               self::DEFAULTS,                                          // last-resort hardcoded floor
 *               $this->gcoreLoadConfig($storage, $siteId, 'MyManager'),  // ValKey: defaults + per-site
 *               $config                                                  // caller passthrough (highest priority)
 *           );
 *       }
 *   }
 *
 * Storage parameter contract: anything with a public `fcall(string $name,
 * array $keys, array $args)` method. In practice this is either
 * \gCore\gNode\Storage\ValKeyStorage (from gNode-Client) or a
 * compatible adapter.
 *
 * @since 2026-05-22 (Wave 0 config homogenization)
 * @see  daemon/functions/gnode_gcore_config.lua  for the FCALL surface
 * @see  daemon/functions/gnode_config.lua        for the gNode-side precedent
 */
trait ManagerConfigTrait
{
    /**
     * Load the merged effective config (bootloader-seeded defaults +
     * per-site overrides) for this manager. Used by initialize().
     *
     * One ValKey round trip via GCORE_MGR_CONFIG_HGETALL. The Lua side
     * merges {default}:gcore:config:<Manager> with
     * {site_id}:gcore:config:<Manager>, with site overrides winning.
     *
     * On any failure (ValKey down, fcall unavailable, malformed
     * response), returns an empty array — caller's own hardcoded
     * defaults provide the floor.
     *
     * @param object $storage fcall-capable storage adapter
     * @param string $siteId Per-site namespace (e.g. 'example_site')
     * @param string $managerName Manager class short-name (e.g. 'CacheManager')
     * @return array<string,mixed> Decoded config (keys → typed PHP values)
     */
    protected function gcoreLoadConfig(object $storage, string $siteId, string $managerName): array
    {
        try {
            $result = $storage->fcall('GCORE_MGR_CONFIG_HGETALL', [], [$siteId, $managerName]);
            if (!is_array($result)) {
                return [];
            }
            // Lua HGETALL response is flat [k1, v1, k2, v2, ...]
            $assoc = [];
            $n = count($result);
            for ($i = 0; $i + 1 < $n; $i += 2) {
                $key = (string)$result[$i];
                $value = $result[$i + 1];
                $assoc[$key] = $this->gcoreDecodeConfigValue($value);
            }
            return $assoc;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get a single config value. Tries per-site override first, falls
     * back to bootloader-seeded default, then to caller-supplied
     * default. Single ValKey round trip.
     *
     * @param mixed $default Returned if neither ValKey layer has the key
     * @return mixed Decoded config value, or $default
     */
    protected function gcoreGetConfig(object $storage, string $siteId, string $managerName, string $key, $default = null)
    {
        try {
            $value = $storage->fcall('GCORE_MGR_CONFIG_GET', [], [$siteId, $managerName, $key]);
            if ($value === null || $value === false || $value === '') {
                return $default;
            }
            return $this->gcoreDecodeConfigValue($value);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Write a per-site config override. Subsequent reads (this manager
     * or another) see the new value immediately; the version counter
     * also bumps so cache-aware consumers can detect the change.
     *
     * @param mixed $value Any JSON-encodable PHP value
     * @return bool True if the write succeeded
     */
    protected function gcoreSetConfig(object $storage, string $siteId, string $managerName, string $key, $value): bool
    {
        try {
            $encoded = $this->gcoreEncodeConfigValue($value);
            // Skip non-serializable values — empty string from encode
            // means the value was an object, resource, or closure. Writing
            // '' to ValKey would shadow a bootloader-seeded default with
            // null, which is worse than not writing at all.
            if ($encoded === '' && $value !== '' && $value !== null) {
                return false;
            }
            $storage->fcall('GCORE_MGR_CONFIG_SET', [], [$siteId, $managerName, $key, $encoded]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve an fcall-capable storage adapter from the standard set
     * of locations. Used by every Wave 1+ manager — avoids
     * reimplementing the same fallback chain in each manager's
     * initialize().
     *
     * Tries (in order, returning the first hit):
     *   1. $config['storage']      — caller-supplied, highest trust
     *   2. $config['gnode_client'] — gNode-Client instance (uses its getStorage())
     *   3. global $gCore           — service locator lookup of 'gnode_client'
     *
     * Returns null when no storage is reachable. Callers should treat
     * null as "ValKey config layer unavailable — DEFAULTS + $config
     * arg are still in effect", not as a fatal error.
     *
     * @param array $config Manager's initialize() config (or current $this->config)
     * @return object|null fcall-capable storage adapter, or null
     */
    protected function gcoreResolveStorage(array $config)
    {
        if (isset($config['storage'])
            && is_object($config['storage'])
            && method_exists($config['storage'], 'fcall')) {
            return $config['storage'];
        }
        if (isset($config['gnode_client'])
            && is_object($config['gnode_client'])
            && method_exists($config['gnode_client'], 'getStorage')) {
            $s = $config['gnode_client']->getStorage();
            if ($s !== null && method_exists($s, 'fcall')) {
                return $s;
            }
        }
        try {
            global $gCore;
            if (is_object($gCore) && method_exists($gCore, 'getService')) {
                $client = $gCore->getService('gnode_client');
                if (is_object($client) && method_exists($client, 'getStorage')) {
                    $s = $client->getStorage();
                    if ($s !== null && method_exists($s, 'fcall')) {
                        return $s;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Service locator unavailable — fall through.
        }
        return null;
    }

    /**
     * Read the version counter (monotonically increasing on every
     * SET/SEED/DELETE) so caching layers can detect drift without
     * polling every key.
     */
    protected function gcoreConfigVersion(object $storage, string $siteId, string $managerName): int
    {
        try {
            $v = $storage->fcall('GCORE_MGR_CONFIG_VERSION', [], [$siteId, $managerName]);
            if (is_int($v)) {
                return $v;
            }
            if (is_numeric($v)) {
                return (int)$v;
            }
            return 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Encode a PHP value for ValKey wire storage. Inverse of
     * gcoreDecodeConfigValue. Format conventions documented at the
     * trait docblock.
     *
     * @param mixed $value
     */
    protected function gcoreEncodeConfigValue($value): string
    {
        // Non-serializable types (objects like ErrorManager, gNodeClient;
        // closures; resources) must NOT be written to ValKey — they're
        // runtime-injected dependencies, not persistent config. Returning
        // empty string causes gcoreSetConfig to store '' which is decoded
        // as null on read (appropriate — the caller's DEFAULTS supply
        // the floor for non-persistent values).
        if (is_object($value) || is_resource($value) || ($value instanceof \Closure)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }
        if ($value === null) {
            return '';
        }
        return (string)$value;
    }

    /**
     * Decode a ValKey wire value to its PHP-native type.
     *
     * Heuristics:
     *   - First char '{' or '[' → JSON decode (arrays/objects)
     *   - 'true' / 'false'       → bool
     *   - numeric                → int (no '.') or float (with '.')
     *   - everything else        → string as-is
     *
     * @param mixed $value Raw value from ValKey
     * @return mixed Typed PHP value
     */
    protected function gcoreDecodeConfigValue($value)
    {
        if ($value === null || $value === false) {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        return $value;
    }

    // =====================================================================
    // SECRETS KEYSPACE — ACL-isolated reads/writes
    // =====================================================================
    //
    // Sensitive config (encryption keys, JWT secrets, API tokens, SMTP
    // passwords) lives in a separate ValKey keyspace:
    //   {site_id}:gcore:secrets:{Manager}
    //   {default}:gcore:secrets:{Manager}
    //
    // Same fallback chain as config (site → default → caller default),
    // but the keyspace is intended to be ACL-restricted to the
    // gcore_secrets_rw_<site> role so only the manager's own process
    // and the installer can read/write. Wave 3 of the config
    // homogenization migrates sensitive keys to these functions.
    //
    // Encoding convention is the same as config (string values, JSON for
    // arrays/objects). The only difference is the FCALL name + the key
    // prefix on the ValKey side.

    /**
     * Read a secret value. Same fallback chain as gcoreGetConfig but
     * reads from the secrets keyspace ({site}:gcore:secrets:{Manager}).
     *
     * @param object $storage fcall-capable storage adapter
     * @param string $siteId Per-site namespace
     * @param string $managerName Manager class short-name
     * @param string $key Secret key name
     * @param mixed $default Returned if neither ValKey layer has the key
     * @return mixed Decoded secret value, or $default
     */
    protected function gcoreGetSecret(object $storage, string $siteId, string $managerName, string $key, $default = null)
    {
        try {
            $value = $storage->fcall('GCORE_MGR_SECRETS_GET', [], [$siteId, $managerName, $key]);
            if ($value === null || $value === false || $value === '') {
                return $default;
            }
            return $this->gcoreDecodeConfigValue($value);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Write a per-site secret. Same contract as gcoreSetConfig but
     * writes to the secrets keyspace. Non-serializable values
     * (objects, closures) are skipped.
     *
     * @param mixed $value Secret value to store
     * @return bool True if the write succeeded
     */
    protected function gcoreSetSecret(object $storage, string $siteId, string $managerName, string $key, $value): bool
    {
        try {
            $encoded = $this->gcoreEncodeConfigValue($value);
            if ($encoded === '' && $value !== '' && $value !== null) {
                return false;
            }
            $storage->fcall('GCORE_MGR_SECRETS_SET', [], [$siteId, $managerName, $key, $encoded]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
