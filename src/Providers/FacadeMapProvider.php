<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Facade;

/**
 * Maps Laravel service classes to their facade and root alias class names.
 *
 * Psalm's MethodReturnTypeProvider uses exact class name matching — a handler
 * registered for Factory::class won't fire when View::make() is called through
 * the facade. This map lets handlers discover which facade/alias classes proxy
 * to their service class so they can register for those names too.
 *
 * Built once during plugin init from the booted app's AliasLoader + Facade::getFacadeRoot().
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/591
 */
final class FacadeMapProvider
{
    /** @var array<lowercase-string, list<class-string>> service class → facade + alias classes */
    private static array $serviceToFacades = [];

    /**
     * Build the map from the booted Laravel app's alias registry.
     *
     * Must be called after ApplicationProvider::bootApp() — facades need
     * the container to resolve their underlying service class.
     */
    public static function init(\Psalm\Progress\Progress $progress): void
    {
        self::$serviceToFacades = [];

        /** @var array<string, class-string> $aliases */
        $aliases = AliasLoader::getInstance()->getAliases();

        foreach ($aliases as $alias => $facadeClass) {
            try {
                // is_subclass_of() invokes the autoloader for unknown classes.
                // For entries in AliasLoader's registry, that routes back through
                // AliasLoader::load(), which calls class_alias($target, $alias).
                //
                // Some published packages ship a misconfigured self-referential
                // entry: mateffy/laravel-introspect declares
                //   "aliases": { "Introspect": "Introspect" }
                // in composer.json (extra.laravel.aliases). Laravel's package
                // discovery registers this verbatim, so AliasLoader tries
                // class_alias('Introspect', 'Introspect'). No real class named
                // 'Introspect' exists in the global namespace (the actual Facade
                // is Mateffy\Introspect\Facades\Introspect), so PHP emits a
                // "Class not found" warning.
                //
                // Under Psalm, that warning is promoted to a RuntimeException by
                // Psalm\Internal\ErrorHandler, which without this guard propagates
                // out of __invoke() and disables the plugin for the whole run.
                // Catching it lets the iteration skip the broken entry and
                // continue mapping the remaining (valid) facades. See issue #745.
                if (!\is_subclass_of($facadeClass, Facade::class)) {
                    continue;
                }

                $root = $facadeClass::getFacadeRoot();
            } catch (\Throwable $e) {
                // Catches both:
                //  - errors raised while autoloading $facadeClass above (broken
                //    aliases, parse errors in the target file, missing parents)
                //  - BindingResolutionException or \Error from getFacadeRoot()
                //    when the facade's container binding is absent or depends on
                //    optional packages (e.g. Symfony components not installed).
                $progress->debug("Laravel plugin: FacadeMapProvider skipped {$facadeClass}: {$e->getMessage()}\n");
                continue;
            }

            // getFacadeRoot() returns mixed — container bindings can resolve to
            // scalars, null, or objects. Only objects have class names to map.
            if (!\is_object($root)) {
                continue;
            }

            $serviceClass = \get_class($root);
            /** @var lowercase-string $key */
            $key = \strtolower($serviceClass);

            /** @var class-string $facadeClass */
            self::$serviceToFacades[$key][] = $facadeClass;

            // Root aliases (e.g., 'View') are generated as stub classes that extend
            // the facade FQCN. Psalm treats them as separate classes, so handlers
            // must register for both.
            if (!\str_contains($alias, '\\')) {
                /** @var class-string $alias */
                self::$serviceToFacades[$key][] = $alias;
            }
        }
    }

    /**
     * Get all facade and root alias class names that proxy to the given service class.
     *
     * Example: getFacadeClasses(Factory::class) returns
     * ['Illuminate\Support\Facades\View', 'View']
     *
     * @param class-string $serviceClass
     * @return list<class-string>
     * @psalm-external-mutation-free
     */
    public static function getFacadeClasses(string $serviceClass): array
    {
        return self::$serviceToFacades[\strtolower($serviceClass)] ?? [];
    }
}
