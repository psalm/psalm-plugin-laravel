<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psalm\Codebase;
// UnionTypeComparator is in Psalm\Internal\* but is the established convention for
// type-containment checks in plugins (Psalm's own bundled providers use it directly,
// and several other handlers in this codebase already depend on Psalm\Internal\*).
// Re-verify when bumping Psalm to a new minor/major version.
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
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
    public static function resolvePropertyType(Codebase $codebase, string $modelClass, string $propertyName): ?Union
    {
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
     * @param \PhpParser\Node\Expr|null $lhsExpr           The method call's left-hand-side expression
     *                                                     (e.g. `$customer->vehicles()` for `$customer->vehicles()->pluck()`).
     *                                                     Used as a fallback when the event's template parameters are
     *                                                     unsubstituted templates (Psalm's @mixin chain limitation).
     */
    public static function resolvePluckReturnType(
        array $args,
        ?array $templateParams,
        int $modelTemplateIndex,
        NodeTypeProvider $nodeTypeProvider,
        Codebase $codebase,
        ?\PhpParser\Node\Expr $lhsExpr = null,
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

        // When the call is reached via Psalm's @mixin chain (e.g. $relation->pluck()
        // forwards to Builder<TRelatedModel>), the event's template parameters can
        // still contain the *unsubstituted* template (TTemplateParam) instead of the
        // concrete model. Fall back to inspecting the call's LHS expression, whose
        // type carries the concrete generic arguments.
        if ($modelClass === null && $lhsExpr instanceof \PhpParser\Node\Expr) {
            $lhsType = $nodeTypeProvider->getType($lhsExpr);
            $modelClass = self::extractModelFromLhsType($lhsType, $modelTemplateIndex);

            // Custom Builder subclasses declared as `/** @extends Builder<Task> */
            // final class TaskBuilder extends Builder {}` are not themselves generic,
            // so the LHS type above is a plain TNamedObject (no type_params for the
            // fallback just above to read) and the event's template parameters are
            // empty. Resolve TModel from the classlike storage's
            // template_extended_params[Builder::class]['TModel'], which Psalm
            // populates from the @extends docblock — the same mechanism
            // ModelFactoryMethodTypeProvider/FactoryCountTypeProvider use for
            // Factory<TModel>. Scoped to Builder: this only ever contributes a result
            // when $modelTemplateIndex is Builder's own (0), since a Collection
            // subclass's storage has no Builder entry in template_extended_params.
            if ($modelClass === null) {
                $modelClass = self::extractModelFromLhsBuilderExtends($lhsType, $codebase);
            }
        }

        if ($modelClass === null) {
            return null;
        }

        $propertyType = self::resolvePropertyType($codebase, $modelClass, $columnName);
        if (!$propertyType instanceof \Psalm\Type\Union) {
            return null;
        }

        // Determine key type:
        //   - no $key argument        → int (positional Laravel key)
        //   - $key arg with @property → @property type if subset of array-key, else array-key
        //   - $key arg without @property → array-key
        //
        // We only adopt the @property type when it is a subset of int|string, because
        // Collection<TKey, TValue> requires TKey to be array-key. A property declared as
        // CarbonInterface|null (cast type) would produce an invalid TKey, so we fall back.
        $keyType = Type::getInt();
        if (\count($args) >= 2) {
            $keyType = self::resolveKeyType(
                keyArg: $args[1],
                modelClass: $modelClass,
                nodeTypeProvider: $nodeTypeProvider,
                codebase: $codebase,
            );
        }

        return new Union([
            new TGenericObject(Collection::class, [$keyType, $propertyType]),
        ]);
    }

    /**
     * Extract a Model class-string from the call's LHS type at the given template index.
     *
     * The LHS type (e.g. HasMany<Vehicle, Customer>) carries concrete generic arguments
     * even when the event's template parameters do not, because @mixin forwarding can
     * leave the template unsubstituted by the time the return-type provider runs.
     *
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    private static function extractModelFromLhsType(?Union $lhsType, int $modelTemplateIndex): ?string
    {
        if (!$lhsType instanceof Union) {
            return null;
        }

        foreach ($lhsType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject) {
                continue;
            }

            $modelType = $atomic->type_params[$modelTemplateIndex] ?? null;
            $modelClass = self::extractModelFromUnion($modelType);
            if ($modelClass !== null) {
                return $modelClass;
            }
        }

        return null;
    }

    /**
     * Resolve TModel for a custom, non-generic Builder subclass from its classlike
     * storage's `@extends Builder<TModel>` binding.
     *
     * `ClassLikeStorageProvider::get()` lowercases internally, so the FQCN is passed
     * through untouched. `template_extended_params` is keyed on the canonical class
     * name (`$parent_storage->name`), which matches `Builder::class` for any
     * vendor-stable Laravel install, and flattens through intermediate,
     * non-generic ancestors (e.g. `TaskBuilder extends BaseTaskBuilder extends
     * Builder`), so deeper inheritance chains resolve the same way.
     *
     * A generic custom builder (`@template T of Model` / `@extends Builder<T>`),
     * used as `TaskBuilder<Task>`, is a TGenericObject and already handled by
     * {@see self::extractModelFromLhsType()}; when reached from here instead, its
     * own TModel binding resolves to the unsubstituted template `T`, which
     * {@see self::extractModelFromUnion()} does not recognize as a Model, so this
     * method correctly yields null for that case rather than a wrong binding.
     *
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    private static function extractModelFromLhsBuilderExtends(?Union $lhsType, Codebase $codebase): ?string
    {
        if (!$lhsType instanceof Union) {
            return null;
        }

        foreach ($lhsType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            try {
                $classStorage = $codebase->classlike_storage_provider->get($atomic->value);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $modelClass = self::extractModelFromUnion(
                $classStorage->template_extended_params[Builder::class]['TModel'] ?? null,
            );
            if ($modelClass !== null) {
                return $modelClass;
            }
        }

        return null;
    }

    /**
     * Resolve the TKey type for pluck($value, $key) when a key argument is provided.
     *
     * Returns the @property type of the key column when it is a subset of array-key
     * (int|string); otherwise returns array-key. Returns array-key for dynamic/unknown
     * key columns.
     *
     * @param class-string<Model> $modelClass
     */
    private static function resolveKeyType(
        \PhpParser\Node\Arg $keyArg,
        string $modelClass,
        NodeTypeProvider $nodeTypeProvider,
        Codebase $codebase,
    ): Union {
        // Cheap AST pre-check: avoid a NodeTypeProvider lookup for non-literal key columns,
        // which is the common case (variables, expressions).
        if (!$keyArg->value instanceof \PhpParser\Node\Scalar\String_) {
            $keyArgType = $nodeTypeProvider->getType($keyArg->value);
            if (!$keyArgType instanceof Union || !$keyArgType->isSingleStringLiteral()) {
                return Type::getArrayKey();
            }

            $keyColumn = $keyArgType->getSingleStringLiteral()->value;
        } else {
            $keyColumn = $keyArg->value->value;
        }

        $keyPropertyType = self::resolvePropertyType($codebase, $modelClass, $keyColumn);
        if (!$keyPropertyType instanceof Union) {
            return Type::getArrayKey();
        }

        if (!UnionTypeComparator::isContainedBy($codebase, $keyPropertyType, Type::getArrayKey())) {
            return Type::getArrayKey();
        }

        return $keyPropertyType;
    }
}
