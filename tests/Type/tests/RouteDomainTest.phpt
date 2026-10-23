--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Route;

// domain() is get-or-set: a null arg reads the domain (string|null), any other arg
// sets it and returns the route for chaining.

enum RouteDomainEnum: string
{
    case Api = 'api.example.com';
}

/**
 * The reported shape (#1929): the setter result must stay a Route, not `$this|string|null`.
 */
function reported_chain(Route $route): void
{
    $_named = $route->domain('api.example.com')->name('x');
    /** @psalm-check-type-exact $_named = Route&static */
}

function getter_and_setter(Route $route): void
{
    $_get = $route->domain();
    /** @psalm-check-type-exact $_get = string|null */

    $_explicit_null = $route->domain(null);
    /** @psalm-check-type-exact $_explicit_null = string|null */

    $_set = $route->domain('api.example.com');
    /** @psalm-check-type-exact $_set = Route&static */
}

function backed_enum_argument(Route $route): void
{
    $_set = $route->domain(RouteDomainEnum::Api);
    /** @psalm-check-type-exact $_set = Route&static */

    $_chained = $route->domain(RouteDomainEnum::Api)->name('x');
    /** @psalm-check-type-exact $_chained = Route&static */
}

function nullable_variable_argument(Route $route, ?string $domain): void
{
    $_result = $route->domain($domain);
    /** @psalm-check-type-exact $_result = Illuminate\Routing\Route&static|null|string */
}
?>
--EXPECTF--
