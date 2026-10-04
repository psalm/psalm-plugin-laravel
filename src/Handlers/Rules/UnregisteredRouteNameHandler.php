<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Issues\UnregisteredRouteName;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Detects calls to route(), to_route(), URL::route()/signedRoute()/temporarySignedRoute(),
 * Redirect::route(), redirect()->route(), and url()->route() whose route name is not
 * registered in the booted application, and flags it as {@see UnregisteredRouteName}.
 *
 * Diagnostic only: every provider method returns null, so stub/native return types are untouched.
 *
 * The canonical URL/Redirect facades are hardcoded next to {@see FacadeMapProvider}'s aliases so
 * the rule still fires in apps that trim their alias registry (same convention as
 * {@see \Psalm\LaravelPlugin\Handlers\Views\MissingViewHandler}).
 *
 * Only string literal names are checked. A leading spread, a non-literal expression, and a
 * `\BackedEnum` name (a `ClassConstFetch`, not a `String_`) are skipped.
 *
 * The rule arms itself in {@see self::init()} and stays off (no findings, no warning) when
 * the route table cannot be trusted: the Testbench fallback or a swallowed bootstrap error, an
 * empty table (a package/library project, or a route cache without named routes), or an app that
 * registers a missing-named-route resolver, which `UrlGenerator::route()` consults before throwing.
 *
 * Known limitations: `Route::has()` guards are not tracked; a resolver registered after boot is
 * invisible; conditionally-registered routes and a stale route cache can produce false
 * positives; Blade templates are out of scope.
 *
 * @see https://laravel.com/docs/routing#named-routes
 */
final class UnregisteredRouteNameHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /**
     * Parameter identifiers the route name can arrive under, for named-argument call sites.
     *
     * The `route()` helper and UrlGenerator's route family name it `$name`; `to_route()` and
     * Redirector's route family name it `$route`. No signature declares both, so accepting
     * either cannot retarget a valid call: on the other family it is already an
     * unknown-named-argument error that Psalm reports itself.
     *
     * @var list<string>
     */
    private const ROUTE_NAME_PARAMETERS = ['name', 'route'];

    /** @var array<string, true> Registered route names, from the booted app's router */
    private static array $names = [];

    private static bool $enabled = false;

    public static function reset(): void
    {
        self::$names = [];
        self::$enabled = false;
    }

    /**
     * Read the booted app's named-route table and arm the rule when it can be trusted.
     *
     * The Testbench fallback is excluded even though its table is non-empty on Laravel 12
     * (the skeleton's local disk registers `storage.local*`), so an emptiness check alone
     * would arm the rule and flag every route name a package uses.
     */
    public static function init(Application $app): void
    {
        if (!ApplicationProvider::isProjectBootTrusted()) {
            return;
        }

        /** @var \Illuminate\Routing\Router $router */
        $router = $app->make('router');

        /** @var array<string, true> $names */
        $names = \array_fill_keys(\array_keys($router->getRoutes()->getRoutesByName()), true);

        // No known names would make every route name "missing".
        if ($names === [] || self::hasMissingNamedRouteResolver($app)) {
            return;
        }

        self::$names = $names;
        self::$enabled = true;
    }

    /**
     * `UrlGenerator::route()` consults a resolver registered through `resolveMissingNamedRoutesUsing()`
     * BEFORE throwing, so with one registered, "absent from the table" no longer implies "fails at
     * runtime". There is no public accessor, hence the reflection. A `url` service that is not
     * Laravel's UrlGenerator cannot be probed and counts as "resolver present".
     */
    private static function hasMissingNamedRouteResolver(Application $app): bool
    {
        $url = $app->make('url');

        if (!$url instanceof UrlGenerator) {
            return true;
        }

        try {
            /** @psalm-var callable|null $resolver */
            $resolver = (new \ReflectionProperty(UrlGenerator::class, 'missingNamedRouteResolver'))->getValue($url);
        } catch (\ReflectionException) {
            return true;
        }

        return $resolver !== null;
    }

    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['route', 'to_route'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $routeName = self::resolveRouteName($event->getCallArgs());

        if ($routeName !== null) {
            self::checkRouteExists(
                $routeName,
                $event->getCodeLocation(),
                $event->getStatementsSource()->getSuppressedIssues(),
            );
        }

        return null;
    }

    /** @inheritDoc */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return \array_values(\array_unique([
            UrlGenerator::class,
            // The url() helper returns this contract, not the concrete class, when
            // called with no path (see the ($path is null ? ...) conditional return
            // in helpers.phpstub) — url()->route('x') would otherwise miss the
            // handler entirely, matching a real @mixin-reroute trap this repo has
            // hit before (#1215).
            \Illuminate\Contracts\Routing\UrlGenerator::class,
            Redirector::class,
            \Illuminate\Support\Facades\URL::class,
            \Illuminate\Support\Facades\Redirect::class,
            ...FacadeMapProvider::getFacadeClasses(UrlGenerator::class),
            ...FacadeMapProvider::getFacadeClasses(Redirector::class),
        ]));
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if (!\in_array($event->getMethodNameLowercase(), ['route', 'signedroute', 'temporarysignedroute'], true)) {
            return null;
        }

        $routeName = self::resolveRouteName($event->getCallArgs());

        if ($routeName !== null) {
            self::checkRouteExists($routeName, $event->getCodeLocation(), $event->getSource()->getSuppressedIssues());
        }

        return null;
    }

    /**
     * Resolve the literal route name from a call's arguments, honouring named arguments.
     *
     * `route(absolute: false, name: 'typo')` puts the name at offset 1, so resolve by parameter
     * identifier first and fall back to the first argument only when it is genuinely positional.
     * Decline when the name cannot be located: a leading spread hides it, and a first argument
     * named for some OTHER parameter means the name is spread in or absent.
     *
     * @param list<Arg> $callArgs
     * @psalm-mutation-free
     */
    private static function resolveRouteName(array $callArgs): ?string
    {
        foreach ($callArgs as $arg) {
            if ($arg->name !== null && \in_array($arg->name->name, self::ROUTE_NAME_PARAMETERS, true)) {
                return self::literalString($arg);
            }
        }

        $firstArg = $callArgs[0] ?? null;

        if ($firstArg === null || $firstArg->name !== null || $firstArg->unpack) {
            return null;
        }

        return self::literalString($firstArg);
    }

    /** @psalm-mutation-free */
    private static function literalString(Arg $arg): ?string
    {
        return $arg->value instanceof String_ ? $arg->value->value : null;
    }

    /**
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkRouteExists(string $routeName, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if (!self::$enabled || isset(self::$names[$routeName])) {
            return;
        }

        IssueBuffer::accepts(
            new UnregisteredRouteName("Route name '{$routeName}' is not registered", $codeLocation),
            $suppressedIssues,
        );
    }
}
