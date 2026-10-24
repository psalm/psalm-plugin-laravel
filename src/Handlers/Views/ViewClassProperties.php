<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\PropertyStorage;

/**
 * The storage lookups every framework-supplied view-data source shares: {@see ComponentRenderData},
 * {@see FrameworkViewData}.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class ViewClassProperties
{
    /**
     * Every public property of the class, statics included, with the class that declares it.
     * Statics stay in because `ReflectionClass::getProperties()` returns them: each consumer drops
     * them or not according to the framework code it models.
     *
     * @param \Closure[_](string): bool $ignoreName evaluated before the declaring class is resolved
     *
     * @return array<string, array{0: ClassLikeStorage, 1: PropertyStorage}>|null null when a
     *         declaring class could not be resolved, which makes the set unknowable rather than empty
     *
     * @psalm-capabilities read-props
     */
    public static function publicOf(Codebase $codebase, ClassLikeStorage $storage, \Closure $ignoreName): ?array
    {
        $properties = [];

        foreach ($storage->declaring_property_ids as $name => $declaringClass) {
            if ($ignoreName($name)) {
                continue;
            }

            $declaring = self::storage($codebase, $declaringClass);

            if (!$declaring instanceof ClassLikeStorage) {
                return null;
            }

            $property = $declaring->properties[$name] ?? null;

            if ($property === null || $property->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                continue;
            }

            $properties[$name] = [$declaring, $property];
        }

        return $properties;
    }

    /**
     * @psalm-mutation-free
     */
    public static function storage(Codebase $codebase, string $fqClassName): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get(\strtolower($fqClassName));
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }
}
