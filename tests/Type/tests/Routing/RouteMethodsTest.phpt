--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Route;

/** @return list<string> */
function route_verbs(Route $route): array
{
    /** @psalm-check-type-exact $methods = list<string> */
    $methods = $route->methods();

    return $methods;
}
?>
--EXPECTF--
