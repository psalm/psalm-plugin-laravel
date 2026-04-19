<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Illuminate\Support\Facades\Facade;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\FacadeMapProvider;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Discovers app-owned Facade subclasses from Psalm's scanned codebase and registers
 * per-class method providers so calls like `App\Facades\License::getStatus()` resolve
 * when the method exists on a `@see`-referenced concrete class.
 *
 * Why a registration handler (like {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}):
 * Psalm's method-provider lookup is keyed on the exact FQCN being called. Registering
 * a single handler for `Facade::class` would not fire for subclasses. We enumerate every
 * concrete `Facade` subclass in the analysed project and bind {@see FacadeMethodHandler}
 * callbacks to each one.
 *
 * First-party `Illuminate\` facades are skipped — Laravel's framework source already
 * ships rich `@method` catalogues on those classes, and {@see FacadeMapProvider::init()}
 * covers the alias path. Vendor-package facades (`Mockery\`, `PHPUnit\`) are skipped defensively.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/787
 * @internal
 */
final class AppFacadeRegistrationHandler implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface
{
    /**
     * Lowercase FQCN of `Illuminate\Support\Facades\Facade`. Pre-computed to avoid running
     * `strtolower()` on a compile-time-known string per class visited during scan.
     */
    private const FACADE_FQCN_LOWER = 'illuminate\\support\\facades\\facade';

    /**
     * Queue `@see`-referenced classes for scanning while the scan phase is still in progress.
     *
     * Psalm's scanner reaches referenced classes through type annotations, parent/interface
     * declarations, and expressions — but NOT through `@see` tags. Without this hook the
     * referenced service class is typically invisible to Psalm by the time
     * {@see self::afterCodebasePopulated()} runs, so the method resolver would fail.
     *
     * We check direct `extends` only (via `$stmt->extends`) because full `parent_classes`
     * chains aren't computed until the populate phase. Indirect subclasses (user-defined
     * base facade) are handled later in afterCodebasePopulated's registration pass — if the
     * `@see` target is unscanned for those, the resolver falls through to path 4 or null.
     */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        // During scan phase, only $storage->parent_class (direct parent) is set; the full
        // parent_classes chain is built later by the populator. Match against the direct
        // parent for the common case (class X extends Facade); indirect cases
        // (class X extends CustomBase extends Facade) are handled gracefully by the
        // resolver falling through to null — an acceptable limitation for v1.
        if ($storage->parent_class === null) {
            return;
        }

        if (\strtolower($storage->parent_class) !== self::FACADE_FQCN_LOWER) {
            return;
        }

        // Mirror the skip-list in afterCodebasePopulated() — first-party Illuminate facades
        // do declare `@see` targets (e.g. Cache → CacheManager + Repository), so without this
        // check we'd reflect + queue their underlying classes during scan even though
        // afterCodebasePopulated() later drops them from registration.
        if (self::isSkippedFacade($storage->name)) {
            return;
        }

        $rootClasses = FacadeMethodHandler::resolveSeeTargetsFromStorage($storage->name, $storage);

        if ($rootClasses === []) {
            return;
        }

        $scanner = $event->getCodebase()->scanner;

        foreach ($rootClasses as $rootClass) {
            $scanner->queueClassLikeForScanning($rootClass);
        }
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if ($storage->abstract) {
                continue;
            }

            // Skip the base Facade class itself.
            if (\strtolower($storage->name) === self::FACADE_FQCN_LOWER) {
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
                    $codebase->progress->warning(
                        "Laravel plugin: skipping facade '{$storage->name}': class could not be loaded by autoloader",
                    );
                    continue;
                }
            } catch (\Error|\Exception $error) {
                $codebase->progress->warning(
                    "Laravel plugin: skipping facade '{$storage->name}': {$error->getMessage()}",
                );
                continue;
            }

            self::registerHandlersForFacade($codebase, $storage->name);
        }
    }

    private static function registerHandlersForFacade(Codebase $codebase, string $facadeClass): void
    {
        $methods = $codebase->methods;

        $methods->existence_provider->registerClosure(
            $facadeClass,
            FacadeMethodHandler::doesMethodExist(...),
        );
        $methods->params_provider->registerClosure(
            $facadeClass,
            FacadeMethodHandler::getMethodParams(...),
        );
        $methods->return_type_provider->registerClosure(
            $facadeClass,
            FacadeMethodHandler::getReturnType(...),
        );

        // If `@see` resolved statically, extend the service-to-facade map so other
        // handlers keyed on the service class (e.g. MissingViewHandler) also see
        // this facade. Runtime-resolved roots (path 4) are intentionally NOT
        // eagerly registered here — `getFacadeRoot()` is only invoked lazily during
        // method analysis, keeping per-registration cost bounded.
        //
        // Note: downstream handlers that consume `FacadeMapProvider::getFacadeClasses()`
        // at hook-registration time (`getClassLikeNames()`) will NOT see these late-added
        // entries, because their class-name lists are frozen before AfterCodebasePopulated
        // fires. Only analysis-time consumers benefit.
        //
        // Scanning of the `@see`-referenced classes is queued earlier during
        // {@see self::afterClassLikeVisit()}; post-populate is too late for the scanner
        // to pick it up.
        /** @var class-string $facadeClass The caller already guards with class_exists() */
        foreach (FacadeMethodHandler::resolveSeeTargets($codebase, $facadeClass) as $rootClass) {
            FacadeMapProvider::registerCustomFacade($rootClass, $facadeClass);
        }
    }

    /**
     * Facades we never register against. First-party `Illuminate\` facades already ship with
     * `@method` catalogues (and are covered by {@see FacadeMapProvider::init()} for the alias
     * path); `Mockery\` / `PHPUnit\` are defensive defaults against test doubles that happen
     * to extend `Facade` transitively.
     *
     * @psalm-pure
     */
    private static function isSkippedFacade(string $fqcn): bool
    {
        return \str_starts_with($fqcn, 'Illuminate\\')
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
