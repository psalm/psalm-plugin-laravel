--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Laravel 11's Date facade declares `create()` as non-null (`@method static Carbon
// create(...)`), unlike Carbon's own `?static` and unlike Laravel 12+'s facade tag
// (`Carbon|null`). DateFacadeHandler swaps the Carbon atomic but preserves whatever
// nullability the facade itself declares, so the asserted type differs by version.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('12.0.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Date;

// Call with scalar args — exercises checkMethodArgs against the synthesised params.
$_create = Date::create(2024, 1, 1);
/** @psalm-check-type-exact $_create = \Illuminate\Support\Carbon */
?>
--EXPECTF--
