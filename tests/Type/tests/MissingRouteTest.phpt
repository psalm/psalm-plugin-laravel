--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

enum RouteEnum: string
{
    case Dashboard = 'dashboard';
}

/**
 * The psalm-tester harness boots via the Testbench package fallback, which never loads an
 * application's route files (ApplicationProvider::doGetApp() branch 3): the named-route table
 * is always empty there. Plugin::initMissingRouteHandler() skips calling
 * MissingRouteHandler::init() on an empty table, so the handler stays disabled and every call
 * below — including ones that would be flagged against a real route table — must stay silent.
 * The positive (actual emission against a real, non-empty route table) is covered by
 * tests/Unit/Handlers/MissingRouteEmissionTest.php, a subprocess fixture with a real
 * bootstrap/app.php and withRouting().
 */
function literal_undefined_name(): string
{
    return route('definitely-not-a-real-route-name');
}

function non_literal_name(string $name): string
{
    return route($name);
}

/**
 * @param list<mixed> $args
 * @psalm-suppress MixedArgument unrelated to MissingRoute — spread hides the argument types too
 */
function spread_args(array $args): string
{
    return route(...$args);
}

function to_route_helper(): \Symfony\Component\HttpFoundation\RedirectResponse
{
    return to_route('definitely-not-a-real-route-name');
}

function url_generator_facade(): string
{
    return URL::route('definitely-not-a-real-route-name');
}

function url_generator_signed(): string
{
    return URL::signedRoute('definitely-not-a-real-route-name');
}

function url_generator_temporary_signed(): string
{
    return URL::temporarySignedRoute('definitely-not-a-real-route-name', now()->addMinutes(5));
}

function redirect_facade(): \Illuminate\Http\RedirectResponse
{
    return Redirect::route('definitely-not-a-real-route-name');
}

function redirect_helper(): \Illuminate\Http\RedirectResponse
{
    return redirect()->route('definitely-not-a-real-route-name');
}

function enum_route_name(): string
{
    return route(RouteEnum::Dashboard);
}
?>
--EXPECTF--
