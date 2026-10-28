<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Traits\Macroable;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Internal\AnonymousClassNameDetector;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Progress\Progress;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Union;

/**
 * Discovers app-owned Facade subclasses from Psalm's scanned codebase and registers
 * per-class method providers so calls like `App\Facades\Diagnostic::getReport()` resolve
 * when the facade's accessor is container-resolvable at runtime.
 *
 * Why a registration handler (like {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}):
 * Psalm's method-provider lookup is keyed on the exact FQCN being called. Registering
 * a single handler for `Facade::class` would not fire for subclasses. We enumerate every
 * concrete `Facade` subclass in the analysed project and bind {@see FacadeMethodHandler}
 * callbacks to each one.
 *
 * First-party `Illuminate\` facades are skipped — Laravel's framework source already
 * ships rich `@method` catalogues on those classes, and {@see \Psalm\LaravelPlugin\Stubs\FacadeMapProvider::init()}
 * covers the alias path. `Laravel\` sub-packages (Cashier, Horizon, Telescope, Pulse,
 * Octane, Pennant) ship their own facades whose bindings live in package service providers
 * that may not run in Testbench — autoloading their root class would chain into
 * `BindingResolutionException`. `Mockery\` / `PHPUnit\` are defensive defaults against
 * test doubles that happen to extend `Facade` transitively.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/787
 * @internal
 */
final class AppFacadeRegistrationHandler implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface
{
    private const FACADE_FQCN = 'Illuminate\\Support\\Facades\\Facade';

    private const FACADE_FQCN_LOWER = 'illuminate\\support\\facades\\facade';

    /**
     * Set of facade classes whose `getFacadeRoot()` probe has already thrown. Prevents
     * re-running a user service provider factory across the two probe sites
     * (scan phase + populate phase) — Laravel's own `Facade::$resolvedInstance` only
     * caches on success, so without this, a throwing accessor runs twice per facade.
     *
     * @var array<string, true>
     */
    private static array $failedFacades = [];

    public static function reset(): void
    {
        self::$failedFacades = [];
    }

    /**
     * Probe `Facade::getFacadeRoot()` at scan time and queue the resolved root class for
     * scanning. We can't do this in {@see self::afterCodebasePopulated()} because by then
     * the scanner has stopped — a root class not already pulled in via other references
     * would be missing its classlike storage when providers look it up.
     *
     * Direct-parent match only: during scan phase, only `$storage->parent_class` is set;
     * the full `parent_classes` chain is built later by the populator. Indirect subclasses
     * (`class X extends CustomBase extends Facade`) fall through to afterCodebasePopulated,
     * where their root class can still be probed but is scanned best-effort (via other
     * references in the project, or not at all).
     */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        if ($storage->parent_class === null) {
            return;
        }

        // strcasecmp avoids allocating a lowercased copy per class visited (~10k+ calls
        // on a mid-size project). $storage->parent_class is stored in declared case.
        if (\strcasecmp($storage->parent_class, self::FACADE_FQCN) !== 0) {
            return;
        }

        if (self::isSkippedFacade($storage->name)) {
            return;
        }

        $progress = $event->getCodebase()->progress;
        $rootClass = self::tryGetFacadeRootClass($storage->name, $progress);

        if ($rootClass === null) {
            return;
        }

        $event->getCodebase()->scanner->queueClassLikeForScanning($rootClass);
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $progress = $codebase->progress;

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            // Abstract classes include the base Facade itself and user-defined abstract
            // base facades — neither should receive method providers.
            if ($storage->abstract) {
                continue;
            }

            if (!isset($storage->parent_classes[self::FACADE_FQCN_LOWER])) {
                continue;
            }

            if (self::isSkippedFacade($storage->name)) {
                continue;
            }

            // AliasStubProvider writes `class_alias()` lines, so Psalm resolves a root alias such as
            // `Redis` to the facade itself and the alias never appears as its own storage entry.
            // A global-namespace class that extends a first-party Illuminate facade
            // (e.g. `class Redis extends \Illuminate\Support\Facades\Redis {}`) still shows up when
            // the project scans a laravel-ide-helper declaration, which keeps the alias name as a
            // real class. Such a class duplicates a facade that FacadeMapProvider::init() already
            // discovered through the alias loader and registered providers for. Re-probing it here
            // is wasted work AND produces user-visible warnings for environment collisions
            // FacadeMapProvider silently tolerates (only debug-level there):
            //   - `Redis` collides with the global \Redis class shipped by ext-redis, so
            //     is_subclass_of() resolves to the extension class and returns false.
            //   - `Concurrency` (and other manager-backed facades) need a service-provider
            //     binding that may not run in Testbench; getFacadeRoot() throws.
            // Skip when the facade is a global-namespace class whose parent is a first-party
            // Illuminate facade. Coverage for these aliases comes from FacadeMapProvider via
            // the alias-loader path; we lose nothing by not probing again here.
            if (self::isIlluminateAliasStub($storage->name, $storage->parent_class)) {
                continue;
            }

            // Anonymous classes: Psalm synthesises an FQCN from the file path and position.
            // That name is not autoloadable and the class has no user-authored docblock to
            // parse, so there is nothing for our resolver to do.
            if (
                $storage->stmt_location !== null
                && AnonymousClassNameDetector::isSynthetic($storage->name, $storage->stmt_location->file_path)
            ) {
                continue;
            }

            try {
                if (!\class_exists($storage->name, true)) {
                    // warning (not debug) — debug is a no-op in the default progress, and a user
                    // facade silently losing method resolution is exactly the class of failure
                    // issue #787 was filed to fix. Match ModelRegistrationHandler's convention.
                    $progress->warning(
                        "Laravel plugin: skipping facade '{$storage->name}': class could not be loaded by autoloader",
                    );
                    continue;
                }
            } catch (\Error|\Exception $error) {
                $progress->warning("Laravel plugin: skipping facade '{$storage->name}': {$error->getMessage()}");
                continue;
            }

            // Probe once more here. For direct-parent facades seen in afterClassLikeVisit,
            // Laravel's `Facade::$resolvedInstance` cache makes this call effectively free
            // in the main process. When scanning forks, worker processes inherit the cache
            // via copy-on-write memory but their writes don't propagate back, so the main
            // process may re-probe; `$failedFacades` below prevents user-provider factories
            // from running a second time in that case. `tryGetFacadeRootClass()` emits its
            // own warning on first failure, so we simply `continue` here.
            $rootClass = self::tryGetFacadeRootClass($storage->name, $progress);

            if ($rootClass === null) {
                continue;
            }

            self::registerHandlersForFacade($codebase, $storage->name, $rootClass);
            self::unsealIfRootForwardsUnknownCalls($codebase, $storage, $rootClass);
        }
    }

    /**
     * Resolve the facade's container-bound root object and return its class.
     *
     * Called by both {@see self::afterClassLikeVisit()} (to queue the result for scanning)
     * and {@see self::afterCodebasePopulated()} (to register method providers). Laravel's
     * `Facade` caches the resolved instance in `static::$resolvedInstance` within a single
     * process, so the second call in the main process is free; in forked-scanner topologies
     * the worker's cache is discarded on exit, and {@see self::$failedFacades} prevents a
     * throwing user-provider factory from re-running in the main process.
     *
     * Works when:
     * - The accessor is a class-string (e.g. a `getFacadeAccessor()` that returns
     *   `MyService::class`) — the container auto-wires it via reflection.
     * - The accessor is a string alias bound in our Testbench container (first-party
     *   services like `'cache'`, `'router'`, package bindings registered via discovered
     *   providers).
     *
     * Returns null when the accessor is a string alias bound only by a user service
     * provider that does not run in Testbench — nothing we can do at this layer.
     *
     * @return ?class-string
     */
    public static function tryGetFacadeRootClass(string $facadeClass, ?Progress $progress = null): ?string
    {
        // $failedFacades gates both the short-circuit return AND the warning emission,
        // so each failure reason is surfaced to the user exactly once per facade across
        // scan-phase + populate-phase invocations (issue #787: silent losses of method
        // resolution must be visible, not swallowed by the default progress sink).
        if (isset(self::$failedFacades[$facadeClass])) {
            return null;
        }

        // is_subclass_of() invokes the autoloader; guard per FacadeMapProvider::init().
        try {
            if (!\is_subclass_of($facadeClass, Facade::class)) {
                self::$failedFacades[$facadeClass] = true;
                $progress?->warning(
                    "Laravel plugin: skipping facade '{$facadeClass}': not a subclass of " . Facade::class,
                );
                return null;
            }

            // getFacadeRoot() is untyped (`@return mixed`) and container bindings can
            // resolve to anything; route through the typed helper so the caller does
            // not observe a mixed value directly.
            $rootClass = self::classOfFacadeRoot($facadeClass::getFacadeRoot());

            if ($rootClass === null) {
                self::$failedFacades[$facadeClass] = true;
                $progress?->warning(
                    "Laravel plugin: skipping facade '{$facadeClass}': getFacadeRoot() returned a non-object value",
                );
                return null;
            }

            return $rootClass;
        } catch (\Throwable $throwable) {
            self::$failedFacades[$facadeClass] = true;
            $progress?->warning(
                "Laravel plugin: getFacadeRoot() failed for '{$facadeClass}': {$throwable->getMessage()}",
            );
            return null;
        }
    }

    /**
     * Narrow the untyped `Facade::getFacadeRoot()` result to a class-string of its
     * runtime object, or null for any non-object value. The `mixed` parameter is a
     * deliberate boundary: it contains the untyped value inside this helper so the
     * caller's local types stay precise (keeps project-wide Psalm type coverage at 100%).
     *
     * @return ?class-string
     * @psalm-pure
     */
    private static function classOfFacadeRoot(mixed $root): ?string
    {
        return \is_object($root) ? \get_class($root) : null;
    }

    /** @param class-string $rootClass */
    private static function registerHandlersForFacade(Codebase $codebase, string $facadeClass, string $rootClass): void
    {
        $methods = $codebase->methods;

        $methods->existence_provider->registerClosure($facadeClass, static fn(MethodExistenceProviderEvent $event): ?bool => FacadeMethodHandler::doesMethodExist(
            $event,
            $rootClass,
        ));
        $methods->params_provider->registerClosure($facadeClass, static fn(MethodParamsProviderEvent $event): ?array => FacadeMethodHandler::getMethodParams(
            $event,
            $rootClass,
        ));
        $methods->return_type_provider->registerClosure($facadeClass, static fn(MethodReturnTypeProviderEvent $event): ?Union => FacadeMethodHandler::getReturnType(
            $event,
            $rootClass,
        ));
    }

    /**
     * Psalm seals every user-defined class's methods when `sealAllMethods` is on, so an unknown
     * static call on a facade reports UndefinedMagicMethod even when the resolved root forwards it
     * through its own `__call` (#2138). Lift the seal for such facades only.
     *
     * Only a facade with no seal of its own (`sealed_methods === null`) is touched: a class-level
     * `@method` tag on the facade is an explicit catalogue and keeps Psalm's sealing. Unsealed means
     * any call is accepted, with the unknown result typed `mixed`; methods the root really declares
     * still resolve exactly through {@see FacadeMethodHandler}.
     *
     * Forwarding needs two runtime facts, both read from what PHP dispatches because Psalm's storage
     * can disagree with it:
     * - The root's effective `__call` is not Macroable's, which throws `BadMethodCallException`
     *   for anything but a registered macro. Storage cannot say which: `ClassLikeNodeScanner` ignores
     *   `insteadof` precedence (vimeo/psalm#12113) and keeps the first-listed trait, and a class-level
     *   stub can redeclare `__call` on a root that uses Macroable at runtime. The reflected file and
     *   start line decide, because `ReflectionMethod::getDeclaringClass()` names the using class
     *   for trait methods.
     * - The facade's effective `__callStatic` is Laravel's `Facade::__callStatic`, the only
     *   dispatcher that reaches the root's `__call`. `use Macroable` on the facade or an own
     *   `__callStatic` replaces it and throws first, even if it would forward.
     * A root without Psalm class storage or without a declared `__call` is never proven to
     * forward, and a root that never resolved does not reach here, so their facades stay sealed.
     * This is a plugin-side guard, not a fix of the Psalm provenance defect.
     *
     * Runs after population, so facade subclasses that already inherited the seal keep it.
     * Class aliases share this storage object, so they are covered by the same mutation.
     *
     * @param class-string $rootClass
     */
    private static function unsealIfRootForwardsUnknownCalls(
        Codebase $codebase,
        ClassLikeStorage $facadeStorage,
        string $rootClass,
    ): void {
        if ($facadeStorage->sealed_methods !== null) {
            return;
        }

        $provider = $codebase->classlike_storage_provider;

        if (!$provider->has($rootClass)) {
            return;
        }

        $callMethodId = $provider->get($rootClass)->declaring_method_ids['__call'] ?? null;

        if (
            $callMethodId === null
            || !self::facadeUsesLaravelDispatcher($facadeStorage->name)
            || self::rootCallIsMacroable($rootClass)
        ) {
            return;
        }

        $facadeStorage->sealed_methods = false;
    }

    /**
     * Whether PHP runs `Facade::__callStatic` for calls on this facade, which is the only dispatcher
     * that forwards to the root. A trait or an own override reports the facade (or an intermediate
     * class) as declaring class, so only the plain inherited method matches. An unreflectable
     * method counts as not matching: forwarding is then unproven and the facade stays sealed.
     *
     */
    private static function facadeUsesLaravelDispatcher(string $facadeClass): bool
    {
        if (!\class_exists($facadeClass)) {
            return false;
        }

        try {
            return \strcasecmp((new \ReflectionMethod($facadeClass, '__callStatic'))->getDeclaringClass()->name, self::FACADE_FQCN) === 0;
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Whether PHP runs `Macroable::__call` for calls on this root. For trait methods
     * `getDeclaringClass()` names the using class, so identity is the reflected file and start line.
     * An unreflectable method counts as Macroable's: forwarding is then unproven and the facade
     * stays sealed.
     *
     * @param class-string $rootClass
     */
    private static function rootCallIsMacroable(string $rootClass): bool
    {
        try {
            $call = new \ReflectionMethod($rootClass, '__call');
            $macroableCall = new \ReflectionMethod(Macroable::class, '__call');
        } catch (\ReflectionException) {
            return true;
        }

        return $call->getFileName() === $macroableCall->getFileName()
            && $call->getStartLine() === $macroableCall->getStartLine();
    }

    /**
     * Facades we never register against. First-party `Illuminate\` facades already ship with
     * `@method` catalogues (and are covered by {@see \Psalm\LaravelPlugin\Stubs\FacadeMapProvider::init()}
     * for the alias path). `Laravel\` sub-packages (Cashier, Horizon, Telescope, Pulse,
     * Octane, Pennant) register their accessors via package service providers that may not
     * run in our Testbench boot — probing them would autoload classes that immediately throw
     * `BindingResolutionException`. `Mockery\` / `PHPUnit\` guard against test doubles that
     * happen to extend `Facade` transitively.
     *
     * @psalm-pure
     */
    private static function isSkippedFacade(string $fqcn): bool
    {
        return (
            \str_starts_with($fqcn, 'Illuminate\\')
            || \str_starts_with($fqcn, 'Laravel\\')
            || \str_starts_with($fqcn, 'Mockery\\')
            || \str_starts_with($fqcn, 'PHPUnit\\')
        );
    }

    /**
     * Detect a global-namespace class that extends a first-party Illuminate facade, i.e. the
     * `class Foo extends \Illuminate\Support\Facades\Foo {}` shape.
     *
     * {@see \Psalm\LaravelPlugin\Stubs\AliasStubProvider} writes `class_alias()` lines, which
     * never produce this shape. It does occur when the project scans a laravel-ide-helper
     * declaration (`_ide_helper.php`): Psalm then keeps the alias name as a real class. Such a
     * class is a duplicate of a facade already mapped by
     * {@see \Psalm\LaravelPlugin\Stubs\FacadeMapProvider::init()} through the same alias loader.
     * Re-probing it in {@see self::afterCodebasePopulated()} adds no coverage and surfaces
     * environment-dependent failures (extension class collision, missing service-provider
     * bindings) that FacadeMapProvider tolerates silently.
     *
     * @psalm-pure
     */
    private static function isIlluminateAliasStub(string $fqcn, ?string $parentClass): bool
    {
        return (
            $parentClass !== null
            && !\str_contains($fqcn, '\\')
            && \str_starts_with($parentClass, 'Illuminate\\Support\\Facades\\')
        );
    }
}
