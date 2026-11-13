--FILE--
<?php declare(strict_types=1);

/**
 * Carbon's `CarbonInterface::isoWeekday()` is dual-purpose:
 *   - `null` (default) returns the ISO weekday (1=Monday..7=Sunday).
 *   - any other value returns a new `static` instance set to that weekday.
 *
 * The stubs under `stubs/integrations/carbon/` narrow the getters (`int<1, 7>`, `int<0, 6>`) and
 * keep the setters `static` on every supported Carbon version (the setter used to collapse to the
 * getter branch, #1059; `tz('UTC')` is the case that mattered on Carbon 3.10 and 3.11).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/922
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1059
 */

use Carbon\Carbon;
use Carbon\WeekDay;

$_dow = Carbon::now()->isoWeekday();
/** @psalm-check-type-exact $_dow = int<1, 7> */

$_mondayInt = Carbon::now()->isoWeekday(1);
/** @psalm-check-type-exact $_mondayInt = \Carbon\Carbon&static */

$_mondayEnum = Carbon::now()->isoWeekday(WeekDay::Monday);
/** @psalm-check-type-exact $_mondayEnum = \Carbon\Carbon&static */

$_weekday = Carbon::now()->weekday();
/** @psalm-check-type-exact $_weekday = int<0, 6> */

$_zone = Carbon::now()->tz();
/** @psalm-check-type-exact $_zone = string */

$_utc = Carbon::now()->tz('UTC');
/** @psalm-check-type-exact $_utc = \Carbon\Carbon&static */
?>
--EXPECTF--
