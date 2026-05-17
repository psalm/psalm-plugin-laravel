--FILE--
<?php declare(strict_types=1);

/**
 * Carbon's `CarbonInterface::isoWeekday()` is dual-purpose:
 *   - `null` (default) → returns ISO weekday `int<1, 7>` (1=Monday..7=Sunday).
 *   - any other value  → returns a new `static` instance set to that weekday.
 *
 * Source declares the union `static|int` so the type narrowing belongs in a
 * stub.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/922
 */

use Carbon\Carbon;
use Carbon\WeekDay;

$_dow = Carbon::now()->isoWeekday();
/** @psalm-check-type-exact $_dow = int<1, 7> */

$_mondayInt = Carbon::now()->isoWeekday(1);
/** @psalm-check-type-exact $_mondayInt = \Carbon\Carbon&static */

$_mondayEnum = Carbon::now()->isoWeekday(WeekDay::Monday);
/** @psalm-check-type-exact $_mondayEnum = \Carbon\Carbon&static */
?>
--EXPECTF--
