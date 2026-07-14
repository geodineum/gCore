<?php
declare(strict_types=1);
/**
 * HtaccessManager - gCore Apache .htaccess management
 *
 * Extracted from InstallManager in Commit 1.3.a (GC-D3.02 partial-close).
 * Owns the "read / generate / write .htaccess" surface:
 *   - setupHtaccess()        — install gCore security rules (one-shot at first run)
 *   - addHtaccessRule()      — append a rule under a named section
 *   - getHtaccessPath()      — resolve the target .htaccess path
 *   - generateHtaccessRules()— canonical gCore security ruleset (heredoc)
 *   - ensureIPBlockSection() — idempotent setup of the IP-block section that
 *                              IPBlockManager writes into
 *
 * IPBlockManager (1.3.b) calls getHtaccessPath() + ensureIPBlockSection() via
 * this class's singleton. Method bodies moved verbatim from InstallManager
 * with no behavior change.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\HtaccessManager
 * @version     1.0.0
 * @since       1.0.0  (Commit 1.3.a)
 */

namespace gCore\Modules\Managers\Base\HtaccessManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;

class HtaccessManager implements ModuleInterface
{
    use ManagerConfigTrait;

    /**
     * Hardcoded floor defaults. These are the last-resort fallback
     * if neither the per-site nor the bootloader-seeded ValKey
     * defaults are present. Once the installer bootloader (config
     * Wave 4) seeds {default}:gcore:config:HtaccessManager, these
     * become redundant but stay as a safety net.
     */
    private const DEFAULTS = [
        'htaccess_path' => null,  // auto-detect at use time
        'debug' => false,
    ];

    /** @var HtaccessManager|null Singleton instance */
    private static $instance = null;

    /** @var bool */
    private $initialized = false;

    /** @var array */
    private $config = [];

    /** @var object|null ErrorManager (optional) */
    private $error = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }

    public function initialize(array $config = []): void
    {
        if ($this->initialized) {
            return;
        }

        // Layered config resolution (lowest priority → highest):
        //   1. self::DEFAULTS                      — hardcoded floor
        //   2. ValKey HGETALL (defaults+override)  — site → bootloader-default
        //   3. $config arg                          — caller passthrough
        //
        // The ValKey layer covers both global defaults (seeded by the
        // installer bootloader at {default}:gcore:config:HtaccessManager)
        // AND per-site overrides ({site_id}:gcore:config:HtaccessManager).
        // Failures load empty — DEFAULTS + $config still work.
        $valkeyConfig = [];
        $storage = $this->gcoreResolveStorage($config);
        if ($storage !== null) {
            $siteId = (string)($config['site_id'] ?? 'default');
            $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'HtaccessManager');
        }

        $this->config = array_merge(self::DEFAULTS, $valkeyConfig, $config);
        $this->error = $config['error_manager'] ?? null;
        $this->initialized = true;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);

        // Persist updates to ValKey (per-site override) so other
        // processes in this site's namespace observe the change.
        // Best-effort — if storage isn't reachable, local update
        // still applies for this request's lifetime.
        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'HtaccessManager', (string)$key, $value);
            }
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        return [
            'initialized' => $this->initialized,
            'htaccess_path' => $this->getHtaccessPath(),
        ];
    }

    // =========================================================================
    // PUBLIC API — .htaccess management
    // =========================================================================

    /**
     * Set up .htaccess with gCore security rules.
     * @api
     */
    public function setupHtaccess(): bool
    {
        $htaccessPath = $this->getHtaccessPath();

        if (!$htaccessPath) {
            $this->log('warning', 'Could not determine .htaccess path');
            return false;
        }

        try {
            return $this->withExclusiveLock(
                $htaccessPath,
                function (string $existingContent) use ($htaccessPath): ?string {
                    // Re-read-verify under lock (GC-D2.20 TOCTOU): if rules
                    // already exist (raced against another caller), no-op.
                    if (strpos($existingContent, '# BEGIN gCore Security') !== false) {
                        $this->log('info', 'gCore htaccess rules already installed');
                        return null;
                    }

                    // Backup existing content (separate file, no lock needed)
                    if ($existingContent !== '') {
                        $backupPath = $htaccessPath . '.backup.' . date('Y-m-d-His');
                        if (@file_put_contents($backupPath, $existingContent) === false) {
                            $this->log('warning', "Failed to write backup at {$backupPath}");
                        } else {
                            $this->log('info', "Backed up .htaccess to {$backupPath}");
                        }
                    }

                    $newContent = $this->generateHtaccessRules() . "\n\n" . $existingContent;
                    $this->log('info', 'Installed gCore htaccess security rules');
                    return $newContent;
                }
            );
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to setup .htaccess: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a custom rule under a named gCore .htaccess section.
     * @api
     */
    public function addHtaccessRule(string $rule, string $section = 'Custom'): bool
    {
        $htaccessPath = $this->getHtaccessPath();
        if (!$htaccessPath) {
            return false;
        }

        try {
            return $this->withExclusiveLock(
                $htaccessPath,
                function (string $content) use ($rule, $section): string {
                    $sectionStart = "# BEGIN gCore {$section}";
                    $sectionEnd = "# END gCore {$section}";

                    if (strpos($content, $sectionStart) === false) {
                        // Create section
                        $newSection = "\n{$sectionStart}\n{$rule}\n{$sectionEnd}\n";

                        // Insert after main gCore Security section if exists
                        if (strpos($content, '# END gCore Security') !== false) {
                            return str_replace(
                                '# END gCore Security',
                                "# END gCore Security\n{$newSection}",
                                $content
                            );
                        }
                        return $newSection . $content;
                    }

                    // Add to existing section
                    return str_replace(
                        $sectionEnd,
                        "{$rule}\n{$sectionEnd}",
                        $content
                    );
                }
            );
        } catch (\Throwable $e) {
            $this->log('error', "Failed to add htaccess rule: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve the .htaccess file path: configured → WordPress ABSPATH →
     * $_SERVER['DOCUMENT_ROOT']. null if none resolves.
     * @api
     */
    public function getHtaccessPath(): ?string
    {
        if (!empty($this->config['htaccess_path'])) {
            return $this->config['htaccess_path'];
        }

        // WordPress
        if (defined('ABSPATH')) {
            return ABSPATH . '.htaccess';
        }

        // Fallback to document root
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            return $_SERVER['DOCUMENT_ROOT'] . '/.htaccess';
        }

        return null;
    }

    /**
     * Canonical gCore security ruleset (includes the empty IP-block section
     * that IPBlockManager populates at runtime).
     * @api
     */
    public function generateHtaccessRules(): string
    {
        return <<<HTACCESS
# BEGIN gCore Security
# Generated by gCore HtaccessManager - DO NOT EDIT MANUALLY

# Protect sensitive files
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>

<FilesMatch "(^#.*#|~$|\.php~|\.bak|\.swp)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Protect configuration files
<FilesMatch "(wp-config\.php|\.env|composer\.json|composer\.lock)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Security headers
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set X-Frame-Options "SAMEORIGIN"
    Header set X-XSS-Protection "1; mode=block"
    Header set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Disable directory browsing
Options -Indexes

# Protect gCore directories
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(logs|cache|temp|backups)/ - [F,L]
</IfModule>

# END gCore Security

# BEGIN gCore IP Blocks
# Managed by IPBlockManager - IPs blocked for security violations
Order Allow,Deny
Allow from all
# END gCore IP Blocks
HTACCESS;
    }

    /**
     * Idempotently ensure the IP-block section exists in .htaccess so
     * IPBlockManager has a target to write into.
     * @api
     */
    public function ensureIPBlockSection(string $htaccessPath): void
    {
        try {
            $this->withExclusiveLock(
                $htaccessPath,
                function (string $content): ?string {
                    // Re-read-verify under lock (GC-D2.20 TOCTOU): another
                    // process may have populated the section between the
                    // caller's check and our acquisition.
                    if (strpos($content, '# BEGIN gCore IP Blocks') !== false) {
                        return null;
                    }

                    $section = <<<HTACCESS

# BEGIN gCore IP Blocks
# Managed by IPBlockManager - IPs blocked for security violations
Order Allow,Deny
Allow from all
# END gCore IP Blocks
HTACCESS;

                    if (strpos($content, '# END gCore Security') !== false) {
                        return str_replace(
                            '# END gCore Security',
                            "# END gCore Security\n{$section}",
                            $content
                        );
                    }
                    return $content . $section;
                }
            );
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to ensure IP-block section: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Read-modify-write `$path` under a single exclusive (LOCK_EX) region.
     *
     * Closes the GC-D2.20 TOCTOU class: classic implementations did
     * `file_exists` → `file_get_contents` → compute → `file_put_contents`
     * with no lock spanning the read and write, so a second writer racing
     * between the read and the write would lose updates. Here the file is
     * opened once with `c+b` (create-if-missing, read+write, no truncate),
     * `LOCK_EX` is acquired, the existing content is read inside the lock,
     * the caller's transform produces the new content (or `null` for
     * no-change), and the same handle is truncated + rewritten before the
     * lock is released.
     *
     * @param string                    $path      Target file path.
     * @param callable(string):?string  $transform Callable receiving the
     *      current contents and returning either the new contents or `null`
     *      to indicate "no change required" (used for the idempotent
     *      no-op short-circuit in setup/ensure paths).
     * @return bool  True on success or no-op; false on lock/IO failure.
     */
    private function withExclusiveLock(string $path, callable $transform): bool
    {
        $fp = @fopen($path, 'c+b');
        if ($fp === false) {
            $this->log('error', "Could not open .htaccess for read/write: {$path}");
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                $this->log('error', "Could not acquire exclusive lock on {$path}");
                return false;
            }

            try {
                $existing = '';
                while (!feof($fp)) {
                    $chunk = fread($fp, 8192);
                    if ($chunk === false) {
                        $this->log('error', "Read failure on {$path}");
                        return false;
                    }
                    $existing .= $chunk;
                }

                $new = $transform($existing);
                if ($new === null) {
                    return true; // idempotent no-op short-circuit
                }

                if (rewind($fp) === false || ftruncate($fp, 0) === false) {
                    $this->log('error', "Failed to truncate {$path} for rewrite");
                    return false;
                }

                $written = fwrite($fp, $new);
                if ($written === false || $written !== strlen($new)) {
                    $this->log('error', "Short write on {$path} (wrote " . var_export($written, true) . " of " . strlen($new) . ")");
                    return false;
                }

                fflush($fp);
                return true;
            } finally {
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->error && method_exists($this->error, 'logMessage')) {
            $this->error->logMessage($message, 'HTACCESS', strtoupper($level), $context);
        } elseif (!empty($this->config['debug']) || $level === 'error') {
            error_log("[HtaccessManager][{$level}] {$message} " . json_encode($context));
        }
    }
}
