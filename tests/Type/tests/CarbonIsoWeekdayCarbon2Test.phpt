--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Carbon-2 counterpart of CarbonIsoWeekdayTest: the dual-purpose narrowing stubs type their
// params as Carbon\WeekDay (a Carbon 3 enum) and are NOT registered on Carbon 2, so isoWeekday()
// must resolve via Carbon 2's own untyped `static|int` with no Carbon\WeekDay reference leaking.
// Skip on Carbon 3, where CarbonIsoWeekdayTest covers the narrowed shape instead.
\Tests\Psalm\LaravelPlugin\Type\CarbonVersion::skipFrom('3.0.0');
--FILE--
<?php declare(strict_types=1);

/**
 * Guards the `>=3.0` lower bound of the pre-3.12 narrowing gate (#1142). Before the gate fix the
 * plugin registered the Carbon\WeekDay-typed redeclarations on Carbon 2 too, producing
 * `UndefinedClass: Carbon\WeekDay`. With the gate, Carbon 2 keeps its native dual-purpose
 * `isoWeekday($value = null): static|int` (un-narrowed: the setter is NOT collapsed to `static`).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1142
 */

use Carbon\Carbon;

$_set = Carbon::now()->isoWeekday(1);
/** @psalm-check-type-exact $_set = \Carbon\Carbon&static|int */
?>
--EXPECTF--
