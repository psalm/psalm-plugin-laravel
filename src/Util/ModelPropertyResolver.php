<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PhpParser\Node\Arg;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Shared utilities for resolving model property types from @property annotations.
 *
 * Used by PluckHandler and CollectionPluckHandler to avoid duplicating the
 * property lookup and argument extraction logic.
 *
 * @internal
 */
final class ModelPropertyResolver
{
    /**
     * Extract a string literal value from a call argument.
     */
    public static function extractStringLiteral(
        MethodReturnTypeProviderEvent $event,
        Arg $arg,
    ): ?string {
        $argType = $event->getSource()->getNodeTypeProvider()->getType($arg->value);
        if ($argType === null || !$argType->isSingleStringLiteral()) {
            return null;
        }

        return $argType->getSingleStringLiteral()->value;
    }

    /**
     * Extract a Model class-string from a Union type, if it contains one.
     *
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    public static function extractModelFromUnion(?Union $type): ?string
    {
        if ($type === null) {
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
     * Build a typed Collection return type for pluck() from event context.
     *
     * Extracts the column name, resolves the model property type, and determines
     * the key type. Returns null if any step fails, causing the handler to fall
     * back to Psalm's default type inference.
     *
     * @param int $modelTemplateIndex Which template parameter holds the Model type
     *                                (0 for Builder<TModel>, 1 for Collection<TKey, TModel>)
     */
    public static function resolvePluckReturnType(
        MethodReturnTypeProviderEvent $event,
        int $modelTemplateIndex,
    ): ?Union {
        $args = $event->getCallArgs();
        if ($args === []) {
            return null;
        }

        $columnName = self::extractStringLiteral($event, $args[0]);
        if ($columnName === null) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        $modelClass = self::extractModelFromUnion($templateTypeParameters[$modelTemplateIndex] ?? null);
        if ($modelClass === null) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();
        $propertyType = self::resolvePropertyType($codebase, $modelClass, $columnName);
        if ($propertyType === null) {
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
