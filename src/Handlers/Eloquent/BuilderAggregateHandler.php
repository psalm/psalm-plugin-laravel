<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Narrows sum/avg/average/min/max returns using the aggregated column's resolved
 * Psalm type (user `@property` → cast → schema).
 *
 * Covers the three call shapes that surface the same aggregate: the query builder
 * (`Model::query()->sum('col')`), the relation (`$model->rel()->sum('col')`), and the
 * in-memory collection (`$model->rel->sum('col')`). See {@see self::getClassLikeNames()}
 * for how each shape carries the model and why all three register here.
 *
 * Cast-aware min/max diverges from Laravel runtime: the query-level aggregate
 * does NOT apply casts, but reporting the cast type matches the rest of the
 * plugin's column-read view and the issue's stated request.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1004
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1182
 * @internal
 */
final class BuilderAggregateHandler implements MethodReturnTypeProviderInterface
{
    /**
     * Hash-lookup table for the method-name quick reject. Hoisted as a const so
     * the array isn't reallocated on every method call of a registered receiver —
     * Collection is among them, so this provider fires on a very hot path.
     */
    private const AGGREGATE_METHODS = [
        'sum' => true,
        'avg' => true,
        'average' => true,
        'min' => true,
        'max' => true,
    ];

    /**
     * Three call shapes (across five registered classes) reach the same
     * column-narrowing logic; each surfaces the aggregated model differently.
     * {@see \Psalm\Internal\Analyzer\Statements\Expression\Call\Method\MethodCallReturnTypeFetcher}
     * dispatches return-type providers on two legs: the premixin (receiver) class and the
     * declaring class.
     *
     * - {@see Builder} / {@see QueryBuilder} — the direct query path. sum/avg/min/max are
     *   declared on Query\Builder and reached from Eloquent\Builder via `@mixin`; registering
     *   both classes resolves the call regardless of which leg carries the model template.
     * - {@see Relation} — `$model->rel()->sum('col')`. Relations forward aggregates to the
     *   builder via `__call`, a path on which Psalm exposes neither argument types nor the
     *   substituted model, so the aggregates are declared as real methods in Relation.phpstub
     *   for this provider to read the column. Registering the abstract base suffices: the
     *   methods are inherited, so every subclass call's declaring method id is `Relation::{m}`
     *   and the declaring-class leg fires.
     * - {@see Collection} / {@see EloquentCollection} — the in-memory path
     *   (`$model->rel->sum('col')`). Both are registered so the receiver-class leg fires for
     *   each; the aggregated model is the collection's value parameter, picked up by
     *   {@see self::resolveModelClass()} scanning all template params.
     *
     * Custom collection subclasses (newCollection() / #[CollectedBy]) are not narrowed on the
     * in-memory read — they resolve to `mixed`; LazyCollection is likewise out of scope. The
     * relation-level path still narrows those models via `rel()->sum('col')`.
     *
     * Unlike the pluck handlers (a Builder/Collection split sharing a Util resolver), all
     * shapes live in one handler because the column resolver this uses,
     * {@see ModelPropertyHandler::resolveColumnType()} (schema + casts, not just `@property`),
     * is an Eloquent handler; a Util-level split would either lose schema columns or make a
     * util reach back into a handler.
     *
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            Builder::class,
            QueryBuilder::class,
            Relation::class,
            Collection::class,
            EloquentCollection::class,
        ];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        if (!isset(self::AGGREGATE_METHODS[$method])) {
            return null;
        }

        $args = $event->getCallArgs();
        if ($args === []) {
            return null;
        }

        $source = $event->getSource();
        $argType = $source->getNodeTypeProvider()->getType($args[0]->value);
        if (!$argType instanceof Union || !$argType->isSingleStringLiteral()) {
            return null;
        }

        $columnName = $argType->getSingleStringLiteral()->value;

        $modelClass = self::resolveModelClass($event);
        if ($modelClass === null) {
            return null;
        }

        $columnType = ModelPropertyHandler::resolveColumnType($source->getCodebase(), $modelClass, $columnName);
        if (!$columnType instanceof Union) {
            return null;
        }

        return match ($method) {
            'sum' => self::narrowSum($columnType),
            'avg', 'average' => self::narrowAvg($columnType),
            'min', 'max' => self::narrowMinMax($columnType),
        };
    }

    /**
     * Resolves the model whose column is being aggregated from the receiver's template
     * parameters: `Builder<TModel>` and `Relation<TRelated, ...>` carry it first, while
     * `Collection<TKey, TValue>` carries it second (the key is never a model). Scanning all
     * params and taking the first that resolves to a Model handles every shape uniformly.
     *
     * LHS fallback handles @mixin chains where event template params arrive
     * unsubstituted (mirrors {@see ModelPropertyResolver::resolvePluckReturnType}).
     *
     * @return class-string<Model>|null
     */
    private static function resolveModelClass(MethodReturnTypeProviderEvent $event): ?string
    {
        $templateParams = $event->getTemplateTypeParameters();
        foreach ($templateParams ?? [] as $param) {
            $modelClass = ModelPropertyResolver::extractModelFromUnion($param);
            if ($modelClass !== null) {
                return $modelClass;
            }
        }

        $stmt = $event->getStmt();
        $lhsExpr = $stmt instanceof \PhpParser\Node\Expr\MethodCall ? $stmt->var : null;
        if (!$lhsExpr instanceof \PhpParser\Node\Expr) {
            return null;
        }

        $lhsType = $event->getSource()->getNodeTypeProvider()->getType($lhsExpr);
        if (!$lhsType instanceof Union) {
            return null;
        }

        foreach ($lhsType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof Type\Atomic\TGenericObject) {
                continue;
            }

            $model = ModelPropertyResolver::extractModelFromUnion($atomic->type_params[0] ?? null);
            if ($model !== null) {
                return $model;
            }
        }

        return null;
    }

    /**
     * sum() upstream returns `$result ?: 0` so null is impossible. Pure int or pure float only.
     *
     * @psalm-mutation-free
     */
    private static function narrowSum(Union $columnType): ?Union
    {
        $shape = self::numericShape($columnType);
        if ($shape === 'int') {
            return Type::getInt();
        }

        if ($shape === 'float') {
            return Type::getFloat();
        }

        return null;
    }

    /**
     * avg() is null on empty table; numeric values land as float|int.
     *
     * @psalm-mutation-free
     */
    private static function narrowAvg(Union $columnType): ?Union
    {
        if (self::numericShape($columnType) === null) {
            return null;
        }

        return new Union([new TInt(), new TFloat(), new TNull()]);
    }

    /**
     * min()/max() return column type + null (empty table). Idempotent on already-nullable.
     *
     * @psalm-external-mutation-free
     */
    private static function narrowMinMax(Union $columnType): Union
    {
        if ($columnType->isNullable()) {
            return $columnType;
        }

        return Type::combineUnionTypes($columnType, Type::getNull());
    }

    /**
     * Returns `'int'` / `'float'` only when the column is one of those (optionally nullable).
     * Mixed numerics, strings, numeric-string, TNumeric, objects → null (defer to stub).
     *
     * @psalm-mutation-free
     */
    private static function numericShape(Union $type): ?string
    {
        $hasInt = false;
        $hasFloat = false;

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TInt) {
                $hasInt = true;
                continue;
            }

            if ($atomic instanceof TFloat) {
                $hasFloat = true;
                continue;
            }

            if ($atomic instanceof TNull) {
                continue;
            }

            return null;
        }

        if ($hasInt && !$hasFloat) {
            return 'int';
        }

        if ($hasFloat && !$hasInt) {
            return 'float';
        }

        return null;
    }
}
