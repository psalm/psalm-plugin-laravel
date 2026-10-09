<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Gives every Blade shadow the global functions and constants the booted Laravel app declared
 * (#1551).
 *
 * Psalm keeps no global function table for ordinary project code: a bare `foo()` resolves through
 * the ROOT file's `FileStorage::$declaring_function_ids`, which a file gets entries in only by
 * `require`ing the declaring file, transitively. A shadow is a standalone compiled template — it
 * requires nothing and usually names no class — so it reaches nothing, and every helper a provider
 * `include`d at boot reported `UndefinedFunction … consider enabling allFunctionsGlobal`, with
 * `define()`d constants in the same files reporting `UndefinedConstant` through the identical
 * mechanism.
 *
 * A template is rendered by a fully booted application, so "visible = what the boot declared" is
 * the faithful model, and it is far narrower than turning `allFunctionsGlobal` on. The injection is
 * confined to paths {@see ShadowRegistry} knows: widening it to project files would suppress
 * genuine `UndefinedFunction` everywhere.
 *
 * What gets injected is the INTERSECTION of the boot's declared names with the declaring file's
 * storage, never the whole file: a declaration behind a disabled feature flag or version gate, or
 * nested inside an uncalled function, sits in that storage without the runtime ever declaring it,
 * and merging it would make an unreachable symbol resolvable in every template.
 *
 * Runs at `AfterCodebasePopulated`, which is after scanning (so the storages exist) and before
 * `analyzeFiles()` forks (so workers inherit the mutation by copy-on-write). Deliberately not
 * earlier: `FileStorageCacheProvider::writeToCache()` runs during scanning, so a merge before that
 * point would persist into the on-disk file-storage cache and leak into runs with Blade disabled.
 *
 * @internal
 */
final class RuntimeHelperVisibility implements AfterCodebasePopulatedInterface
{
    /** @var list<string> */
    private static array $helperFiles = [];

    /** @var array<string, true> */
    private static array $declaredFunctionIds = [];

    /** @var array<string, true> */
    private static array $declaredConstants = [];

    /**
     * @param list<string> $helperFiles from {@see \Psalm\LaravelPlugin\Bootstrap\ApplicationProvider::runtimeDeclaredFunctionFiles()}
     * @param list<string> $declaredFunctionIds from {@see \Psalm\LaravelPlugin\Bootstrap\ApplicationProvider::runtimeDeclaredFunctionIds()}
     * @param list<string> $declaredConstants from {@see \Psalm\LaravelPlugin\Bootstrap\ApplicationProvider::runtimeDeclaredConstants()}
     */
    public static function init(array $helperFiles, array $declaredFunctionIds, array $declaredConstants): void
    {
        self::$helperFiles = $helperFiles;
        self::$declaredFunctionIds = \array_fill_keys($declaredFunctionIds, true);
        self::$declaredConstants = \array_fill_keys($declaredConstants, true);
    }

    public static function reset(): void
    {
        self::$helperFiles = [];
        self::$declaredFunctionIds = [];
        self::$declaredConstants = [];
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        if (self::$helperFiles === []) {
            return;
        }

        $shadowPaths = ShadowRegistry::shadowPaths();

        if ($shadowPaths === []) {
            return;
        }

        $codebase = $event->getCodebase();
        $functionIds = [];
        $constants = [];

        foreach (self::$helperFiles as $helperFile) {
            try {
                $helperStorage = $codebase->file_storage_provider->get($helperFile);
            } catch (\InvalidArgumentException) {
                // Never scanned — an `<ignoreFiles>` path, or a file outside every scanned root.
                continue;
            }

            // Three gates. The declared-name set keeps symbols the file merely CONTAINS out (see the
            // class docblock). `functions` is what makes the map followable: `Functions::getStorage()`
            // reads `file_storage_provider->get($declaringPath)->functions[$id]` and FATALS when it
            // is absent; a populated `declaring_function_ids` also carries entries merged in from
            // required files, whose storage this one does not hold; those are dropped here and
            // picked up from their own file, since the capture names every declaring file directly.
            // A stubbed id is skipped outright so a user helper can never outrank a stub's types.
            foreach ($helperStorage->declaring_function_ids as $id => $declaringPath) {
                if (!isset(self::$declaredFunctionIds[$id], $helperStorage->functions[$id])) {
                    continue;
                }

                if (!$codebase->functions->hasStubbedFunction($id)) {
                    $functionIds[$id] = $declaringPath;
                }
            }

            foreach ($helperStorage->declaring_constants as $name => $declaringPath) {
                if (isset(self::$declaredConstants[$name], $helperStorage->constants[$name])) {
                    $constants[$name] = $declaringPath;
                }
            }
        }

        if ($functionIds === [] && $constants === []) {
            return;
        }

        foreach ($shadowPaths as $shadowPath) {
            try {
                $shadowStorage = $codebase->file_storage_provider->get($shadowPath);
            } catch (\InvalidArgumentException) {
                continue;
            }

            // `+=`, never a replace: the shadow's own declarations win. Psalm's `Functions::getStorage()`
            // FATALS (UnexpectedValueException) rather than declining when a declaring path does not
            // carry the id, so overwriting a correct entry would turn a false positive into a crash.
            $shadowStorage->declaring_function_ids += $functionIds;
            $shadowStorage->declaring_constants += $constants;
        }
    }
}
