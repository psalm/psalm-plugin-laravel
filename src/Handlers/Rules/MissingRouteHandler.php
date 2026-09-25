<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\MissingRoute;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Detects calls to route(), to_route(), URL::route()/signedRoute()/temporarySignedRoute(),
 * Redirect::route(), redirect()->route(), and url()->route() whose route name is not
 * registered anywhere in the booted application, and flags it as {@see MissingRoute}.
 *
 * Diagnostic only — every provider method below always returns null; stub/native return
 * types are left untouched.
 *
 * Registers for the service classes (UrlGenerator, Redirector) and their canonical
 * facades/aliases via {@see FacadeMapProvider}, so the diagnostic fires regardless of
 * how the developer reaches the route() family. The canonical facades are hardcoded
 * (not left to FacadeMapProvider) so the diagnostic still fires on
 * `\Illuminate\Support\Facades\URL::route()` / `...\Redirect::route()` in apps that trim
 * their alias registry — matches {@see \Psalm\LaravelPlugin\Handlers\Views\MissingViewHandler}'s
 * convention.
 *
 * Only string literal route names are checked. A leading spread (`route(...$args)`) hides
 * the name entirely and is skipped, same as an already-non-literal first argument. A
 * `\BackedEnum` route name (Laravel 11+) is a `ClassConstFetch` node, never a `String_`,
 * so it is skipped too — a deliberate false-negative, not a bug. Named arguments are
 * resolved by parameter identifier, so `route(absolute: false, name: 'typo')` is checked at
 * the offset it actually occupies (see {@see self::resolveRouteName()}).
 *
 * An empty name (`route('')`) is skipped by design: it can never match a registered route,
 * so a finding here would restate a mistake that is already obvious at the call site, and an
 * empty literal reads as unfinished scaffolding rather than a typo'd name.
 *
 * The named-route table is populated once per invocation from the booted app's router
 * (see `Plugin::initMissingRouteHandler()`). A compiled route cache
 * (`bootstrap/cache/routes-v7.php`) is read the same way a live route-file boot is; the
 * plugin does not treat a cached boot any differently. When the table comes back empty,
 * the handler stays disabled entirely rather than reporting every route name as missing.
 * Two situations produce that empty table: a package/library project analysed through the
 * Testbench fallback (never loads user route files, no warning) and a route cache that
 * itself carries no named routes (warns, naming `route:cache` and `route:clear`).
 *
 * Known limitations (by design, not pre-waived accidents): `Route::has()` guards around a
 * call site are not tracked, so a name that is only conditionally missing still reports;
 * conditionally-registered routes (feature flags, env-gated route files) can produce a
 * false positive if the analysing environment doesn't register them; a stale route cache
 * (one written before a route was added, renamed, or before its name was added) can also
 * produce a false positive, reporting a route that does exist because the cache predates
 * it, and `route:cache` or `route:clear` resolves it; Blade templates are out of scope.
 *
 * @see https://laravel.com/docs/routing#named-routes
 */
final class MissingRouteHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    /**
     * Parameter identifiers the route name can arrive under, for named-argument call sites.
     *
     * Laravel's own signatures disagree across the family: the `route()` helper and
     * UrlGenerator's route()/signedRoute()/temporarySignedRoute() (plus the
     * Contracts\Routing\UrlGenerator interface) name it `$name`, while `to_route()` and
     * Redirector's route family name it `$route`. No signature in the family declares both,
     * so accepting either identifier cannot retarget a valid call: on a receiver from the
     * other family that identifier is already an unknown-named-argument error at the call
     * site, which Psalm reports on its own.
     *
     * @var list<string>
     */
    private const ROUTE_NAME_PARAMETERS = ['name', 'route'];

    /** @var array<string, true> Registered route names, from the booted app's router */
    private static array $names = [];

    private static bool $enabled = false;

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$names = [];
        self::$enabled = false;
    }

    /**
     * @param array<string, true> $names
     * @psalm-external-mutation-free
     */
    public static function init(array $names): void
    {
        self::$names = $names;
        self::$enabled = true;
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

    /**
     * @inheritDoc
     * @psalm-external-mutation-free
     */
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
     * `route(absolute: false, name: 'typo')` puts the name at offset 1, so reading offset 0
     * positionally would check the wrong node. Resolve by parameter identifier first
     * ({@see self::ROUTE_NAME_PARAMETERS}); only fall back to the first argument when it is
     * genuinely positional. Decline when the name cannot be located confidently rather than
     * guess: a leading spread hides it, and a first argument named for some OTHER parameter
     * means the name is either spread in or absent.
     *
     * @param list<Arg> $callArgs
     * @psalm-mutation-free
     */
    private static function resolveRouteName(array $callArgs): ?string
    {
        foreach ($callArgs as $arg) {
            if ($arg->name !== null && \in_array($arg->name->name, self::ROUTE_NAME_PARAMETERS, true)) {
                return self::extractLiteralStringArg($arg);
            }
        }

        if ($callArgs === []) {
            return null;
        }

        $firstArg = $callArgs[0];

        if ($firstArg->name !== null || $firstArg->unpack) {
            return null;
        }

        return self::extractLiteralStringArg($firstArg);
    }

    /**
     * Extract a literal string value from a call argument's AST node.
     *
     * Returns null for non-literal arguments (including a `\BackedEnum` case, which is a
     * `ClassConstFetch`) — the handler only validates route names it can statically
     * determine from the source code.
     *
     * @psalm-mutation-free
     */
    private static function extractLiteralStringArg(Arg $arg): ?string
    {
        $value = $arg->value;

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * @param array<array-key, string> $suppressedIssues
     */
    private static function checkRouteExists(string $routeName, CodeLocation $codeLocation, array $suppressedIssues): void
    {
        if (!self::$enabled) {
            return;
        }

        // The empty name is an intentional limitation, not an oversight: see the class docblock.
        if ($routeName === '' || isset(self::$names[$routeName])) {
            return;
        }

        IssueBuffer::accepts(
            new MissingRoute("Route '{$routeName}' is not defined", $codeLocation),
            $suppressedIssues,
        );
    }
}
