<?php
declare(strict_types=1);

namespace gCore\Modules\Core\Utils;

/**
 * Viewkey gate primitives — constant-time validation, salted-hash cookie
 * storage, set/clear cookie helpers.
 *
 * Shared by:
 *   - gTemplate\EnvironmentGate (the public-facing "Under Development"
 *     splash for non-production sites)
 *   - gCore\Modules\Dashboard\Admin\DashboardAdmin (the wp-admin viewkey
 *     bounce gate)
 *
 * Both gates previously carried independent implementations that could
 * drift on cookie name, hashing strategy, expiry semantics, etc.
 * This class is the single canonical source of truth for the viewkey
 * primitive (ROADMAP §B.4 closure).
 *
 * Cookie security: the cookie stores `sha256(viewkey || site-host)` —
 * not the raw viewkey — so a stolen cookie does not leak the secret in
 * cleartext. The salt is the site host (via WP `get_site_url()` when
 * available, falling back to `$_SERVER['HTTP_HOST']` in CLI/test paths)
 * which makes per-site cookies non-portable across multi-site
 * installations.
 *
 * @package gCore
 * @subpackage Core\Utils
 */
final class ViewKeyGate
{
    public const DEFAULT_COOKIE_NAME = 'gcore_viewkey';
    public const DEFAULT_EXPIRY      = 86400; // 24h

    /**
     * Constant-time comparison of an input candidate against the
     * configured viewkey. Returns false if either side is empty.
     */
    public static function validate(string $configured, string $candidate): bool
    {
        if ($configured === '' || $candidate === '') {
            return false;
        }
        return hash_equals($configured, $candidate);
    }

    /**
     * Compute the cookie value that represents an authenticated visitor
     * for this site. The salt binds the cookie to the site host so a
     * cookie issued by site A cannot be replayed at site B.
     */
    public static function cookieValue(string $configured): string
    {
        if ($configured === '') {
            return '';
        }
        return hash('sha256', $configured . self::salt());
    }

    /**
     * Returns true when the current request carries a valid viewkey
     * cookie matching the configured viewkey.
     */
    public static function cookieMatches(string $cookieName, string $configured): bool
    {
        if ($configured === '') {
            return false;
        }
        if (!isset($_COOKIE[$cookieName]) || !is_string($_COOKIE[$cookieName])) {
            return false;
        }
        $expected = self::cookieValue($configured);
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, (string) $_COOKIE[$cookieName]);
    }

    /**
     * Set the viewkey cookie on the response. Mutates `$_COOKIE` in
     * addition to setcookie() so the same request can read the cookie
     * back without round-tripping through the browser.
     *
     * @param int $expirySecs Cookie lifetime in seconds; 0 means session.
     */
    public static function setCookie(
        string $cookieName,
        string $configured,
        int $expirySecs = self::DEFAULT_EXPIRY,
        ?bool $secure = null
    ): void {
        if ($configured === '') {
            return;
        }
        $value = self::cookieValue($configured);
        if ($value === '') {
            return;
        }
        $expires = $expirySecs > 0 ? time() + $expirySecs : 0;
        $secureFlag = $secure ?? self::isHttps();

        setcookie(
            $cookieName,
            $value,
            [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => $secureFlag,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[$cookieName] = $value;
    }

    /**
     * Clear the viewkey cookie. Sets expiry in the past + drops the
     * `$_COOKIE` superglobal entry.
     */
    public static function clearCookie(string $cookieName): void
    {
        setcookie(
            $cookieName,
            '',
            [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        unset($_COOKIE[$cookieName]);
    }

    /**
     * Per-site salt for the cookie hash. Prefers WP `get_site_url()` so
     * multisite installs salt by host; falls back to HTTP_HOST in CLI /
     * test contexts where the WP API isn't available.
     */
    private static function salt(): string
    {
        if (function_exists('get_site_url')) {
            $host = parse_url((string) get_site_url(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (is_string($host) && $host !== '') {
            return $host;
        }
        return 'gcore-viewkey-salt';
    }

    /**
     * Best-effort HTTPS detection — prefers WP's is_ssl() when present,
     * falls back to inspecting the SERVER variables directly so the
     * helper works in non-WP runtimes (CLI, tests).
     */
    private static function isHttps(): bool
    {
        if (function_exists('is_ssl')) {
            return (bool) is_ssl();
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        return false;
    }
}
