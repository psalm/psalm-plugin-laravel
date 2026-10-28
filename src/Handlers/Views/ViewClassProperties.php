<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Views;

use Illuminate\Contracts\Support\Renderable;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * The storage lookups every framework-supplied view-data source shares: {@see ComponentRenderData},
 * {@see FrameworkViewData}.
 *
 * @internal
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
     * The property's declared type with `self` and `parent` resolved against the class that declares
     * it, which for a trait's property is the class the trait appears in, and `static` against the
     * rendered class. Null for a property with no declared type, or when that appearing class cannot
     * be resolved: the type is then unknowable, never a guess.
     *
     * @param bool $final whether `static` collapses to the rendered class (`true`) or keeps the
     *                    late-static marker of a non-final one
     */
    public static function typeOf(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $name,
        ClassLikeStorage $declaring,
        PropertyStorage $property,
        bool $final,
    ): ?Union {
        if (!$property->type instanceof Union) {
            return null;
        }

        $owner = $declaring;

        if ($declaring->is_trait) {
            $appearing = \strstr($storage->appearing_property_ids[$name] ?? '', '::', true);
            $owner = $appearing === false ? null : self::storage($codebase, $appearing);

            if (!$owner instanceof ClassLikeStorage) {
                return null;
            }
        }

        return TypeExpander::expandUnion(
            $codebase,
            $property->type,
            $owner->name,
            $storage->name,
            $owner->parent_class,
            final: $final,
        );
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

    /**
     * What a template receives for a top-level data value: `View::gatherData()` swaps every
     * `Renderable` for the string it renders to. A class Psalm never scanned is not proven
     * `Renderable` and stays as is. Values nested in an array keep their objects, as in Laravel.
     *
     * @psalm-mutation-free
     */
    public static function renderablesAsString(Union $supplied, Codebase $codebase): Union
    {
        $atomics = [];

        foreach ($supplied->getAtomicTypes() as $atomic) {
            $atomics[] = $atomic instanceof TNamedObject
                && ClassLineage::isA($codebase, $atomic->value, Renderable::class)
                    ? new TString()
                    : $atomic;
        }

        return new Union($atomics);
    }
}
