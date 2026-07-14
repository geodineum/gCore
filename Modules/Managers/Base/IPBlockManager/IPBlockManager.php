<?php
declare(strict_types=1);
/**
 * IPBlockManager - gCore IP-blocking via .htaccess
 *
 * Extracted from InstallManager in Commit 1.3.b (GC-D3.02 partial-close).
 * Owns the block/unblock/list/expire surface for Apache-level IP denials
 * managed inside the gCore Security section of .htaccess.
 *
 * Delegates all .htaccess path resolution + section scaffolding to
 * HtaccessManager (1.3.a). No direct file I/O on .htaccess from this
 * class — we only rewrite block entries; the surrounding section is
 * HtaccessManager's job.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\IPBlockManager
 * @version     1.0.0
 * @since       1.0.0  (Commit 1.3.b)
 */

namespace gCore\Modules\Managers\Base\IPBlockManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;
use gCore\Modules\Managers\Base\HtaccessManager\HtaccessManager;

class IPBlockManager implements ModuleInterface
{
    use ManagerConfigTrait;

    /** Hardcoded floor defaults. See ManagerConfigTrait docblock for the layering rationale. */
    private const DEFAULTS = [
        'debug' => false,
    ];

    /** @var IPBlockManager|null Singleton instance */
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

        // Layered config: DEFAULTS → ValKey (defaults + per-site) → $config arg
        $siteId = (string)($config['site_id'] ?? 'default');
        $valkeyConfig = [];
        $storage = $this->gcoreResolveStorage($config);
        if ($storage !== null) {
            $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'IPBlockManager');
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

        // Persist per-key to ValKey so cross-process updates are visible.
        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'IPBlockManager', (string)$key, $value);
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
            'blocked_count' => count($this->getBlockedIPs()),
        ];
    }

    // =========================================================================
    // PUBLIC API — IP blocking
    // =========================================================================

    /**
     * Add IP to .htaccess block list.
     *
     * Used by SecurityManager firewall for auto-banning malicious IPs.
     * $reason is sanitized to prevent .htaccess comment-injection
     * (GC-D2.06 closed in Commit 1.2.d and preserved here).
     * @api
     */
    public function blockIP(string $ip, string $reason = '', ?int $duration = null): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->log('warning', "Invalid IP address: {$ip}");
            return false;
        }

        // GC-D2.06: strip CR/LF/# from $reason to prevent .htaccess directive
        // injection via newlines in the comment at L~34 below. Cap at 200
        // chars to keep the comment line readable.
        $reason = str_replace(["\r", "\n", "#"], ' ', $reason);
        if (strlen($reason) > 200) {
            $reason = substr($reason, 0, 200);
        }

        $htaccess = HtaccessManager::getInstance();
        $htaccessPath = $htaccess->getHtaccessPath();
        if (!$htaccessPath || !file_exists($htaccessPath)) {
            return false;
        }

        try {
            $content = file_get_contents($htaccessPath);

            // Find the gCore IP block section
            $blockStart = '# BEGIN gCore IP Blocks';
            $blockEnd = '# END gCore IP Blocks';

            if (strpos($content, $blockStart) === false) {
                // Create the section if it doesn't exist (delegates to HtaccessManager)
                $htaccess->ensureIPBlockSection($htaccessPath);
                $content = file_get_contents($htaccessPath);
            }

            // Check if IP is already blocked
            if (strpos($content, "Deny from {$ip}") !== false) {
                return true; // Already blocked
            }

            // Add the IP block
            $timestamp = date('Y-m-d H:i:s');
            $expiry = $duration ? date('Y-m-d H:i:s', time() + $duration) : 'permanent';
            $comment = "# Blocked: {$timestamp} | Reason: {$reason} | Expires: {$expiry}";

            $newRule = "{$comment}\nDeny from {$ip}\n";

            // Insert before END marker
            $content = str_replace(
                $blockEnd,
                $newRule . $blockEnd,
                $content
            );

            file_put_contents($htaccessPath, $content);

            $this->log('info', "Blocked IP: {$ip}", ['reason' => $reason, 'duration' => $duration]);

            return true;

        } catch (\Exception $e) {
            $this->log('error', "Failed to block IP {$ip}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove IP from .htaccess block list.
     * @api
     */
    public function unblockIP(string $ip): bool
    {
        $htaccessPath = HtaccessManager::getInstance()->getHtaccessPath();
        if (!$htaccessPath || !file_exists($htaccessPath)) {
            return false;
        }

        try {
            $content = file_get_contents($htaccessPath);

            // Remove the IP and its comment
            $pattern = "/# Blocked:.*\nDeny from " . preg_quote($ip, '/') . "\n/";
            $newContent = preg_replace($pattern, '', $content);

            if ($newContent !== $content) {
                file_put_contents($htaccessPath, $newContent);
                $this->log('info', "Unblocked IP: {$ip}");
                return true;
            }

            return false;

        } catch (\Exception $e) {
            $this->log('error', "Failed to unblock IP {$ip}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get list of blocked IPs with metadata.
     * @api
     */
    public function getBlockedIPs(): array
    {
        $htaccessPath = HtaccessManager::getInstance()->getHtaccessPath();
        if (!$htaccessPath || !file_exists($htaccessPath)) {
            return [];
        }

        $content = file_get_contents($htaccessPath);
        $blocked = [];

        // Parse blocked IPs from gCore section
        $pattern = '/# Blocked: ([^\|]+)\| Reason: ([^\|]+)\| Expires: ([^\n]+)\nDeny from ([^\n]+)/';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $blocked[] = [
                'ip' => trim($match[4]),
                'blocked_at' => trim($match[1]),
                'reason' => trim($match[2]),
                'expires' => trim($match[3])
            ];
        }

        return $blocked;
    }

    /**
     * Clean expired IP blocks. Should be called periodically (e.g. daily cron).
     * @api
     */
    public function cleanExpiredBlocks(): int
    {
        $blocked = $this->getBlockedIPs();
        $removed = 0;
        $now = time();

        foreach ($blocked as $block) {
            if ($block['expires'] !== 'permanent') {
                $expiryTime = strtotime($block['expires']);
                if ($expiryTime && $expiryTime < $now) {
                    if ($this->unblockIP($block['ip'])) {
                        $removed++;
                    }
                }
            }
        }

        if ($removed > 0) {
            $this->log('info', "Cleaned {$removed} expired IP blocks");
        }

        return $removed;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->error && method_exists($this->error, 'logMessage')) {
            $this->error->logMessage($message, 'IPBLOCK', strtoupper($level), $context);
        } elseif (!empty($this->config['debug']) || $level === 'error') {
            error_log("[IPBlockManager][{$level}] {$message} " . json_encode($context));
        }
    }
}
