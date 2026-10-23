<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser\Node\Expr\MethodCall;
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
 * `withTimestamps()` adds its created/updated columns the same way.
 *
 * When every link down to the `$this->belongsToMany(...)` call is known to keep the relation, all calls of the
 * chain are read again on each of these two methods, so the result does not depend on their order: the date
 * columns need `created_at` selected anywhere ({@see PivotColumnResolver::columnTypes()}).
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
        if (!\in_array($method, ['withpivot', 'withtimestamps'], true) || !$stmt instanceof MethodCall) {
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

        $codebase = $event->getSource()->getCodebase();
        $parent = self::className($relation->type_params[1]);
        $chain = PivotColumnResolver::readChain($codebase, $stmt, $parent, self::className($relation->type_params[0]));
        if ($chain['table'] === false) {
            return null;
        }

        // Only a visible chain is read whole, so the result does not depend on call order (`created_at` may be
        // selected after `withTimestamps()`). Elsewhere calls before this one may have been replaced.
        $columns = $chain['visible'] ? $chain['columns'] : PivotColumnResolver::callColumns($codebase, $stmt, $method, $parent);
        if ($columns === []) {
            return null;
        }

        $slot = $relation->type_params[2]->getSingleAtomic();
        $pivot = PivotColumnResolver::refine(
            // A visible chain is rebuilt whole: the slot's shape would only keep columns typed before it was.
            $chain['visible'] && $slot instanceof TNamedObject ? new TNamedObject($slot->value) : $slot,
            PivotColumnResolver::columnTypes($codebase, $chain['table'], $columns, $parent, $chain['visible']),
        );
        if (!$pivot instanceof TNamedObject) {
            return null;
        }

        // A copy of the receiver keeps what Psalm already expanded on it (`&static`, other slots).
        $params = $relation->type_params;
        $params[2] = new Union([$pivot]);

        return new Union([$relation->setTypeParams($params)]);
    }

    /** @psalm-pure */
    private static function className(Union $type): ?string
    {
        $atomic = $type->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject && !$atomic instanceof TGenericObject ? $atomic->value : null;
    }
}
