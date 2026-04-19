<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Illuminate\Support\Facades\Facade;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Progress\Progress;
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
 * ships rich `@method` catalogues on those classes, and {@see \Psalm\LaravelPlugin\Providers\FacadeMapProvider::init()}
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

            // Anonymous classes: Psalm synthesises an FQCN from the file path and position.
            // That name is not autoloadable and the class has no user-authored docblock to
            // parse, so there is nothing for our resolver to do.
            if (
                $storage->stmt_location !== null
                && self::isSyntheticAnonymousClassName($storage->name, $storage->stmt_location->file_path)
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
                $progress->warning(
                    "Laravel plugin: skipping facade '{$storage->name}': {$error->getMessage()}",
                );
                continue;
            }

            // Probe once more here. For direct-parent facades seen in afterClassLikeVisit,
            // Laravel's `Facade::$resolvedInstance` cache makes this call effectively free
            // in the main process. When scanning forks, worker processes inherit the cache
            // via copy-on-write memory but their writes don't propagate back — so the main
            // process may re-probe; `$failedFacades` below prevents user-provider factories
            // from running a second time in that case.
            $rootClass = self::tryGetFacadeRootClass($storage->name, $progress);

            if ($rootClass === null) {
                $progress->debug(
                    "Laravel plugin: skipping facade '{$storage->name}': getFacadeRoot() returned no object\n",
                );
                continue;
            }

            self::registerHandlersForFacade($codebase, $storage->name, $rootClass);
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
     * - The accessor is a class-string (e.g. `protected static function getFacadeAccessor()
     *   { return MyService::class; }`) — the container auto-wires it via reflection.
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
        if (isset(self::$failedFacades[$facadeClass])) {
            return null;
        }

        // is_subclass_of() invokes the autoloader; guard per FacadeMapProvider::init().
        try {
            if (!\is_subclass_of($facadeClass, Facade::class)) {
                self::$failedFacades[$facadeClass] = true;
                return null;
            }

            /** @var mixed $root — getFacadeRoot() is untyped and container bindings can resolve to anything */
            $root = $facadeClass::getFacadeRoot();
        } catch (\Throwable $throwable) {
            self::$failedFacades[$facadeClass] = true;
            $progress?->debug(
                "Laravel plugin: getFacadeRoot() failed for '{$facadeClass}': {$throwable->getMessage()}\n",
            );
            return null;
        }

        return \is_object($root) ? \get_class($root) : null;
    }

    /** @param class-string $rootClass */
    private static function registerHandlersForFacade(
        Codebase $codebase,
        string $facadeClass,
        string $rootClass,
    ): void {
        $methods = $codebase->methods;

        $methods->existence_provider->registerClosure(
            $facadeClass,
            static fn(MethodExistenceProviderEvent $event): ?bool
                => FacadeMethodHandler::doesMethodExist($event, $rootClass),
        );
        $methods->params_provider->registerClosure(
            $facadeClass,
            static fn(MethodParamsProviderEvent $event): ?array
                => FacadeMethodHandler::getMethodParams($event, $rootClass),
        );
        $methods->return_type_provider->registerClosure(
            $facadeClass,
            static fn(MethodReturnTypeProviderEvent $event): ?Union
                => FacadeMethodHandler::getReturnType($event, $rootClass),
        );
    }

    /**
     * Facades we never register against. First-party `Illuminate\` facades already ship with
     * `@method` catalogues (and are covered by {@see \Psalm\LaravelPlugin\Providers\FacadeMapProvider::init()}
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
        return \str_starts_with($fqcn, 'Illuminate\\')
            || \str_starts_with($fqcn, 'Laravel\\')
            || \str_starts_with($fqcn, 'Mockery\\')
            || \str_starts_with($fqcn, 'PHPUnit\\');
    }

    /**
     * Detects the synthetic FQCN Psalm assigns to anonymous classes. Psalm builds
     * them as `{sanitized_file_path}_{line}_{startFilePos}` (prefixed by the
     * surrounding namespace), and they are never autoloadable.
     *
     * Duplicated from {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}
     * because both registration handlers use it independently and neither owns a
     * shared utility location; consolidation can come with a third caller.
     *
     * @see \Psalm\Internal\Analyzer\ClassAnalyzer::getAnonymousClassName()
     * @psalm-pure
     */
    private static function isSyntheticAnonymousClassName(string $fqcn, string $filePath): bool
    {
        if ($filePath === '') {
            return false;
        }

        $lastSeparator = \strrpos($fqcn, '\\');
        $shortName = $lastSeparator === false ? $fqcn : \substr($fqcn, $lastSeparator + 1);

        if (\preg_match('/_\d+_\d+$/', $shortName) !== 1) {
            return false;
        }

        $sanitizedPath = \preg_replace('/[^A-Za-z0-9]/', '_', $filePath) ?? '';

        return \str_starts_with($shortName, $sanitizedPath . '_');
    }
}
