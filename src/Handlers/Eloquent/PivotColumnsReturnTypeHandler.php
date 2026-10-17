<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * `withPivot()` / `withTimestamps()` refine the pivot slot of the relation they are called on:
 * `BelongsToMany<R, D, Pivot, 'pivot'>` becomes `BelongsToMany<R, D, Pivot&object{role: mixed}, 'pivot'>` (#1944).
 *
 * Why a handler, not the stub's `@return $this`: the new columns come from the call's arguments (and their
 * types from the schema), which a docblock cannot express. It also runs inside a relation method body, where
 * ModelRelationReturnTypeHandler never reaches, so a declared `Pivot&object{role: string}` return type matches
 * the inferred one. Column types: {@see PivotShapeResolver}.
 *
 * Psalm falls back to the DECLARING class's provider for a subclass call. `withTimestamps()` is declared on
 * `BelongsToMany`, but `MorphToMany::withPivot()` resolves to the `InteractsWithPivotTable` trait, so both are
 * registered to cover `MorphToMany`. The answer needs BOTH the dispatched class and the receiver expression to be exactly
 * one of the two base classes (#1906): the class alone passes `C of BelongsToMany` templates and
 * `BelongsToMany&Sub` intersections, the expression alone a `$this` reshaped by a userland subclass. Anything
 * else declines, leaving the stub's `$this`.
 *
 * @internal
 */
final class PivotColumnsReturnTypeHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [BelongsToMany::class, InteractsWithPivotTable::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        $stmt = $event->getStmt();
        if (($method !== 'withpivot' && $method !== 'withtimestamps') || !$stmt instanceof MethodCall) {
            return null;
        }

        $called = \strtolower($event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName());
        if ($called !== \strtolower(BelongsToMany::class) && $called !== \strtolower(MorphToMany::class)) {
            return null;
        }

        $source = $event->getSource();
        $receiver = $source->getNodeTypeProvider()->getType($stmt->var);
        if (!$receiver instanceof Union || !$receiver->isSingle()) {
            return null;
        }

        $atomic = $receiver->getSingleAtomic();
        if (!$atomic instanceof TGenericObject || $atomic->extra_types !== []) {
            return null;
        }

        $params = $atomic->type_params;
        $default = ModelRelationReturnTypeHandler::PIVOT_AWARE_RELATIONS[$called === \strtolower(MorphToMany::class) ? MorphToMany::class : BelongsToMany::class];
        if (\count($params) === 2) {
            $params[] = new Union([new TNamedObject($default)]);
            $params[] = new Union([TLiteralString::make('pivot')]);
        } elseif (\count($params) !== 4) {
            return null;
        }

        $pivotClass = self::singleClass($params[2]);
        $table = null;
        $factory = RelationMethodParser::findPivotFactoryCall($stmt->var);
        if ($factory !== null) {
            $table = RelationMethodParser::factoryPivotTable($factory[0], $factory[1], $source->getFQCLN() ?? '', null);
        }

        $known = PivotShapeResolver::shapeProperties($params[2]);
        if ($pivotClass === null || $known === null) {
            return null;
        }

        // Psalm invokes a provider without a safety net: an escaping exception aborts the whole run.
        try {
            $properties = (new PivotShapeResolver(
                $source->getCodebase(),
                $pivotClass,
                $table,
                self::singleClass($params[1]),
                self::singleClass($params[0]),
            ))->properties(
                $method === 'withpivot' ? RelationMethodParser::withPivotColumns($stmt) : [],
                $method === 'withtimestamps' ? [RelationMethodParser::withTimestampsArgs($stmt)] : [],
                $known,
            );
        } catch (\Throwable $throwable) {
            $source->getCodebase()->progress->debug("Laravel plugin: pivot columns provider failed for {$pivotClass}::{$method}: {$throwable->getMessage()}\n");

            return null;
        }

        $refined = $properties === [] ? null : PivotShapeResolver::refine($params[2], $properties);
        if (!$refined instanceof TNamedObject) {
            return null;
        }

        $params[2] = new Union([$refined]);

        return new Union([$atomic->setTypeParams($params)]);
    }

    /**
     * The class of a template argument that is a single plain class (`Team&static` counts, a union or
     * template does not).
     *
     * @psalm-mutation-free
     */
    private static function singleClass(Union $type): ?string
    {
        if (!$type->isSingle()) {
            return null;
        }

        $atomic = $type->getSingleAtomic();

        return $atomic instanceof TNamedObject && !$atomic instanceof TGenericObject ? $atomic->value : null;
    }
}
