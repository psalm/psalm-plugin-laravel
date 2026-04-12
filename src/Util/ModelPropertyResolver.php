<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psalm\Codebase;
use Psalm\NodeTypeProvider;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Shared utilities for resolving model property types from @property annotations.
 *
 * Used by BuilderPluckHandler and CollectionPluckHandler to avoid duplicating the
 * property lookup and argument extraction logic.
 *
 * @internal
 */
final class ModelPropertyResolver
{
    /**
     * Extract a Model class-string from a Union type, if it contains one.
     *
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    public static function extractModelFromUnion(?Union $type): ?string
    {
        if (!$type instanceof \Psalm\Type\Union) {
            return null;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && \is_a($atomic->value, Model::class, true)) {
                return $atomic->value;
            }
        }

        return null;
    }

    /**
     * Look up the type of a model property from @property / @property-read PHPDoc annotations.
     *
     * @param class-string<Model> $modelClass
     * @psalm-mutation-free
     */
    public static function resolvePropertyType(
        Codebase $codebase,
        string $modelClass,
        string $propertyName,
    ): ?Union {
        try {
            $classStorage = $codebase->classlike_storage_provider->get($modelClass);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $classStorage->pseudo_property_get_types['$' . $propertyName] ?? null;
    }

    /**
     * Build a typed Collection return type for pluck().
     *
     * Resolves the model property type from @property annotations and determines
     * the key type. Returns null if any step fails, causing the handler to fall
     * back to Psalm's default type inference.
     *
     * @param list<\PhpParser\Node\Arg> $args             Call arguments
     * @param non-empty-list<Union>|null $templateParams   Template type parameters from the event
     * @param int $modelTemplateIndex                      Which template parameter holds the Model type
     *                                                     (0 for Builder<TModel>, 1 for Collection<TKey, TModel>)
     */
    public static function resolvePluckReturnType(
        array $args,
        ?array $templateParams,
        int $modelTemplateIndex,
        NodeTypeProvider $nodeTypeProvider,
        Codebase $codebase,
    ): ?Union {
        if ($args === []) {
            return null;
        }

        // Extract column name from the first argument as a string literal
        $argType = $nodeTypeProvider->getType($args[0]->value);
        if (!$argType instanceof \Psalm\Type\Union || !$argType->isSingleStringLiteral()) {
            return null;
        }

        $columnName = $argType->getSingleStringLiteral()->value;

        $modelClass = self::extractModelFromUnion($templateParams[$modelTemplateIndex] ?? null);
        if ($modelClass === null) {
            return null;
        }

        $propertyType = self::resolvePropertyType($codebase, $modelClass, $columnName);
        if (!$propertyType instanceof \Psalm\Type\Union) {
            return null;
        }

        // Determine key type: int when no $key argument, array-key when $key is provided.
        // Laravel does NOT apply casts/mutators to the key column — keys come from raw PDO
        // results and are always string|int.
        $keyType = Type::getInt();
        if (\count($args) >= 2) {
            $keyType = Type::getArrayKey();
        }

        return new Union([
            new TGenericObject(Collection::class, [$keyType, $propertyType]),
        ]);
    }
}
