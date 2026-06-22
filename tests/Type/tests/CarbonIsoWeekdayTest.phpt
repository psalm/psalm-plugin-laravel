--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Carbon\WeekDay (used below) is a Carbon 3 enum, and the dual-purpose narrowing stub that
// drives the asserted setter type is registered only on Carbon >= 3.0. Skip on Carbon 2.
\Tests\Psalm\LaravelPlugin\Type\CarbonVersion::skipBelow('3.0.0');
--FILE--
<?php declare(strict_types=1);

/**
 * Carbon's `CarbonInterface::isoWeekday()` is dual-purpose:
 *   - `null` (default) returns the ISO weekday (1=Monday..7=Sunday).
 *   - any other value returns a new `static` instance set to that weekday.
 *
 * The getter assertion is intentionally the non-exact `int` rather than
 * `int<1, 7>`, because the inferred getter type is version-dependent:
 *   - nesbot/carbon < 3.12: the plugin's pre-3.12 stub narrows it to `int<1, 7>`.
 *   - nesbot/carbon >= 3.12: Carbon's own `@return ($value is null ? int : static)`
 *     drives inference (the plugin skips its redeclaration to dodge a Psalm
 *     conditional-merge bug, see #1059), so the getter is the wider `int`.
 * `int<1, 7>` is a subtype of `int`, so one non-exact check passes on both.
 *
 * The setter assertions stay exact: returning `static` (not the getter's int) is
 * the behaviour that regressed on carbon >= 3.12, so it is the load-bearing guard.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/922
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1059
 */

use Carbon\Carbon;
use Carbon\WeekDay;

$_dow = Carbon::now()->isoWeekday();
/** @psalm-check-type $_dow = int */

$_mondayInt = Carbon::now()->isoWeekday(1);
/** @psalm-check-type-exact $_mondayInt = \Carbon\Carbon&static */

$_mondayEnum = Carbon::now()->isoWeekday(WeekDay::Monday);
/** @psalm-check-type-exact $_mondayEnum = \Carbon\Carbon&static */
?>
--EXPECTF--
