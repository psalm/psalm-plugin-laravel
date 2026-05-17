--FILE--
<?php declare(strict_types=1);

/**
 * Regression for issue #922 — two related defects covered in one expression:
 *
 *  1. `MissingDependency` cascade: `Carbon\DatePeriodBase` is declared via
 *     runtime `require` from `vendor/nesbot/carbon/lazy/`, outside Carbon's
 *     composer autoload. `CarbonStubProvider` registers Carbon's own lazy
 *     file as a stub; without it `CarbonPeriod::create()` collapses to
 *     `never` and every consumer surfaces as dead code.
 *
 *  2. `Collection<int, mixed>` widening: Carbon's `CarbonPeriod::getIterator()`
 *     returns untemplated `Generator`, so Psalm cannot resolve the iterable
 *     yield type. `stubs/common/Carbon/CarbonPeriod.phpstub` pins
 *     `@implements IteratorAggregate<int, CarbonInterface>` so `collect()`
 *     binds `TValue = CarbonInterface`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/922
 */

use Carbon\CarbonPeriod;

$_dates = collect(CarbonPeriod::create('2026-01-01', '2026-01-10'));
/** @psalm-check-type-exact $_dates = \Illuminate\Support\Collection<int, \Carbon\CarbonInterface> */
?>
--EXPECTF--
