<?php
declare(strict_types=1);
/**
 * CapabilityManager
 *
 * Per-recipient capability links: a URL that lets whoever holds it do ONE
 * scoped thing (edit one invitation, accept one offer, read one feed) until it
 * expires or is revoked. No accounts, no passwords.
 *
 * A token is derived, never stored: HMAC-SHA256 over (scope, subject, epoch,
 * expiry) with a per-site secret. ValKey holds only sha256(token), so a dump
 * of the store yields no usable link, while the site can still recompute a
 * recipient's link to send it again. Rotation bumps the epoch, which changes
 * the token and orphans the old hash.
 *
 * Keys, all inside the site's own namespace:
 *   {site}:cap:<sha256(token)>               JSON link record      TTL = expiry
 *   {site}:cap:subject:<scope>:<subject>     JSON {hash, epoch, …} TTL = expiry
 *   {site}:cap:session:<sha256(session id)>  JSON session          TTL = idle
 *
 * A GET-safe verify() never changes anything: mail scanners open every link.
 * exchange() is the only state change, and for single-use links the first
 * exchange deletes the record, so two concurrent exchanges cannot both win.
 *
 * @package     gCore
 * @subpackage  Modules\Managers\Base
 * @since       3.1.0
 */

namespace gCore\Modules\Managers\Base\CapabilityManager;

use gCore\Modules\Core\Interfaces\ModuleInterface;
use gCore\Modules\Core\Shared\ManagerConfigTrait;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__, 5));
}

class CapabilityManager implements ModuleInterface
{
    use ManagerConfigTrait;

    private const MANAGER = 'CapabilityManager';
    private const SECRET_KEY = 'link_secret';
    private const SCOPE_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';
    private const SUBJECT_PATTERN = '/\A[A-Za-z0-9_.:-]{1,128}\z/';

    private const DEFAULTS = [
        'session_idle_seconds' => 7200,
    ];

    /** @var CapabilityManager|null */
    private static $instance = null;

    /** @var array */
    private $config = self::DEFAULTS;

    /** @var object|null */
    private $storage = null;

    /** @var callable|null returns the current time in ms */
    private $clock = null;

    /** @var array<string,string> per-site secret cache for this process */
    private $secrets = [];

    /** @var bool */
    private $initialized = false;

    private function __construct() {}

    public static function getInstance(): ModuleInterface
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param array $config storage (fcall-capable: get/set/delete/fcall), or gnode_client;
     *                      clock (callable(): int ms), session_idle_seconds
     */
    public function initialize(array $config = []): void
    {
        $this->storage = $this->gcoreResolveStorage($config);
        $this->clock = isset($config['clock']) && is_callable($config['clock']) ? $config['clock'] : null;
        foreach (self::DEFAULTS as $k => $v) {
            if (array_key_exists($k, $config)) {
                $this->config[$k] = $config[$k];
            }
        }
        $this->secrets = [];
        $this->initialized = $this->storage !== null;
    }

    public function getConfig(): array { return $this->config; }

    public function updateConfig(array $config): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            if (array_key_exists($k, $config)) {
                $this->config[$k] = $config[$k];
            }
        }
    }

    public function isInitialized(): bool { return $this->initialized; }

    public function getStatus(): array
    {
        return ['initialized' => $this->initialized, 'storage' => $this->storage !== null];
    }

    /**
     * The link token for (scope, subject), valid until $expiresAtMs. Issuing again
     * before expiry returns the SAME token (a reminder carries the same link).
     */
    public function issue(string $siteId, string $scope, string $subject, int $expiresAtMs, bool $singleUse = false): string
    {
        $this->guard($siteId, $scope, $subject);
        $now = $this->now();
        if ($expiresAtMs <= $now) {
            throw new \InvalidArgumentException('capability: expiry is in the past');
        }
        $index = $this->readJson($this->subjectKey($siteId, $scope, $subject));
        $epoch = 0;
        if ($index !== null) {
            $epoch = (int)$index['epoch'];
            if (empty($index['revoked']) && (int)$index['expires_ms'] === $expiresAtMs && (bool)$index['single_use'] === $singleUse) {
                $token = $this->derive($siteId, $scope, $subject, $epoch, $expiresAtMs);
                if ($this->storage->exists($this->linkKey($siteId, $token))) {
                    return $token;
                }
            }
            // Revoked, consumed or changed: never re-derive a token that was given out before.
            if ((string)$index['hash'] !== '') {
                $this->storage->delete($this->linkKeyForHash($siteId, (string)$index['hash']));
            }
            $epoch++;
        }
        return $this->write($siteId, $scope, $subject, $epoch, $expiresAtMs, $singleUse);
    }

    /** A new token for the subject; the old link stops working. */
    public function rotate(string $siteId, string $scope, string $subject, int $expiresAtMs, bool $singleUse = false): string
    {
        $this->guard($siteId, $scope, $subject);
        $index = $this->readJson($this->subjectKey($siteId, $scope, $subject));
        $epoch = 0;
        if ($index !== null) {
            if ((string)$index['hash'] !== '') {
                $this->storage->delete($this->linkKeyForHash($siteId, (string)$index['hash']));
            }
            $epoch = (int)$index['epoch'] + 1;
        }
        return $this->write($siteId, $scope, $subject, $epoch, $expiresAtMs, $singleUse);
    }

    /** Read-only: what a token grants, or null. Safe for a GET. */
    public function verify(string $siteId, string $token): ?array
    {
        if (!$this->initialized || !$this->tokenShape($token)) {
            return null;
        }
        $link = $this->readJson($this->linkKey($siteId, $token));
        if ($link === null || (int)$link['expires_ms'] <= $this->now()) {
            return null;
        }
        return ['scope' => $link['scope'], 'subject' => $link['subject'],
                'single_use' => (bool)$link['single_use'], 'expires_ms' => (int)$link['expires_ms']];
    }

    /**
     * Trade a token for a server-side session. A single-use link is consumed here:
     * the first exchange deletes it, a second gets null.
     *
     * @return array|null {session_id, scope, subject, csrf, expires_ms}
     */
    public function exchange(string $siteId, string $token, ?string $expectScope = null): ?array
    {
        $grant = $this->verify($siteId, $token);
        if ($grant === null || ($expectScope !== null && $grant['scope'] !== $expectScope)) {
            return null;
        }
        if ($grant['single_use'] && !$this->storage->delete($this->linkKey($siteId, $token))) {
            return null;
        }
        $sessionId = $this->random();
        $idle = (int)$this->config['session_idle_seconds'];
        $expires = min($grant['expires_ms'], $this->now() + $idle * 1000);
        $session = ['scope' => $grant['scope'], 'subject' => $grant['subject'],
                    'csrf' => $this->random(), 'expires_ms' => $grant['expires_ms']];
        $ttl = max(1, intdiv($expires - $this->now() + 999, 1000));
        $this->storage->set($this->sessionKey($siteId, $sessionId), json_encode($session), $ttl);
        return ['session_id' => $sessionId] + $session;
    }

    /** The session behind a session id, its idle timer refreshed; null when gone. */
    public function session(string $siteId, string $sessionId): ?array
    {
        if (!$this->initialized || !$this->tokenShape($sessionId)) {
            return null;
        }
        $key = $this->sessionKey($siteId, $sessionId);
        $session = $this->readJson($key);
        if ($session === null || (int)$session['expires_ms'] <= $this->now()) {
            return null;
        }
        $ttl = max(1, intdiv(min((int)$session['expires_ms'] - $this->now(), (int)$this->config['session_idle_seconds'] * 1000) + 999, 1000));
        $this->storage->set($key, json_encode($session), $ttl);
        return $session;
    }

    public function endSession(string $siteId, string $sessionId): void
    {
        if ($this->initialized && $this->tokenShape($sessionId)) {
            $this->storage->delete($this->sessionKey($siteId, $sessionId));
        }
    }

    /** The subject's link stops working; its open sessions end at their idle timeout. */
    public function revoke(string $siteId, string $scope, string $subject): bool
    {
        $this->guard($siteId, $scope, $subject);
        $key = $this->subjectKey($siteId, $scope, $subject);
        $index = $this->readJson($key);
        if ($index === null) {
            return false;
        }
        $this->storage->delete($this->linkKeyForHash($siteId, (string)$index['hash']));
        $index['hash'] = '';
        $index['revoked'] = true;
        $ttl = max(1, intdiv((int)$index['expires_ms'] - $this->now() + 999, 1000));
        $this->storage->set($key, json_encode($index), $ttl);
        return true;
    }

    public function getCapabilityVector(): array
    {
        return ['capability_links' => 1.0];
    }

    public function shutdown(): void
    {
        $this->initialized = false;
        $this->secrets = [];
    }

    // ---------------------------------------------------------------------

    private function write(string $siteId, string $scope, string $subject, int $epoch, int $expiresAtMs, bool $singleUse): string
    {
        $token = $this->derive($siteId, $scope, $subject, $epoch, $expiresAtMs);
        $hash = hash('sha256', $token);
        $ttl = max(1, intdiv($expiresAtMs - $this->now() + 999, 1000));
        $link = ['scope' => $scope, 'subject' => $subject, 'issued_ms' => $this->now(),
                 'expires_ms' => $expiresAtMs, 'single_use' => $singleUse];
        $this->storage->set($this->linkKeyForHash($siteId, $hash), json_encode($link), $ttl);
        $index = ['hash' => $hash, 'epoch' => $epoch, 'expires_ms' => $expiresAtMs, 'single_use' => $singleUse];
        $this->storage->set($this->subjectKey($siteId, $scope, $subject), json_encode($index), $ttl);
        return $token;
    }

    private function derive(string $siteId, string $scope, string $subject, int $epoch, int $expiresAtMs): string
    {
        $mac = hash_hmac('sha256', "v1|{$scope}|{$subject}|{$epoch}|{$expiresAtMs}", $this->secret($siteId), true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** The per-site link secret, created once under a lock so two first issues agree. */
    private function secret(string $siteId): string
    {
        if (isset($this->secrets[$siteId])) {
            return $this->secrets[$siteId];
        }
        $s = $this->gcoreGetSecret($this->storage, $siteId, self::MANAGER, self::SECRET_KEY);
        if (!is_string($s) || strlen($s) < 64) {
            $lockToken = $this->random();
            for ($i = 0; $i < 20; $i++) {
                if ($this->lock($siteId, $lockToken)) {
                    try {
                        $s = $this->gcoreGetSecret($this->storage, $siteId, self::MANAGER, self::SECRET_KEY);
                        if (!is_string($s) || strlen($s) < 64) {
                            $s = bin2hex(random_bytes(32));
                            if (!$this->gcoreSetSecret($this->storage, $siteId, self::MANAGER, self::SECRET_KEY, $s)) {
                                throw new \RuntimeException('capability: cannot store the link secret');
                            }
                        }
                    } finally {
                        $this->unlock($siteId, $lockToken);
                    }
                    break;
                }
                usleep(50000);
                $s = $this->gcoreGetSecret($this->storage, $siteId, self::MANAGER, self::SECRET_KEY);
                if (is_string($s) && strlen($s) >= 64) {
                    break;
                }
            }
            if (!is_string($s) || strlen($s) < 64) {
                throw new \RuntimeException('capability: no link secret for ' . $siteId);
            }
        }
        return $this->secrets[$siteId] = $s;
    }

    private function lock(string $siteId, string $token): bool
    {
        return (int)$this->storage->fcall('GNODE_LOCK_ACQUIRE', ['capability-secret'], [$siteId, $token, 10]) === 1;
    }

    private function unlock(string $siteId, string $token): void
    {
        try {
            $this->storage->fcall('GNODE_LOCK_RELEASE', ['capability-secret'], [$siteId, $token]);
        } catch (\Throwable $e) {
            // the lock expires on its own after 10 s
        }
    }

    private function guard(string $siteId, string $scope, string $subject): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('capability: not initialized (no storage)');
        }
        if (!preg_match('/\A[a-z][a-z0-9_]*\z/', $siteId)) {
            throw new \InvalidArgumentException('capability: bad site id');
        }
        if (!preg_match(self::SCOPE_PATTERN, $scope) || !preg_match(self::SUBJECT_PATTERN, $subject)) {
            throw new \InvalidArgumentException('capability: bad scope or subject');
        }
    }

    private function tokenShape(string $t): bool
    {
        return (bool)preg_match('/\A[A-Za-z0-9_-]{43}\z/', $t);
    }

    private function readJson(string $key): ?array
    {
        $raw = $this->storage->get($key);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $v = json_decode($raw, true);
        return is_array($v) ? $v : null;
    }

    private function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function now(): int
    {
        return $this->clock !== null ? (int)($this->clock)() : (int)floor(microtime(true) * 1000);
    }

    private function linkKey(string $siteId, string $token): string { return $this->linkKeyForHash($siteId, hash('sha256', $token)); }
    private function linkKeyForHash(string $siteId, string $hash): string { return '{' . $siteId . '}:cap:' . $hash; }
    private function subjectKey(string $siteId, string $scope, string $subject): string { return '{' . $siteId . '}:cap:subject:' . $scope . ':' . $subject; }
    private function sessionKey(string $siteId, string $sid): string { return '{' . $siteId . '}:cap:session:' . hash('sha256', $sid); }
}
