--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;

// Default app keeps Illuminate\Support\Carbon as the date class, so the handler's
// Carbon -> configured-class swap is the identity here. This guards that the handler
// reproduces the facade's `@method` return types exactly (shape + nullability) and
// that synthesising params for the pseudo `@method` calls does not regress arg checks.
// The configured-class swap itself (e.g. CarbonImmutable) is covered by the unit test,
// since the handler reads the booted app's configured class, not the analysed code.

// Non-null date methods.
$_now = Date::now();
/** @psalm-check-type-exact $_now = \Illuminate\Support\Carbon */

$_today = Date::today();
/** @psalm-check-type-exact $_today = \Illuminate\Support\Carbon */

$_parse = Date::parse('2024-01-01');
/** @psalm-check-type-exact $_parse = \Illuminate\Support\Carbon */

// create()'s nullability flips between Laravel 11 (non-null) and 12+ (Carbon|null) in
// the facade's own `@method` tag, so it's asserted separately in DateFacadeCreateL11Test.phpt
// / DateFacadeCreateL12Test.phpt instead of here. It still exercises checkMethodArgs
// against the synthesised params there.

// Call with an object arg.
$_instance = Date::instance(new \DateTime());
/** @psalm-check-type-exact $_instance = \Illuminate\Support\Carbon */

// Nullable factory methods — the `|null` must survive the swap.
$_make = Date::make('2024-01-01');
/** @psalm-check-type-exact $_make = \Illuminate\Support\Carbon|null */

$_testNow = Date::getTestNow();
/** @psalm-check-type-exact $_testNow = \Illuminate\Support\Carbon|null */

// Defer path: methods whose `@method` return type has no Carbon atomic are left to
// Psalm's own `@method` resolution (the handler returns null from both providers).
$_locale = Date::getLocale();
/** @psalm-check-type-exact $_locale = string */

// Defer path with args — guards that the params provider also defers for a method the
// handler does not retype, so arg checking still flows through Psalm's `@method` path.
$_hasFormat = Date::hasFormat('2024-01-01', 'Y-m-d');
/** @psalm-check-type-exact $_hasFormat = bool */
?>
--EXPECTF--
