--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Laravel 12+'s literal() declares `@return mixed`, unlike 11's `@return \stdClass`.
// LiteralHandler defers to the reflected type for literal(...$arr) (property names
// depend on runtime array contents), so the asserted type differs by version.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

function literal_unpack_falls_back_to_reflected_type(array $arr): void
{
    $_r = literal(...$arr);
    /** @psalm-check-type-exact $_r = mixed */
}
?>
--EXPECTF--
