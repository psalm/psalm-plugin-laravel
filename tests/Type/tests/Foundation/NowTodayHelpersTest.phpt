--FILE--
<?php declare(strict_types=1);

// now() returns Carbon, not mixed
$_now = now();
/** @psalm-check-type-exact $_now = \Illuminate\Support\Carbon */

// now() accepts a timezone string
$_nowTz = now('UTC');
/** @psalm-check-type-exact $_nowTz = \Illuminate\Support\Carbon */

// now() accepts a DateTimeZone object
$_nowDtz = now(new \DateTimeZone('UTC'));
/** @psalm-check-type-exact $_nowDtz = \Illuminate\Support\Carbon */

// today() returns Carbon, not mixed
$_today = today();
/** @psalm-check-type-exact $_today = \Illuminate\Support\Carbon */

// today() accepts a timezone string
$_todayTz = today('UTC');
/** @psalm-check-type-exact $_todayTz = \Illuminate\Support\Carbon */

// today() accepts a DateTimeZone object
$_todayDtz = today(new \DateTimeZone('UTC'));
/** @psalm-check-type-exact $_todayDtz = \Illuminate\Support\Carbon */
?>
--EXPECT--
