<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Psalm\Codebase;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
// UnionTypeComparator is in Psalm\Internal\* but is the established convention for
// type-containment checks in plugins (Psalm's own bundled providers use it directly,
// and several other handlers in this codebase already depend on Psalm\Internal\*).
// Re-verify when bumping Psalm to a new minor/major version.
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
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
            // Factory<TModel>. Naturally scoped to Builder subclasses regardless of
            // caller: Psalm's Populator only ever creates a
            // template_extended_params[Builder::class] entry when Builder is a
            // generic ancestor somewhere in the class's hierarchy, so a Collection
            // subclass's storage (reached when this runs from CollectionPluckHandler,
            // $modelTemplateIndex 1) simply has no such key and this fallback yields
            // null there — no explicit index check is needed or performed.
            if ($modelClass === null) {
                $modelClass = self::extractModelFromLhsBuilderExtends($lhsType, $codebase);
            }
        }

        if ($modelClass === null) {
            return null;
        }

        // The value column is often not a @property: raw select aliases and computed
        // columns (`selectRaw('COUNT(*) AS cnt')`) have no model annotation to resolve.
        // Don't bail on that alone — fall back to mixed for the value and still attempt
        // to narrow the key below, since the two axes are independent.
        //
        // resolveColumnType() (not the lower-level resolvePropertyType() below) so a
        // column with no @property still narrows from migration schema / casts, mirroring
        // ordinary `$model->column` reads and BuilderAggregateHandler's column resolution.
        $resolvedPropertyType = ModelPropertyHandler::resolveColumnType($codebase, $modelClass, $columnName);
        $valueResolved = $resolvedPropertyType instanceof \Psalm\Type\Union;
        $propertyType = $resolvedPropertyType ?? Type::getMixed();

        // Determine key type:
        //   - no $key argument        → int, always (Laravel's positional pluck() yields
        //                                sequential int keys regardless of the value column)
        //   - $key arg with @property → @property type if subset of array-key, else array-key
        //   - $key arg without @property → array-key
        //
        // We only adopt the @property type when it is a subset of int|string, because
        // Collection<TKey, TValue> requires TKey to be array-key. A property declared as
        // CarbonInterface|null (cast type) would produce an invalid TKey, so we fall back.
        $keyResolved = \count($args) < 2;
        $keyType = Type::getInt();
        if (\count($args) >= 2) {
            $resolvedKeyType = self::resolveKeyType(
                keyArg: $args[1],
                modelClass: $modelClass,
                nodeTypeProvider: $nodeTypeProvider,
                codebase: $codebase,
            );
            $keyResolved = $resolvedKeyType instanceof Union;
            $keyType = $resolvedKeyType ?? Type::getArrayKey();
        }

        // Neither axis narrowed past the stub's default `Collection<array-key, mixed>` —
        // defer to it instead of constructing an identical type via this handler.
        if (!$valueResolved && !$keyResolved) {
            return null;
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
     * Public: shared with {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderAggregateHandler},
     * which has the identical non-generic-custom-builder gap for sum()/avg()/min()/max()
     * (issue #1294), the same way {@see self::extractModelFromUnion()} already is.
     *
     * @return class-string<Model>|null
     * @psalm-mutation-free
     */
    public static function extractModelFromLhsBuilderExtends(?Union $lhsType, Codebase $codebase): ?string
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
     * (int|string); otherwise returns null — the caller falls back to array-key, but
     * needs to know whether narrowing actually happened (a raw-alias value column
     * combined with an equally-unresolved key column means neither axis narrowed, so
     * the caller defers to the stub entirely instead of restating its default).
     *
     * @param class-string<Model> $modelClass
     */
    private static function resolveKeyType(
        \PhpParser\Node\Arg $keyArg,
        string $modelClass,
        NodeTypeProvider $nodeTypeProvider,
        Codebase $codebase,
    ): ?Union {
        // Cheap AST pre-check: avoid a NodeTypeProvider lookup for non-literal key columns,
        // which is the common case (variables, expressions).
        if (!$keyArg->value instanceof \PhpParser\Node\Scalar\String_) {
            $keyArgType = $nodeTypeProvider->getType($keyArg->value);
            if (!$keyArgType instanceof Union || !$keyArgType->isSingleStringLiteral()) {
                return null;
            }

            $keyColumn = $keyArgType->getSingleStringLiteral()->value;
        } else {
            $keyColumn = $keyArg->value->value;
        }

        // Registry-aware resolution (see resolvePluckReturnType() above): a key column
        // with no @property still narrows from migration schema / casts.
        $keyPropertyType = ModelPropertyHandler::resolveColumnType($codebase, $modelClass, $keyColumn);
        if (!$keyPropertyType instanceof Union) {
            return null;
        }

        if (!UnionTypeComparator::isContainedBy($codebase, $keyPropertyType, Type::getArrayKey())) {
            return null;
        }

        return $keyPropertyType;
    }
}
