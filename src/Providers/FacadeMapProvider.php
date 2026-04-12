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
            if (!\is_subclass_of($facadeClass, Facade::class)) {
                continue;
            }

            try {
                $root = $facadeClass::getFacadeRoot();
            } catch (\Throwable $e) {
                // BindingResolutionException is normal for unbound facades.
                // \Error can occur for missing classes (e.g. optional Symfony packages).
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
