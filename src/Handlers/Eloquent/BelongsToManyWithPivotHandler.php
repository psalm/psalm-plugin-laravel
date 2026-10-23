<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * `->withPivot('role')` adds the selected columns to TPivotModel: `BelongsToMany<User, Team, Pivot&object{role: string}, 'pivot'>`,
 * so a declaration carrying that shape is accepted (#1944). The stub cannot express it: the
 * columns are call arguments, and the column types come from the pivot table's migration.
 * `withTimestamps()` adds its created/updated columns, and `using(X::class)` builds the shape on `X`.
 *
 * When the chain is visible down to its `$this->belongsToMany(...)` call, every call of it is read again
 * on each of these three methods, so the result does not depend on their order: the pivot kind decides
 * whether the timestamp columns are dates ({@see PivotColumnResolver::columnTypes()}).
 *
 * @internal
 */
final class BelongsToManyWithPivotHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [BelongsToMany::class, MorphToMany::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $stmt = $event->getStmt();
        $method = $event->getMethodNameLowercase();
        if (!\in_array($method, ['withpivot', 'withtimestamps', 'using'], true) || !$stmt instanceof MethodCall) {
            return null;
        }

        // The provider fires per receiver atomic but sees the whole receiver: refine single atomics only.
        $receiver = $event->getSource()->getNodeTypeProvider()->getType($stmt->var);
        if (!$receiver instanceof Union || !$receiver->isSingle()) {
            return null;
        }

        $relation = $receiver->getSingleAtomic();
        if (
            !$relation instanceof TGenericObject
            || !\in_array(\strtolower($relation->value), [\strtolower(BelongsToMany::class), \strtolower(MorphToMany::class)], true)
            || \count($relation->type_params) !== 4
            || !$relation->type_params[2]->isSingle()
        ) {
            return null;
        }

        $chain = self::chain($event, $stmt, $relation);
        $slot = $relation->type_params[2]->getSingleAtomic();
        // `using()` has no columns of its own, and an unseen chain may carry one that moves the date casts.
        if (
            $chain['columns'] === [] || $chain['table'] === false
            || $method === 'using' && !$chain['visible']
            // Later calls see the custom class `using()` left in the slot.
            || $method !== 'using' && $chain['using'] !== null && (!$slot instanceof TNamedObject || \strcasecmp($slot->value, $chain['using']) !== 0)
        ) {
            return null;
        }

        $pivot = PivotColumnResolver::refine(
            // A visible chain is rebuilt whole: the slot's shape would only keep columns typed before it was.
            $chain['visible'] && $slot instanceof TNamedObject ? new TNamedObject($slot->value) : $slot,
            PivotColumnResolver::columnTypes(
                $event->getSource()->getCodebase(),
                $chain['table'],
                $chain['columns'],
                self::className($relation->type_params[1]),
                $chain['using'],
                $chain['visible'],
            ),
            $chain['using'],
        );
        if (!$pivot instanceof TNamedObject) {
            return null;
        }

        // A copy of the receiver keeps what Psalm already expanded on it (`&static`, other slots).
        $params = $relation->type_params;
        $params[2] = new Union([$pivot]);

        return new Union([$relation->setTypeParams($params)]);
    }

    /**
     * What the calls of the receiver chain below (and including) `$stmt` select: columns innermost first, the
     * outermost `using()` class, and the pivot table of the `$this->belongsToMany(...)` call the chain starts
     * from. `visible` is false when the chain starts elsewhere (a delegated relation method, a parameter) or
     * a `using()` argument is not a class literal.
     *
     * @return array{columns: list<string>, using: ?string, table: string|false|null, visible: bool}
     */
    private static function chain(MethodReturnTypeProviderEvent $event, MethodCall $stmt, TGenericObject $relation): array
    {
        $codebase = $event->getSource()->getCodebase();
        $parent = self::className($relation->type_params[1]);
        $chain = ['columns' => [], 'using' => null, 'table' => null, 'visible' => false];
        $usingSeen = false;
        for ($expr = $stmt; $expr instanceof MethodCall && $expr->name instanceof Identifier; $expr = $expr->var) {
            $name = \strtolower($expr->name->name);
            if ($name === 'withpivot') {
                $chain['columns'] = [...PivotColumnResolver::columnsFromArgs($expr->args), ...$chain['columns']];
            } elseif ($name === 'withtimestamps') {
                $chain['columns'] = [...PivotColumnResolver::timestampColumns($codebase, $expr, $parent), ...$chain['columns']];
            } elseif ($name === 'using' && !$usingSeen) {
                // The outermost runs last and wins.
                $usingSeen = true;
                $chain['using'] = self::classLiteral($event, $expr);
            } elseif (isset(PivotColumnResolver::FACTORY_TABLE_POSITION[$name]) && $expr->var instanceof Expr\Variable && $expr->var->name === 'this') {
                $chain['table'] = PivotColumnResolver::table($codebase, $expr, $name, self::className($relation->type_params[0]), $parent);
                $chain['visible'] = !$usingSeen || $chain['using'] !== null;
                break;
            }
        }

        return $chain;
    }

    private static function classLiteral(MethodReturnTypeProviderEvent $event, MethodCall $using): ?string
    {
        $arg = $using->args[0] ?? null;
        $type = $arg instanceof Arg && !$arg->unpack ? $event->getSource()->getNodeTypeProvider()->getType($arg->value) : null;
        $atomic = $type?->isSingle() === true ? $type->getSingleAtomic() : null;

        return $atomic instanceof TLiteralClassString ? $atomic->value : null;
    }

    /** @psalm-pure */
    private static function className(Union $type): ?string
    {
        $atomic = $type->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject && !$atomic instanceof TGenericObject ? $atomic->value : null;
    }
}
