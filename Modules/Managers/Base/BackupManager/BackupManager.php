<?php
declare(strict_types=1);
/**
 * BackupManager - gCore filesystem backup / restore / retention
 *
 * Extracted from InstallManager in Commit 1.3.c (GC-D3.02 partial-close).
 * Owns the {create, restore, cleanOld} surface for gCore-maintained
 * backups under `${installationBasePath}/backups/`.
 *
 * Independent of HtaccessManager + IPBlockManager — no cross-manager
 * calls needed. Ships its own minimal copyDirectory / removeDirectory
 * helpers rather than depending on InstallManager internals (those
 * internals are also used by extractPackage which stays in
 * InstallManager, so extracting them here would force a shared
 * FilesystemHelper that the current scope does not justify).
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base\BackupManager
 * @version     1.0.0
 * @since       1.0.0  (Commit 1.3.c)
 */

namespace gCore\Modules\Managers\Base\BackupManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

class BackupManager implements ModuleInterface
{
    use ManagerConfigTrait;

    /** Hardcoded floor defaults. See ManagerConfigTrait for the layering rationale. */
    private const DEFAULTS = [
        'debug' => false,
        'permissions' => [
            'files' => 0644,
            'directories' => 0755,
        ],
    ];

    /** @var BackupManager|null Singleton instance */
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

        // Layered config: DEFAULTS → ValKey → $config arg
        // installation_base_path defaults to getcwd() if no layer supplies it.
        $siteId = (string)($config['site_id'] ?? 'default');
        $valkeyConfig = [];
        $storage = $this->gcoreResolveStorage($config);
        if ($storage !== null) {
            $valkeyConfig = $this->gcoreLoadConfig($storage, $siteId, 'BackupManager');
        }

        $floor = self::DEFAULTS + ['installation_base_path' => getcwd()];
        $this->config = array_merge($floor, $valkeyConfig, $config);
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

        $storage = $this->gcoreResolveStorage($this->config);
        if ($storage !== null) {
            $siteId = (string)($this->config['site_id'] ?? 'default');
            foreach ($config as $key => $value) {
                $this->gcoreSetConfig($storage, $siteId, 'BackupManager', (string)$key, $value);
            }
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function getStatus(): array
    {
        $backupDir = $this->getBackupDir();
        return [
            'initialized' => $this->initialized,
            'backup_dir' => $backupDir,
            'backup_dir_exists' => is_dir($backupDir),
        ];
    }

    // =========================================================================
    // PUBLIC API — Backups
    // =========================================================================

    /**
     * Create a timestamped backup of a file or directory.
     *
     * Returns the backup path on success, null on failure.
     * @api
     */
    public function createBackup(string $name, string $path): ?string
    {
        $backupDir = $this->getBackupDir();
        if (!file_exists($backupDir)) {
            mkdir($backupDir, $this->config['permissions']['directories'], true);
        }

        $timestamp = date('Y-m-d-His');
        $backupPath = "{$backupDir}/{$name}-{$timestamp}";

        try {
            if (is_dir($path)) {
                $this->copyDirectory($path, $backupPath);
            } else {
                copy($path, $backupPath);
            }

            $this->log('info', "Created backup: {$backupPath}");
            return $backupPath;

        } catch (\Exception $e) {
            $this->log('error', "Backup failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Restore a backup over a target path. For directory backups, the
     * existing target is removed first (destructive restore).
     * @api
     */
    public function restoreBackup(string $backupPath, string $targetPath): bool
    {
        try {
            if (is_dir($backupPath)) {
                // Remove existing target
                if (file_exists($targetPath)) {
                    $this->removeDirectory($targetPath);
                }
                $this->copyDirectory($backupPath, $targetPath);
            } else {
                copy($backupPath, $targetPath);
            }

            $this->log('info', "Restored backup from {$backupPath} to {$targetPath}");
            return true;

        } catch (\Exception $e) {
            $this->log('error', "Restore failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove backups older than $retentionDays. Returns the count removed.
     * @api
     */
    public function cleanOldBackups(int $retentionDays = 30): int
    {
        $backupDir = $this->getBackupDir();
        if (!is_dir($backupDir)) {
            return 0;
        }

        $cutoff = time() - ($retentionDays * DAY_IN_SECONDS);
        $removed = 0;

        $files = new \DirectoryIterator($backupDir);
        foreach ($files as $file) {
            if ($file->isDot()) continue;

            if ($file->getMTime() < $cutoff) {
                if ($file->isDir()) {
                    $this->removeDirectory($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->log('info', "Cleaned {$removed} old backups");
        }

        return $removed;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function getBackupDir(): string
    {
        return ($this->config['installation_base_path'] ?? getcwd()) . '/backups';
    }

    /**
     * Recursive directory copy. Local duplicate of InstallManager's helper;
     * kept here so BackupManager has no runtime dependency on InstallManager.
     */
    private function copyDirectory(string $source, string $dest): void
    {
        if (!file_exists($dest)) {
            mkdir($dest, $this->config['permissions']['directories'], true);
        }

        $dir = new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $destPath = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!file_exists($destPath)) {
                    mkdir($destPath, $this->config['permissions']['directories']);
                }
            } else {
                copy($item, $destPath);
            }
        }
    }

    /**
     * Recursive directory removal. Local duplicate of InstallManager's helper.
     */
    private function removeDirectory(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $dir = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($dir, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item);
            } else {
                unlink($item);
            }
        }

        rmdir($path);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->error && method_exists($this->error, 'logMessage')) {
            $this->error->logMessage($message, 'BACKUP', strtoupper($level), $context);
        } elseif (!empty($this->config['debug']) || $level === 'error') {
            error_log("[BackupManager][{$level}] {$message} " . json_encode($context));
        }
    }
}
