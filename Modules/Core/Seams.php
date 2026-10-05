<?php
declare(strict_types=1);
/**
 * Seams — gCore's extension points, and the null fallback that makes them safe.
 *
 * A seam is a named point in the FREE code where an optional implementation may
 * add to or transform what happens. With nothing filling it, a seam does exactly
 * nothing and the free behaviour stands. That is the whole contract.
 *
 * This replaces class substitution as the way optional capability is added. Under
 * substitution a paid implementation had to re-implement the free manager in
 * order to extend it — 5,081 lines of free managers that a Pro package could only
 * displace, never augment — so a single unimported class name inside one of those
 * packages took the free behaviour down with it. A filler can only reach a site
 * through a registered seam, registration is guarded, and INVOCATION is guarded
 * here rather than trusted to the filler, because `catch (\Exception)` does not
 * catch `\Error` and the packages proved it.
 *
 * @package gCore
 */

namespace gCore\Modules\Core;

final class Seams
{
    public const TRANSFORM = 'transform';
    public const SIGNAL    = 'signal';

    /**
     * Every seam the free code offers. This array IS the optional-capability
     * surface: a capability absent from here cannot be offered to anyone,
     * because nothing in the framework would ever call it.
     *
     * `default` documents what an UNFILLED seam yields. Note that "do nothing"
     * is a statement about the FRAMEWORK's behaviour, not a promise that the
     * operation succeeds: `money.exact` yields null, and a caller that needs an
     * exact amount must refuse to proceed rather than fall back to float
     * arithmetic. Null fallback means unchanged, not quietly worse.
     */
    public const CATALOGUE = [
        'analytics.event' => [
            'kind'    => self::SIGNAL,
            'args'    => '(string $event, array $context)',
            'default' => 'nothing; the free per-day counters are already written',
            'fills'   => 'fan the event out to a richer store for cohorts and funnels',
        ],
        'metrics.decorate' => [
            'kind'    => self::TRANSFORM,
            'args'    => '(array $metrics, string $siteId)',
            'default' => 'the metrics hash as read, unchanged',
            'fills'   => 'add derived fields — learned baselines, load provenance',
        ],
        'translate.alternates' => [
            'kind'    => self::TRANSFORM,
            'args'    => '(array $alternates, int $postId)',
            'default' => 'an empty list, so no hreflang tags are emitted',
            'fills'   => 'supply the alternates; the free code does the rendering',
        ],
        'money.exact' => [
            'kind'    => self::TRANSFORM,
            'args'    => '(?string $amount, string $op, array $operands)',
            'default' => 'null — NO exact arithmetic is available, so a caller that '
                       . 'needs it must fail closed rather than compute with floats',
            'fills'   => 'exact decimal arithmetic via the constellation money service',
        ],
    ];

    /** @var array<string, array<int, callable[]>> name => priority => fillers */
    private static array $fillers = [];

    /** @var array<string, true> seams this site refuses to let anything fill */
    private static array $blocked = [];

    /**
     * Offer a filler for a seam.
     *
     * An undeclared name is REFUSED and logged, never silently accepted. A filler
     * registered against a typo would be indistinguishable from a filler that
     * works and is never reached, and that ambiguity has already cost this estate
     * a subsystem it thought was missing.
     */
    public static function fill(string $name, callable $filler, int $priority = 10): bool
    {
        if (!isset(self::CATALOGUE[$name])) {
            self::log('error', sprintf(
                'refused a filler for undeclared seam "%s"; declared: %s',
                $name, implode(', ', array_keys(self::CATALOGUE))
            ));
            return false;
        }
        if (isset(self::$blocked[$name])) {
            self::log('info', sprintf('seam "%s" is blocked on this site; filler ignored', $name));
            return false;
        }
        self::$fillers[$name][$priority][] = $filler;
        return true;
    }

    /**
     * Run a transform seam. Every filler that throws is logged and skipped, and
     * the value it was handed carries on to the next one, so a broken filler
     * costs its own contribution and nothing else.
     *
     * @param mixed $value The free code's own answer, and the fallback.
     * @return mixed
     */
    public static function apply(string $name, $value, ...$args)
    {
        self::assertDeclared($name, self::TRANSFORM);
        foreach (self::ordered($name) as $filler) {
            try {
                $value = $filler($value, ...$args);
            } catch (\Throwable $e) {
                self::failed($name, $e);
            }
        }
        return $value;
    }

    /**
     * Run a signal seam. Nothing is returned and nothing is expected; a filler
     * that throws is logged and the rest still run.
     */
    public static function signal(string $name, ...$args): void
    {
        self::assertDeclared($name, self::SIGNAL);
        foreach (self::ordered($name) as $filler) {
            try {
                $filler(...$args);
            } catch (\Throwable $e) {
                self::failed($name, $e);
            }
        }
    }

    /** Whether anything is filling this seam — the honest answer to "is X available". */
    public static function filled(string $name): bool
    {
        return !empty(self::$fillers[$name]);
    }

    /**
     * Refuse every filler for a seam on this site, including ones already
     * registered. The per-site kill switch, at seam granularity rather than
     * per-manager: a capability whose backend is down is held by name.
     */
    public static function block(string $name): void
    {
        self::$blocked[$name] = true;
        unset(self::$fillers[$name]);
    }

    /** name => number of fillers. What a probe iterates and an admin screen shows. */
    public static function state(): array
    {
        $out = [];
        foreach (self::CATALOGUE as $name => $_) {
            $out[$name] = isset(self::$blocked[$name])
                ? -1
                : count(self::ordered($name));
        }
        return $out;
    }

    /** Test seam only: drop all registrations. */
    public static function reset(): void
    {
        self::$fillers = [];
        self::$blocked = [];
    }

    /** @return callable[] */
    private static function ordered(string $name): array
    {
        if (empty(self::$fillers[$name])) {
            return [];
        }
        $byPriority = self::$fillers[$name];
        ksort($byPriority);
        return array_merge(...array_values($byPriority));
    }

    private static function assertDeclared(string $name, string $kind): void
    {
        if (!isset(self::CATALOGUE[$name])) {
            self::log('error', sprintf('seam "%s" is not declared in the catalogue', $name));
            return;
        }
        if (self::CATALOGUE[$name]['kind'] !== $kind) {
            self::log('error', sprintf(
                'seam "%s" is a %s and was invoked as a %s',
                $name, self::CATALOGUE[$name]['kind'], $kind
            ));
        }
    }

    private static function failed(string $name, \Throwable $e): void
    {
        // Error level, not debug: the default log level is warning, so a filler
        // failing would otherwise be silent exactly where it matters.
        self::log('error', sprintf(
            'filler for seam "%s" threw %s: %s — falling back to the free behaviour',
            $name, get_class($e), $e->getMessage()
        ));
    }

    private static function log(string $level, string $message): void
    {
        if (function_exists('error_log')) {
            error_log(sprintf('[gCore][seams][%s] %s', $level, $message));
        }
    }
}
