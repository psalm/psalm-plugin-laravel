<?php

declare(strict_types=1);

namespace MissingRouteFixture;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;

enum RouteEnum: string
{
    case Dashboard = 'dashboard';
}

/** Every registered call site the handler covers, one clean and one typo'd usage each. */
final class UsesRoutes
{
    public function routeHelperTypo(): string
    {
        return route('dashbaord');
    }

    public function routeHelperClean(): string
    {
        return route('dashboard');
    }

    public function toRouteHelperTypo(): RedirectResponse
    {
        return to_route('posts.hsow');
    }

    public function toRouteHelperClean(): RedirectResponse
    {
        return to_route('posts.show');
    }

    public function urlFacadeRouteTypo(): string
    {
        return URL::route('dashbaord');
    }

    public function urlFacadeSignedRouteTypo(): string
    {
        return URL::signedRoute('dashbaord');
    }

    public function urlFacadeTemporarySignedRouteTypo(): string
    {
        return URL::temporarySignedRoute('dashbaord', now()->addMinutes(5));
    }

    public function redirectFacadeRouteTypo(): RedirectResponse
    {
        return Redirect::route('dashbaord');
    }

    public function redirectHelperRouteTypo(): RedirectResponse
    {
        return redirect()->route('dashbaord');
    }

    public function redirectHelperRouteClean(): RedirectResponse
    {
        return redirect()->route('dashboard');
    }

    /**
     * url() with no path returns \Illuminate\Contracts\Routing\UrlGenerator, not the
     * concrete \Illuminate\Routing\UrlGenerator — a distinct receiver the handler must
     * also register for, or this call site is missed entirely.
     */
    public function urlHelperRouteTypo(): string
    {
        return url()->route('dashbaord');
    }

    public function urlHelperRouteClean(): string
    {
        return url()->route('dashboard');
    }

    /**
     * A leading spread hides the name entirely — must never be flagged.
     * @psalm-suppress MixedArgument unrelated to MissingRoute — spread hides the argument types too
     */
    public function spreadArgsNeverFlagged(): string
    {
        /** @var list<mixed> $args */
        $args = ['dashbaord'];

        return route(...$args);
    }

    /** A dynamic (non-literal) name is never checked. */
    public function nonLiteralNameNeverFlagged(string $name): string
    {
        return route($name);
    }

    /** A BackedEnum route name is a ClassConstFetch, never a String_ — never checked. */
    public function enumNameNeverFlagged(): string
    {
        return route(RouteEnum::Dashboard);
    }
}
