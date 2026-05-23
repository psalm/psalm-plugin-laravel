<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Narrows Builder::sum/avg/average/min/max returns using the column's resolved
 * Psalm type (user `@property` → cast → schema).
 *
 * Targets Eloquent Builder only (Query\Builder has no model template). Calls
 * arriving via Eloquent's `@mixin Query\Builder` chain still surface the
 * Eloquent template parameters on the event.
 *
 * Cast-aware min/max diverges from Laravel runtime: the query-level aggregate
 * does NOT apply casts, but reporting the cast type matches the rest of the
 * plugin's column-read view and the issue's stated request.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1004
 * @internal
 */
final class BuilderAggregateHandler implements MethodReturnTypeProviderInterface
{
    /**
     * Hash-lookup table for the method-name quick reject. Hoisted as a const so
     * the array isn't reallocated on every Builder method call (this provider
     * fires on the hot path).
     */
    private const AGGREGATE_METHODS = [
        'sum' => true,
        'avg' => true,
        'average' => true,
        'min' => true,
        'max' => true,
    ];

    /**
     * Registers both Builder classes because sum/avg/min/max are declared on
     * Query\Builder and reached from Eloquent\Builder via `@mixin`. Psalm's
     * {@see \Psalm\Internal\Analyzer\Statements\Expression\Call\Method\MethodCallReturnTypeFetcher}
     * fires the provider for the premixin class first and the declaring class
     * second — registering both ensures the call is resolved regardless of
     * which leg picks up the model template.
     *
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Builder::class, QueryBuilder::class];
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

        $columnType = ModelPropertyHandler::resolveColumnType(
            $source->getCodebase(),
            $modelClass,
            $columnName,
        );
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
     * LHS fallback handles @mixin chains where event template params arrive
     * unsubstituted (mirrors {@see ModelPropertyResolver::resolvePluckReturnType}).
     *
     * @return class-string<Model>|null
     */
    private static function resolveModelClass(MethodReturnTypeProviderEvent $event): ?string
    {
        $templateParams = $event->getTemplateTypeParameters();
        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateParams[0] ?? null);
        if ($modelClass !== null) {
            return $modelClass;
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
