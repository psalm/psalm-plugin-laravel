<?php

declare(strict_types=1);

namespace UnregisteredRouteNameFixture;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

enum RouteEnum: string
{
    case Dashboard = 'dashboard';
    case Legacy = 'dashboard-legacy';
}

/** Every call site the handler covers: typo'd usages, plus clean and skipped shapes that must stay silent. */
final class UsesRoutes
{
    private const DASHBOARD = 'dashboard';

    private const LEGACY = 'dashboard-legacy';

    public function routeHelperTypo(): string
    {
        return route('dashboard-legacy');
    }

    public function routeHelperClean(): string
    {
        return route('dashboard');
    }

    public function toRouteHelperTypo(): RedirectResponse
    {
        return to_route('posts.hsow');
    }

    public function urlFacadeRouteTypo(): string
    {
        return URL::route('dashboard-legacy');
    }

    public function urlFacadeSignedRouteTypo(): string
    {
        return URL::signedRoute('dashboard-legacy');
    }

    public function urlFacadeTemporarySignedRouteTypo(): string
    {
        return URL::temporarySignedRoute('dashboard-legacy', now()->addMinutes(5));
    }

    public function redirectFacadeRouteTypo(): RedirectResponse
    {
        return Redirect::route('dashboard-legacy');
    }

    public function redirectHelperRouteTypo(): RedirectResponse
    {
        return redirect()->route('dashboard-legacy');
    }

    /**
     * url() with no path returns \Illuminate\Contracts\Routing\UrlGenerator, not the
     * concrete \Illuminate\Routing\UrlGenerator — a distinct receiver the handler must
     * also register for, or this call site is missed entirely.
     */
    public function urlHelperRouteTypo(): string
    {
        return url()->route('dashboard-legacy');
    }

    /**
     * The name sits at offset 1 here, so a positional read of the first argument would
     * check `false` (and silently skip) instead of the typo. `route()` and UrlGenerator's
     * route family name the parameter `$name`.
     */
    public function routeHelperNamedArgsReorderedTypo(): string
    {
        return route(absolute: false, name: 'dashboard-legacy');
    }

    /** The same shape with a registered name must stay silent. */
    public function routeHelperNamedArgsReorderedClean(): string
    {
        return route(absolute: false, name: 'dashboard');
    }

    /**
     * `to_route()` names the parameter `$route`, not `$name` — the second identifier the
     * handler accepts. Laravel's two signature families disagree here, so covering only
     * one of them would leave half the call sites resolved positionally.
     */
    public function toRouteHelperNamedArgTypo(): RedirectResponse
    {
        return to_route(route: 'posts.hsow');
    }

    /** Redirector's route family also names the parameter `$route`. */
    public function redirectHelperRouteNamedArgTypo(): RedirectResponse
    {
        return redirect()->route(route: 'dashboard-legacy');
    }

    /**
     * A leading spread hides the name entirely — must never be flagged.
     * @psalm-suppress MixedArgument unrelated to UnregisteredRouteName — spread hides the argument types too
     */
    public function spreadArgsNeverFlagged(): string
    {
        /** @var list<mixed> $args */
        $args = ['dashboard-legacy'];

        return route(...$args);
    }

    /** A dynamic (non-literal) name is never checked. */
    public function nonLiteralNameNeverFlagged(string $name): string
    {
        return route($name);
    }

    public function enumNameTypo(): string
    {
        return route(RouteEnum::Legacy);
    }

    /** The facade `@method` path, where Psalm has not analysed the arguments yet. */
    public function constantNameTypo(): string
    {
        return URL::route(self::LEGACY);
    }

    public function enumAndConstantNamesClean(): string
    {
        return route(RouteEnum::Dashboard) . URL::route(self::DASHBOARD);
    }
}
