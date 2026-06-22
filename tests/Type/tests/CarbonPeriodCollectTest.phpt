--FILE--
<?php declare(strict_types=1);

/**
 * Cross-version regression (#922, #1142) — runs on BOTH Carbon majors (no SKIPIF) so the two
 * `CarbonStubProvider` CarbonPeriod variants stay in lockstep on the one observable result:
 *
 *  1. `MissingDependency` cascade: a CarbonPeriod whose parent is unresolved collapses
 *     `CarbonPeriod::create()` to `never`. On Carbon 3 the parent is `Carbon\DatePeriodBase`,
 *     declared via a runtime `require` from `vendor/nesbot/carbon/lazy/` (outside Carbon's
 *     composer autoload) that `CarbonStubProvider` registers as a stub; on Carbon 2 there is no
 *     DatePeriodBase, and shipping the Carbon-3 stub there was the original cause of #1142.
 *
 *  2. `Collection<int, mixed>` widening: CarbonPeriod carries no iterable template, so Psalm
 *     widens the yield to `mixed`. The plugin's per-major CarbonPeriod stub pins the value type
 *     (`@implements IteratorAggregate<int, CarbonInterface>` on Carbon 3, `@implements
 *     Iterator<int, CarbonInterface>` on Carbon 2) so `collect()` binds `TValue = CarbonInterface`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/922
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1142
 */

use Carbon\CarbonPeriod;

$_dates = collect(CarbonPeriod::create('2026-01-01', '2026-01-10'));
/** @psalm-check-type-exact $_dates = \Illuminate\Support\Collection<int, \Carbon\CarbonInterface> */
?>
--EXPECTF--
