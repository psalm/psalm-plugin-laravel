<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TCallableObject;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Collapses `Builder::find($mixed)` (and `findOrFail` / `findOrNew` / `findOr`)
 * to the scalar-id branch when the argument is `mixed`. Also covers the
 * relation classes that re-declare these methods with their own conditionals
 * (`BelongsToMany`, `HasManyThrough`, `HasOneOrManyThrough`, `HasOneOrMany`).
 *
 * The stub uses a template conditional:
 *
 *     @template T
 *     @param T $id
 *     @psalm-return (T is (array|Arrayable) ? Collection<int, TModel> : TModel|null)
 *
 * When the caller's `$id` is `mixed` (e.g., property access on `\stdClass`
 * returned by `\DB::select()`), Psalm cannot prove `mixed is array|Arrayable`
 * either way, so it widens the result to the union of both branches:
 * `Collection<int, TModel>|TModel|null`. Downstream `$model->prop = ...` then
 * surfaces as `UndefinedPropertyAssignment` on the Collection branch.
 *
 * Larastan ships an `@method` overload pair on the stub, but Psalm stores
 * pseudo-methods in `$storage->pseudo_methods[$lc_method_name]` — a
 * last-write-wins map, not an overload set — so the overload approach is not
 * portable. This handler implements the same trade-off Larastan makes: when
 * the argument is `mixed`, return the scalar-id branch (`TModel|null` /
 * `TModel`), accepting that callers who pass a `mixed` that is actually an
 * array get the wrong narrowing in exchange for the common case (DB-row id
 * lookups) staying clean. See `tests/Type/tests/Builder/FindMixedTradeOffTest.phpt`
 * for the pinned trade-off.
 *
 * For `BelongsToMany`, the scalar-id branch carries a pivot intersection
 * (`TRelatedModel&object{pivot: TPivotModel}`); the handler reconstructs that
 * shape from the relation's templates (TPivotModel is at index 2). The other
 * covered relations do not carry pivot intersections.
 *
 * Non-mixed argument types are deferred to the stub, where the conditional
 * resolves correctly.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/975
 * @internal
 */
final class BuilderFindMixedHandler implements MethodReturnTypeProviderInterface
{
    private const METHOD_FIND = 'find';

    private const METHOD_FIND_OR_FAIL = 'findorfail';

    private const METHOD_FIND_OR_NEW = 'findornew';

    private const METHOD_FIND_OR = 'findor';

    /**
     * @var array<string, true>
     */
    private const TARGET_METHODS = [
        self::METHOD_FIND => true,
        self::METHOD_FIND_OR_FAIL => true,
        self::METHOD_FIND_OR_NEW => true,
        self::METHOD_FIND_OR => true,
    ];

    /**
     * Pivot template-parameter index per class. The class must be in
     * `getClassLikeNames()` to reach the handler; `null` means the class has
     * no pivot intersection (use bare TRelatedModel).
     *
     * @var array<class-string, int|null>
     */
    private const PIVOT_TEMPLATE_INDEX = [
        Builder::class => null,
        HasOneOrMany::class => null,
        HasManyThrough::class => null,
        HasOneOrManyThrough::class => null,
        BelongsToMany::class => 2,
    ];

    /**
     * Cache for the `null` Union combined with `TModel` in the scalar-id
     * branch. `Type::getNull()` is not memoized inside Psalm — every call
     * allocates a fresh `TNull` + `Union`. Caching saves an allocation pair on
     * every reachable `find($mixed)` call site analyzed by the worker.
     */
    private static ?Union $nullUnion = null;

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            Builder::class,
            HasOneOrMany::class,
            HasManyThrough::class,
            HasOneOrManyThrough::class,
            BelongsToMany::class,
        ];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $methodName = $event->getMethodNameLowercase();
        if (!isset(self::TARGET_METHODS[$methodName])) {
            return null;
        }

        $stmt = $event->getStmt();
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        $args = $event->getCallArgs();
        if ($args === []) {
            return null;
        }

        $nodeTypeProvider = $event->getSource()->getNodeTypeProvider();
        $idType = $nodeTypeProvider->getType($args[0]->value);
        if (!$idType instanceof \Psalm\Type\Union || !$idType->isMixed()) {
            return null;
        }

        $templateParams = $event->getTemplateTypeParameters();
        if ($templateParams === null || !isset($templateParams[0])) {
            return null;
        }

        $tModel = self::applyPivotIntersection(
            tModel: $templateParams[0],
            templateParams: $templateParams,
            fqClassName: $event->getFqClasslikeName(),
        );

        return match ($methodName) {
            self::METHOD_FIND => Type::combineUnionTypes($tModel, self::nullUnion()),
            self::METHOD_FIND_OR_FAIL, self::METHOD_FIND_OR_NEW => $tModel,
            self::METHOD_FIND_OR => Type::combineUnionTypes(
                $tModel,
                self::extractFindOrTValue($args, $nodeTypeProvider) ?? self::nullUnion(),
            ),
            default => null,
        };
    }

    /**
     * Wraps `$tModel` with `&object{pivot: TPivotModel}` for `BelongsToMany`,
     * matching the stub's scalar-id branch. Returns `$tModel` unchanged for
     * classes without a pivot template.
     *
     * @param list<Union> $templateParams
     *
     * @psalm-mutation-free
     */
    private static function applyPivotIntersection(
        Union $tModel,
        array $templateParams,
        string $fqClassName,
    ): Union {
        $pivotIndex = self::PIVOT_TEMPLATE_INDEX[$fqClassName] ?? null;
        if ($pivotIndex === null || !isset($templateParams[$pivotIndex])) {
            return $tModel;
        }

        $pivotIntersection = new TObjectWithProperties(['pivot' => $templateParams[$pivotIndex]]);
        $intersectionKey = $pivotIntersection->getKey();

        $newAtomics = [];
        foreach ($tModel->getAtomicTypes() as $atomic) {
            // Only atomics that use HasIntersectionTrait support
            // setIntersectionTypes(). Others (e.g. TNull) are passed through —
            // they cannot carry the pivot intersection and forcing one would
            // crash.
            if (
                $atomic instanceof TNamedObject
                || $atomic instanceof TTemplateParam
                || $atomic instanceof TObjectWithProperties
                || $atomic instanceof TIterable
                || $atomic instanceof TCallableObject
            ) {
                $existing = $atomic->getIntersectionTypes();
                $existing[$intersectionKey] = $pivotIntersection;
                $newAtomics[] = $atomic->setIntersectionTypes($existing);
            } else {
                $newAtomics[] = $atomic;
            }
        }

        return new Union($newAtomics);
    }

    /**
     * Resolves the `TValue` template that `findOr` returns when the lookup
     * misses. The callback can be either argument 2 (Laravel's documented
     * shape) or argument 1 (the `findOr($id, $callback)` convenience form
     * where Builder swaps `$columns` for `$callback` internally).
     *
     * @param list<Arg> $args
     */
    private static function extractFindOrTValue(array $args, NodeTypeProvider $nodeTypeProvider): ?Union
    {
        foreach ([2, 1] as $argIndex) {
            if (!isset($args[$argIndex])) {
                continue;
            }

            $argType = $nodeTypeProvider->getType($args[$argIndex]->value);
            if (!$argType instanceof \Psalm\Type\Union) {
                continue;
            }

            foreach ($argType->getAtomicTypes() as $atomic) {
                if (!$atomic instanceof TClosure) {
                    continue;
                }

                if ($atomic->return_type instanceof \Psalm\Type\Union) {
                    return $atomic->return_type;
                }
            }
        }

        return null;
    }

    /**
     * `Type::getNull()` is not memoized inside Psalm — every call allocates a
     * fresh `TNull` + `Union`. The cache here saves an allocation pair on
     * every reachable mixed-id call site analyzed by the worker.
     *
     * @psalm-external-mutation-free
     */
    private static function nullUnion(): Union
    {
        return self::$nullUnion ??= Type::getNull();
    }
}
