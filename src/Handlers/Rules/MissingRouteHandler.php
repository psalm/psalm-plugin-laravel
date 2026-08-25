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
 * Redirect::route(), and redirect()->route() whose route name is not registered anywhere
 * in the booted application, and flags it as {@see MissingRoute}.
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
 * so it is skipped too — a deliberate false-negative, not a bug.
 *
 * The named-route table is populated once per invocation from the booted app's router
 * (see `Plugin::initMissingRouteHandler()`). When that table comes back empty — a
 * package/library project analysed through the Testbench fallback never loads user route
 * files — the handler stays disabled entirely rather than reporting every route name as
 * missing.
 *
 * Known limitations (by design, not pre-waived accidents): `Route::has()` guards around a
 * call site are not tracked, so a name that is only conditionally missing still reports;
 * conditionally-registered routes (feature flags, env-gated route files) can produce a
 * false positive if the analysing environment doesn't register them; Blade templates are
 * out of scope; a stale compiled route cache is not detected as such.
 *
 * @see https://laravel.com/docs/routing#named-routes
 */
final class MissingRouteHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
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
        $callArgs = $event->getCallArgs();

        if ($callArgs === [] || $callArgs[0]->unpack) {
            return null;
        }

        $routeName = self::extractLiteralStringArg($callArgs[0]);

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

        $callArgs = $event->getCallArgs();

        if ($callArgs === [] || $callArgs[0]->unpack) {
            return null;
        }

        $routeName = self::extractLiteralStringArg($callArgs[0]);

        if ($routeName !== null) {
            self::checkRouteExists($routeName, $event->getCodeLocation(), $event->getSource()->getSuppressedIssues());
        }

        return null;
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

        if ($routeName === '' || isset(self::$names[$routeName])) {
            return;
        }

        IssueBuffer::accepts(
            new MissingRoute("Route '{$routeName}' is not defined", $codeLocation),
            $suppressedIssues,
        );
    }
}
