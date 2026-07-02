--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Laravel 12+'s Date facade declares `create()` as nullable (`@method static
// Carbon|null create(...)`), matching Carbon's own `?static`. Laravel 11's facade
// tag omits the `|null`, so this is asserted separately from DateFacadeCreateL11Test.phpt.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;

// Call with scalar args — exercises checkMethodArgs against the synthesised params.
$_create = Date::create(2024, 1, 1);
/** @psalm-check-type-exact $_create = \Illuminate\Support\Carbon|null */
?>
--EXPECTF--
