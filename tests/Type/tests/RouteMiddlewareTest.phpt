--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route as RouteFacade;

// Regression for issue #888: chained calls after Route::middleware(...) must not collapse
// to the array branch of the (now-removed) conditional return type. The setter form must
// always return $this so subsequent ->withoutScopedBindings(), ->name(...), or
// RouteCollectionInterface::add() calls type-check.
function middleware_setter_variants_return_this(Route $route): void
{
    $_array = $route->middleware(['web']);
    /** @psalm-check-type-exact $_array = Route&static */

    $_string = $route->middleware('auth');
    /** @psalm-check-type-exact $_string = Route&static */

    // Variadic call form — the original reason `@psalm-variadic` lives on the stub.
    $_variadic = $route->middleware('auth', 'web');
    /** @psalm-check-type-exact $_variadic = Route&static */

    // No-arg form: the fix deliberately drops the `array` branch of the original
    // conditional return, so even `middleware()` resolves to `$this`. Pin this so a
    // future contributor restoring the conditional (`($middleware is null ? ...)`)
    // can't silently reintroduce #888.
    $_get = $route->middleware();
    /** @psalm-check-type-exact $_get = Route&static */
}

// The exact construct from the #888 reproducer title: chaining ->withoutScopedBindings()
// after ->middleware([...]). If middleware() collapses to array, the chained call raises
// InvalidMethodCall.
function middleware_then_without_scoped_bindings(Route $route): void
{
    $_chain = $route->middleware(['web'])->withoutScopedBindings();
    /** @psalm-check-type-exact $_chain = Route&static */
}

// Facade -> verb -> ->middleware([...]). The Route facade resolution path was flagged in
// the issue as a possible widening source; this pins the chain return type for that path.
function facade_chain_after_middleware(): void
{
    $_chain = RouteFacade::get('/test', fn () => 'ok')->middleware(['web']);
    /** @psalm-check-type-exact $_chain = Route&static */
}

// Passing the chain result to RouteCollectionInterface::add — the second concrete failure
// mode in #888. If middleware() collapses to array<array-key, string>, this raises
// InvalidArgument.
function pass_to_route_collection_add(RouteCollectionInterface $routes): Route
{
    return $routes->add(
        RouteFacade::get('/test', fn () => 'ok')->middleware(['web']),
    );
}
?>
--EXPECTF--
