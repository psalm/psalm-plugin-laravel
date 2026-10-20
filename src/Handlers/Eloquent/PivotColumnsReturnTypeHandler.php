<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
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
        if (($method !== 'withpivot' && $method !== 'withtimestamps') || !$stmt instanceof MethodCall || $stmt->isFirstClassCallable()) {
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
        if (!$atomic instanceof TGenericObject || $atomic->extra_types !== [] || \strtolower($atomic->value) !== $called) {
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

        $using = self::callsUsing($stmt->var);

        // Psalm invokes a provider without a safety net: an escaping exception aborts the whole run.
        try {
            $properties = (new PivotShapeResolver(
                $source->getCodebase(),
                $pivotClass,
                $table,
                self::singleClass($params[1]),
                self::singleClass($params[0]),
                false,
                $using,
                $factory === null && !$using,
            ))->properties(
                $method === 'withpivot' ? self::pivotColumns($stmt, $source->getNodeTypeProvider()) : [],
                $method === 'withtimestamps' ? [self::timestampArguments($stmt, $source->getNodeTypeProvider())] : [],
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
     * Whether a `using()` call sits in the chain: it hydrates the pivot through `fromRawAttributes()` even when it names
     * `Pivot` itself, which the slot's class alone cannot tell.
     *
     * @psalm-capabilities read-props
     */
    private static function callsUsing(\PhpParser\Node\Expr $expr): bool
    {
        while ($expr instanceof MethodCall) {
            if ($expr->name instanceof Identifier && \strtolower($expr->name->name) === 'using') {
                return true;
            }

            $expr = $expr->var;
        }

        return false;
    }

    /**
     * The columns `withPivot()` selects, from the argument TYPES (a `$column = 'role'` variable or a literal array in
     * a variable counts). Laravel reads the first argument when it is an array and ignores the rest, so a first
     * argument that may be an array (or is unknown) claims no trailing column.
     *
     * @return list<non-empty-string>
     */
    private static function pivotColumns(MethodCall $call, NodeTypeProvider $types): array
    {
        $values = [];
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack || ($arg->name instanceof Identifier && ($position !== 0 || $arg->name->name !== 'columns'))) {
                return [];
            }

            $values[] = $arg->value;
        }

        $first = isset($values[0]) ? $types->getType($values[0]) : null;
        if (!$first instanceof Union) {
            return [];
        }

        $columns = [];
        if ($first->hasArray()) {
            $array = $first->isSingle() ? $first->getSingleAtomic() : null;
            // array_merge() lets a later string key replace an earlier one, so keyed entries claim nothing.
            foreach ($array instanceof TKeyedArray ? $array->properties : [] as $key => $item) {
                $columns[] = \is_int($key) ? self::literalColumn($item) : null;
            }
        } elseif ($first->isString()) {
            foreach ($values as $value) {
                $columns[] = self::literalColumn($types->getType($value));
            }
        }

        return \array_values(\array_filter($columns, static fn(?string $column): bool => $column !== null));
    }

    /**
     * The `withTimestamps()` arguments as {@see RelationMethodParser::withTimestampsArgs()} shapes them, from
     * the argument types (`Customer::CREATED_AT` is a literal).
     *
     * @return array{created: string|false|null, updated: string|false|null}
     */
    private static function timestampArguments(MethodCall $call, NodeTypeProvider $types): array
    {
        $result = ['created' => null, 'updated' => null];
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return ['created' => false, 'updated' => false];
            }

            $slot = $arg->name instanceof Identifier
                ? ['createdAt' => 'created', 'updatedAt' => 'updated'][$arg->name->name] ?? null
                : match ($position) {
                    0 => 'created',
                    1 => 'updated',
                    default => null,
                };
            if ($slot === null) {
                continue;
            }

            $type = $types->getType($arg->value);
            $result[$slot] = $type instanceof Union && $type->isNull() ? null : (self::literalColumn($type) ?? false);
        }

        return $result;
    }

    /**
     * The value of a single non-empty string literal type, else null.
     *
     * @return ?non-empty-string
     * @psalm-mutation-free
     */
    private static function literalColumn(?Union $type): ?string
    {
        if (!$type instanceof Union || $type->possibly_undefined || !$type->isSingleStringLiteral()) {
            return null;
        }

        $value = $type->getSingleStringLiteral()->value;

        return $value === '' ? null : $value;
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
