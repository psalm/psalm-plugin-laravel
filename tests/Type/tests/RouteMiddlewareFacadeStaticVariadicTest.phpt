--FILE--
<?php declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Reproducer (documented broken behavior) for the `routes/contact.php` form in
 * invoiceninja:
 *
 *   Route::middleware('contact_db', 'api_secret_check', 'contact_token_auth')
 *       ->prefix('api/v1/contact')->name('api.contact.')->group(function () { ... });
 *
 * The Laravel framework's `RouteRegistrar::__call` resolves `middleware(...)` by
 * collecting variadic strings (`is_array($parameters[0]) ? $parameters[0] : $parameters`),
 * so this call shape is valid at runtime. The `@method static` annotation on
 * `Illuminate\Support\Facades\Route` and on `Illuminate\Routing\RouteRegistrar`
 * declares it as single-arg (`array|string|null $middleware`), so Psalm reports
 * `TooManyArguments` even though the runtime accepts the call.
 *
 * The existing RouteMiddlewareTest covers the Route *instance* form via the
 * `@psalm-variadic` stub on `Illuminate\Routing\Route::middleware`. The facade
 * / RouteRegistrar entry point lacks the same override.
 *
 * Once a plugin stub adds `@psalm-variadic` to RouteRegistrar::middleware (or the
 * Route facade @method static is overridden), the EXPECTF below should be cleared
 * and this becomes a positive regression test.
 */

// Variadic strings (the exact invoiceninja shape).
function test_route_facade_middleware_variadic_strings(): RouteRegistrar
{
    return RouteFacade::middleware('contact_db', 'api_secret_check', 'contact_token_auth');
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
}

?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for Illuminate\Support\Facades\Route::middleware - expecting 1 but saw 3
TooManyArguments on line %d: Too many arguments for Illuminate\Support\Facades\Route::middleware - expecting 1 but saw 2
