--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * The no-arg `route()` is nullable on every supported Laravel: the resolver returns null when no
 * route matched (error pages, console, queue). Typing it as a non-null Route makes `?->` and
 * `instanceof` guards report redundant checks.
 */
function request_route_is_nullable(Request $request): void
{
    $_route = $request->route();
    /** @psalm-check-type-exact $_route = Route|null */
}

function request_route_nullsafe_name(Request $request): ?string
{
    return $request->route()?->getName();
}

function request_route_instanceof_guard(Request $request): ?string
{
    $route = $request->route();

    if ($route instanceof Route) {
        return $route->getName();
    }

    return null;
}

function request_route_with_arguments_unchanged(Request $request): void
{
    $_noDefault = $request->route('id');
    /** @psalm-check-type-exact $_noDefault = null|object|string */

    $_stringDefault = $request->route('id', 'fallback-string');
    /** @psalm-check-type-exact $_stringDefault = object|string|null */
}
?>
--EXPECTF--
