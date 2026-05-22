--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Regression test for the `routes/contact.php` form in invoiceninja:
 *
 *   Route::middleware('contact_db', 'api_secret_check', 'contact_token_auth')
 *       ->prefix('api/v1/contact')->name('api.contact.')->group(function () { ... });
 *
 * The Laravel framework's `RouteRegistrar::__call` resolves `middleware(...)` by
 * collecting variadic strings (`is_array($parameters[0]) ? $parameters[0] : $parameters`),
 * so this call shape is valid at runtime. The vendor `@method static` annotation on
 * `Illuminate\Support\Facades\Route` and on `Illuminate\Routing\RouteRegistrar`
 * declares it as single-arg (`array|string|null $middleware`), so without overrides
 * Psalm reports `TooManyArguments`.
 *
 * Coverage:
 *   - The Route *instance* form is covered by RouteMiddlewareTest (via the
 *     `@psalm-variadic` stub on `Illuminate\Routing\Route::middleware`).
 *   - The facade / RouteRegistrar entry points are patched by the
 *     `stubs/common/Support/Facades/Route.phpstub` and
 *     `stubs/common/Routing/RouteRegistrar.phpstub` overrides.
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

// Reverse-chain: registrar produced by ->prefix(), then ->middleware(...) with
// variadic strings. Pins the concrete RouteRegistrar::middleware() override
// directly (rather than the facade __callStatic path), guarding the second
// half of the stub fix from regression independent of the facade entry point.
function test_route_registrar_middleware_variadic_from_prefix(): RouteRegistrar
{
    return RouteFacade::prefix('api/v1/contact')
        ->middleware('contact_db', 'api_secret_check', 'contact_token_auth');
}

?>
--EXPECTF--
