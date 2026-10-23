<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * `->withPivot('role')` adds the selected columns to TPivotModel: `BelongsToMany<User, Team, Pivot&object{role: string}, 'pivot'>`,
 * so a declaration carrying that shape is accepted (#1944). The stub cannot express it: the
 * columns are call arguments, and the column types come from the pivot table's migration.
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
        if ($event->getMethodNameLowercase() !== 'withpivot' || !$stmt instanceof MethodCall) {
            return null;
        }

        $columns = PivotColumnResolver::columnsFromArgs($stmt->args);
        if ($columns === []) {
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

        $table = self::factoryTable($event, $stmt->var, $relation);
        if ($table === false) {
            return null;
        }

        $pivot = PivotColumnResolver::refine(
            $relation->type_params[2]->getSingleAtomic(),
            PivotColumnResolver::columnTypes(
                $event->getSource()->getCodebase(),
                $table,
                $columns,
                self::className($relation->type_params[1]),
            ),
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
     * The pivot table of the `$this->belongsToMany(...)` call the chain starts from; null when the
     * chain starts elsewhere (a delegated relation method, a parameter).
     */
    private static function factoryTable(MethodReturnTypeProviderEvent $event, Expr $expr, TGenericObject $relation): string|false|null
    {
        while ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
            $name = \strtolower($expr->name->name);
            if (isset(PivotColumnResolver::FACTORY_TABLE_POSITION[$name]) && $expr->var instanceof Expr\Variable && $expr->var->name === 'this') {
                return PivotColumnResolver::table(
                    $event->getSource()->getCodebase(),
                    $expr,
                    $name,
                    self::className($relation->type_params[0]),
                    self::className($relation->type_params[1]),
                );
            }

            $expr = $expr->var;
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function className(Union $type): ?string
    {
        $atomic = $type->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject && !$atomic instanceof TGenericObject ? $atomic->value : null;
    }
}
