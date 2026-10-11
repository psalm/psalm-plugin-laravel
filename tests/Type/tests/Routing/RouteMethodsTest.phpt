--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Route;

/** @return array<string> */
function route_verbs(Route $route): array
{
    /** @psalm-check-type-exact $methods = array<array-key, string> */
    $methods = $route->methods();

    return $methods;
}
?>
--EXPECTF--
