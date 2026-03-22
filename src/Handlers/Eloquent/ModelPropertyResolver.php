<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
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
        \PhpParser\Node\Arg $arg,
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
        \Psalm\Codebase $codebase,
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
}
