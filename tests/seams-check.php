<?php
declare(strict_types=1);
/**
 * Verify the seam contract without a framework boot:  php tests/seams-check.php
 *
 * The property under test is the one the whole model rests on — that an absent
 * or BROKEN filler leaves the free behaviour exactly as it was.
 */

require __DIR__ . '/../Modules/Core/Seams.php';

use gCore\Modules\Core\Seams;

$pass = 0; $fail = 0;
function check(string $what, bool $ok): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %-62s %s\n", $what, $ok ? 'ok' : 'FAILED');
}

echo "seams:\n";

// 1. Unfilled is the identity, for both kinds.
Seams::reset();
check('an unfilled transform returns its value unchanged',
    Seams::apply('metrics.decorate', ['a' => 1], 'site') === ['a' => 1]);
Seams::signal('analytics.event', 'visit', []);
check('an unfilled signal does nothing and does not throw', true);
check('money.exact unfilled yields null, so a caller must fail closed',
    Seams::apply('money.exact', null, 'add', []) === null);

// 2. A filler runs, in priority order.
Seams::reset();
Seams::fill('metrics.decorate', fn(array $m) => $m + ['second' => 2], 20);
Seams::fill('metrics.decorate', fn(array $m) => $m + ['first' => 1], 5);
check('fillers run in priority order',
    array_keys(Seams::apply('metrics.decorate', [], 's')) === ['first', 'second']);

// 3. THE property: a filler that throws costs only its own contribution.
Seams::reset();
Seams::fill('metrics.decorate', function (array $m) { throw new \RuntimeException('boom'); }, 5);
Seams::fill('metrics.decorate', fn(array $m) => $m + ['survived' => true], 10);
$out = Seams::apply('metrics.decorate', ['free' => 'value'], 's');
check('a throwing filler leaves the free value intact',
    ($out['free'] ?? null) === 'value');
check('and the fillers after it still run',
    ($out['survived'] ?? false) === true);

// 4. An \Error, not an \Exception — the failure the Pro packages actually had.
Seams::reset();
Seams::fill('metrics.decorate', function (array $m) { return UndefinedClass::make(); }, 10);
check('an \\Error from a filler is caught, not fatal',
    Seams::apply('metrics.decorate', ['free' => 1], 's') === ['free' => 1]);

// 5. A signal filler that throws does not stop the others.
Seams::reset();
$seen = [];
Seams::fill('analytics.event', function () { throw new \LogicException('x'); }, 5);
Seams::fill('analytics.event', function (string $e) use (&$seen) { $seen[] = $e; }, 10);
Seams::signal('analytics.event', 'visit', []);
check('a throwing signal filler does not stop the rest', $seen === ['visit']);

// 6. A typo is refused loudly, never silently registered.
Seams::reset();
check('filling an undeclared seam is refused',
    Seams::fill('analytics.evnt', fn() => null) === false);
check('and nothing is registered under the typo',
    Seams::filled('analytics.evnt') === false);

// 7. The per-site kill switch beats an already-registered filler.
Seams::reset();
Seams::fill('metrics.decorate', fn(array $m) => $m + ['pro' => 1]);
Seams::block('metrics.decorate');
check('block() drops fillers already registered',
    Seams::apply('metrics.decorate', ['free' => 1], 's') === ['free' => 1]);
check('and refuses new ones',
    Seams::fill('metrics.decorate', fn(array $m) => $m) === false);

// 8. The catalogue is enumerable, which is what makes a seam probeable.
Seams::reset();
$state = Seams::state();
check('state() reports every declared seam',
    array_keys($state) === array_keys(Seams::CATALOGUE));
check('and reports 0 fillers when nothing is filling',
    array_sum($state) === 0);

printf("\n%d ok, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
