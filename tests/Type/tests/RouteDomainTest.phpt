--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

// Setter form: a non-null domain returns $this so the route stays chainable.
function domain_setter_returns_this(Route $route): void
{
    $_set = $route->domain('example.com');
    /** @psalm-check-type-exact $_set = Route&static */
}

// Getter form: the null arg returns the configured domain (string|null).
function domain_getter_returns_string_or_null(Route $route): void
{
    $_get = $route->domain();
    /** @psalm-check-type-exact $_get = string|null */

    $_explicit_null = $route->domain(null);
    /** @psalm-check-type-exact $_explicit_null = string|null */
}

// Chaining after the setter must type-check — the reflected flat union
// `$this|string|null` would break this (next call lands on string|null).
function domain_then_name(Route $route): Route
{
    return $route->domain('example.com')->name('home');
}

// Facade -> verb -> ->domain(...) chain.
function facade_chain_after_domain(): void
{
    $_chain = RouteFacade::get('/test', fn () => 'ok')->domain('example.com');
    /** @psalm-check-type-exact $_chain = Route&static */
}
?>
--EXPECTF--
