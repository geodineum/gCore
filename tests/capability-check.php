<?php
declare(strict_types=1);
/**
 * Verify capability links without a framework boot:  php tests/capability-check.php
 * Against a real ValKey with gNode's secrets and lock functions loaded (the Lua contract too):
 *   CAPABILITY_VALKEY_PORT=47998 php tests/capability-check.php
 * As a site-scoped identity (the ACL a site really holds, ~{demo_site}:* and nothing else):
 *   CAPABILITY_VALKEY_PORT=47998 CAPABILITY_VALKEY_AUTH=user:password php tests/capability-check.php
 */

require __DIR__ . '/../vendor/autoload.php';

use gCore\Modules\Managers\Base\CapabilityManager\CapabilityManager;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %-66s %s\n", $what, $ok ? 'ok' : 'FAILED');
}

/** The five calls the manager makes, in memory, with TTLs on a settable clock. */
final class FakeStore {
    public array $kv = []; public array $exp = []; public array $secrets = []; public array $locks = []; public int $now = 0;
    private function live(string $k): bool {
        if (isset($this->exp[$k]) && $this->exp[$k] <= $this->now) { unset($this->kv[$k], $this->exp[$k]); }
        return array_key_exists($k, $this->kv);
    }
    public function get(string $k) { return $this->live($k) ? $this->kv[$k] : false; }
    public function set(string $k, $v, ?int $ttl = null): bool { $this->kv[$k] = $v; if ($ttl) { $this->exp[$k] = $this->now + $ttl * 1000; } else { unset($this->exp[$k]); } return true; }
    public function exists(string $k): bool { return $this->live($k); }
    public function delete(string $k): bool { $had = $this->live($k); unset($this->kv[$k], $this->exp[$k]); return $had; }
    public function fcall(string $fn, array $keys, array $args) {
        switch ($fn) {
            case 'GCORE_MGR_SECRETS_GET': return $this->secrets[$args[0]][$args[1]][$args[2]] ?? null;
            case 'GCORE_MGR_SECRETS_SET': $this->secrets[$args[0]][$args[1]][$args[2]] = $args[3]; return 'OK';
            case 'GNODE_LOCK_ACQUIRE': $k = $args[0] . $keys[0]; if (isset($this->locks[$k])) { return 0; } $this->locks[$k] = $args[1]; return 1;
            case 'GNODE_LOCK_RELEASE': $k = $args[0] . $keys[0]; if (($this->locks[$k] ?? null) !== $args[1]) { return 0; } unset($this->locks[$k]); return 1;
        }
        throw new \RuntimeException("unexpected fcall $fn");
    }
}

/** The same five calls against a real ValKey (phpredis). */
final class RealStore {
    public \Redis $r;
    public function __construct(int $port, string $auth = '') {
        $this->r = new \Redis(); $this->r->connect('127.0.0.1', $port);
        if ($auth !== '') { [$u, $p] = array_pad(explode(':', $auth, 2), 2, ''); $this->r->auth($p === '' ? $u : [$u, $p]); }
    }
    public function get(string $k) { return $this->r->get($k); }
    public function set(string $k, $v, ?int $ttl = null): bool { return $ttl ? $this->r->setex($k, $ttl, $v) : $this->r->set($k, $v); }
    public function exists(string $k): bool { return $this->r->exists($k) > 0; }
    public function delete(string $k): bool { return $this->r->del($k) > 0; }
    public function fcall(string $fn, array $keys, array $args) { return $this->r->rawCommand('FCALL', $fn, count($keys), ...$keys, ...$args); }
}

function suite(string $name, object $store, callable $advance): void {
    echo "$name:\n";
    $ref = new \ReflectionClass(CapabilityManager::class);
    $inst = $ref->getProperty('instance'); $inst->setAccessible(true); $inst->setValue(null, null);
    $now = 1_800_000_000_000;
    if ($store instanceof FakeStore) { $store->now = $now; }
    $clock = function () use (&$now) { return $now; };
    $cap = CapabilityManager::getInstance();
    $cap->initialize(['storage' => $store, 'clock' => $clock, 'session_idle_seconds' => 3600]);
    check('initialized with an fcall-capable store', $cap->isInitialized());

    $deadline = $now + 7 * 86400 * 1000;
    $t = $cap->issue('demo_site', 'round', 'inv-1', $deadline);
    check('a token is 43 base64url characters (256 bits)', (bool)preg_match('/\A[A-Za-z0-9_-]{43}\z/', $t));
    check('issuing again returns the same link (a reminder carries it)', $cap->issue('demo_site', 'round', 'inv-1', $deadline) === $t);
    $dump = $store instanceof FakeStore ? json_encode($store->kv) : implode(' ', array_map(fn($k) => $k . (string)$store->r->get($k), $store->r->keys('*')));
    check('the raw token is nowhere in the store, only its hash', strpos($dump, $t) === false && strpos($dump, hash('sha256', $t)) !== false);
    $g = $cap->verify('demo_site', $t);
    check('verify reads scope and subject', $g !== null && $g['scope'] === 'round' && $g['subject'] === 'inv-1');
    check('verify changes nothing (a mail scanner may open it)', $cap->verify('demo_site', $t) !== null);
    check('a token from another site grants nothing there', $cap->verify('other_site', $t) === null);
    check('a malformed token is refused', $cap->verify('demo_site', 'x') === null);

    $s = $cap->exchange('demo_site', $t, 'round');
    check('exchange yields a session with a csrf token', $s !== null && strlen($s['session_id']) === 43 && strlen($s['csrf']) === 43);
    check('exchange with the wrong scope is refused', $cap->exchange('demo_site', $t, 'offer') === null);
    check('the session is found again', ($cap->session('demo_site', $s['session_id'])['subject'] ?? '') === 'inv-1');
    check('a multi-use link survives an exchange', $cap->verify('demo_site', $t) !== null);

    $o = $cap->issue('demo_site', 'offer', 'gap-7', $now + 3600 * 1000, true);
    $first = $cap->exchange('demo_site', $o, 'offer');
    $second = $cap->exchange('demo_site', $o, 'offer');
    check('a single-use link works once', $first !== null);
    check('and not a second time (the first exchange consumed it)', $second === null);

    $r = $cap->rotate('demo_site', 'round', 'inv-1', $deadline);
    check('rotate gives a different token', $r !== $t);
    check('the old token stops working', $cap->verify('demo_site', $t) === null);
    check('the new one works', $cap->verify('demo_site', $r) !== null);
    check('revoke stops it', $cap->revoke('demo_site', 'round', 'inv-1') && $cap->verify('demo_site', $r) === null);
    $re = $cap->issue('demo_site', 'round', 'inv-1', $deadline);
    check('issuing after a revoke yields a fresh token, not the revoked one', $re !== $r && $re !== $t && $cap->verify('demo_site', $re) !== null);

    $login = $cap->issue('demo_site', 'alpha', 'web-1', $now + 15 * 60 * 1000, true);
    $long = $cap->exchange('demo_site', $login, 'alpha', $now + 30 * 86400 * 1000);
    check('a session may outlive its link when the exchange says so', $long !== null && $long['expires_ms'] === $now + 30 * 86400 * 1000);
    $advance($now, 20 * 60 * 1000);
    check('twenty minutes on, the fifteen-minute link is dead and the session is not', $cap->verify('demo_site', $login) === null && $cap->session('demo_site', $long['session_id']) !== null);
    $capped = $cap->exchange('demo_site', $cap->issue('demo_site', 'alpha', 'web-2', $now + 60_000, true), 'alpha', $now - 1);
    check('a session end in the past falls back to the link expiry', $capped !== null && $capped['expires_ms'] === $now + 60_000);

    $short = $cap->issue('demo_site', 'feed', 'agent-3', $now + 2000);
    $advance($now, 3000);
    check('an expired link grants nothing', $cap->verify('demo_site', $short) === null);
    $cap->endSession('demo_site', $s['session_id']);
    check('an ended session is gone', $cap->session('demo_site', $s['session_id']) === null);

    $thrown = false;
    try { $cap->issue('demo_site', 'round', 'bad subject!', $deadline); } catch (\InvalidArgumentException $e) { $thrown = true; }
    check('a subject outside the allowed alphabet is refused', $thrown);
    $thrown = false;
    try { $cap->issue('demo_site', 'round', 'inv-2', $now - 1); } catch (\InvalidArgumentException $e) { $thrown = true; }
    check('an expiry in the past is refused', $thrown);

    $inst->setValue(null, null);
    $again = CapabilityManager::getInstance();
    $again->initialize(['storage' => $store, 'clock' => $clock]);
    $fromSecond = $again->issue('demo_site', 'round', 'inv-1', $deadline);
    check('a second process derives the same link from the stored secret', $fromSecond === $re);
}

$fake = new FakeStore();
suite('capability (memory)', $fake, function (int &$now, int $ms) use ($fake) { $now += $ms; $fake->now = $now; });

$port = (int)(getenv('CAPABILITY_VALKEY_PORT') ?: 0);
if ($port > 0) {
    $auth = (string)(getenv('CAPABILITY_VALKEY_AUTH') ?: '');
    $real = new RealStore($port, $auth);
    if ($auth === '') {
        $real->r->flushAll();
        foreach (['gnode_gcore_config.lua', 'gnode_lock.lua'] as $lib) {
            $src = file_get_contents((getenv('GNODE_FUNCTIONS_DIR') ?: __DIR__ . '/../../gNode/daemon/functions') . '/' . $lib);
            $real->r->rawCommand('FUNCTION', 'LOAD', 'REPLACE', $src);
        }
    }
    suite("capability (ValKey :$port" . ($auth !== '' ? ', as ' . explode(':', $auth)[0] : '') . ")", $real, function (int &$now, int $ms) { $now += $ms; });
}

printf("%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
