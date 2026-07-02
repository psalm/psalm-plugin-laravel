--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Laravel 11's literal() declares `@return \stdClass`, unlike 12+'s `@return mixed`.
// LiteralHandler defers to the reflected type for literal(...$arr) (property names
// depend on runtime array contents), so the asserted type differs by version.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('12.0.0');
--FILE--
<?php declare(strict_types=1);

function literal_unpack_falls_back_to_reflected_type(array $arr): void
{
    $_r = literal(...$arr);
    /** @psalm-check-type-exact $_r = stdClass */
}
?>
--EXPECTF--
