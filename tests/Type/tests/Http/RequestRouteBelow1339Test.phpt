--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('13.39.0');
--FILE--
<?php declare(strict_types=1);

/**
 * Companion to RequestRouteTest: before Laravel 13.39.0 the no-arg `route()` is declared as a
 * non-null `Route` (the stubs/13.39.0 nullable override does not load), so the common stub's
 * non-null return applies.
 */
function request_route_is_non_null_below_13_39(\Illuminate\Http\Request $request): void
{
    $_route = $request->route();
    /** @psalm-check-type-exact $_route = \Illuminate\Routing\Route */
}
?>
--EXPECTF--
