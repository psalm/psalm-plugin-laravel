--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Regression test for issue #972 — the `routes/contact.php` form in invoiceninja:
 *
 *   Route::middleware('contact_db', 'api_secret_check', 'contact_token_auth')
 *       ->prefix('api/v1/contact')->name('api.contact.')->group(function () { ... });
 *
 * The Laravel framework's `RouteRegistrar::__call` resolves `middleware(...)` by
 * collecting variadic strings (`is_array($parameters[0]) ? $parameters[0] : $parameters`),
 * so this call shape is valid at runtime. Laravel's source declares
 * `@method static middleware(array|string|null $middleware)` (single-arg) on both
 * `Illuminate\Support\Facades\Route` and `Illuminate\Routing\RouteRegistrar`,
 * which previously caused Psalm to report `TooManyArguments`.
 *
 * Fix uses two stubs:
 *   - `stubs/common/Routing/RouteRegistrar.phpstub` declares `middleware` as a
 *     real method with `@psalm-variadic` (covers the instance form).
 *   - `stubs/common/Support/Facades/Route.phpstub` overrides the facade's
 *     `@method static middleware(...)` to use variadic syntax (covers the
 *     facade form, which Psalm resolves via the facade's own pseudo method
 *     before any rootClass lookup runs).
 */

// Variadic strings (the exact invoiceninja shape). Pin the exact type so a future
// regression that widens to `RouteRegistrar|mixed` can't slip through under the
// covariant return-type-only signal.
function test_route_facade_middleware_variadic_strings(): RouteRegistrar
{
    $_x = RouteFacade::middleware('contact_db', 'api_secret_check', 'contact_token_auth');
    /** @psalm-check-type-exact $_x = RouteRegistrar */
    return $_x;
}

// Single-string form: still valid, must not regress.
function test_route_facade_middleware_single_string(): RouteRegistrar
{
    return RouteFacade::middleware('web');
}

// Array form: documented Laravel signature, must keep working.
function test_route_facade_middleware_array(): RouteRegistrar
{
    return RouteFacade::middleware(['web', 'auth']);
}

// Chain past middleware: ->prefix(...)->name(...) must type-check on the
// RouteRegistrar chain (the same shape as routes/contact.php). Without an
// intermediate assertion, a future regression where ->prefix() collapses to
// mixed could silently keep this test passing on the EXPECTF-listed errors.
function test_route_facade_middleware_chain_intermediate(): void
{
    $_chain = RouteFacade::middleware('contact_db', 'api_secret_check')
        ->prefix('api/v1/contact')
        ->name('api.contact.');
    /** @psalm-check-type-exact $_chain = RouteRegistrar */

    // Full invoiceninja chain including the terminal ->group(\Closure) call. Once the
    // intermediate is RouteRegistrar the source's `@method group(\Closure ...)` resolves,
    // but pin the full shape so a future regression of either link surfaces here.
    RouteFacade::middleware('contact_db', 'api_secret_check')
        ->prefix('api/v1/contact')
        ->name('api.contact.')
        ->group(static fn () => null);
}

?>
--EXPECT--
