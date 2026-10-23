<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
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

        $codebase = $event->getSource()->getCodebase();
        $parent = self::className($relation->type_params[1]);
        $chain = PivotColumnResolver::readChain(
            $codebase,
            $stmt,
            $parent,
            self::className($relation->type_params[0]),
            static fn(MethodCall $using): ?string => self::classLiteral($event, $using),
        );
        // `using()` has no columns of its own, and an unseen chain may carry one that moves the date casts.
        if ($chain['columns'] === [] || $chain['table'] === false || $method === 'using' && !$chain['visible']) {
            return null;
        }

        // Later calls see the custom class `using()` left in the slot.
        $slot = $relation->type_params[2]->getSingleAtomic();
        if ($method !== 'using' && $chain['using'] !== null && (!$slot instanceof TNamedObject || \strcasecmp($slot->value, $chain['using']) !== 0)) {
            return null;
        }

        $types = PivotColumnResolver::columnTypes(
            $codebase,
            $chain['table'],
            $chain['columns'],
            $parent,
            $chain['using'],
            $chain['visible'],
        );
        $pivot = $chain['using'] !== null
            ? PivotColumnResolver::shapeOn($chain['using'], $types)
            // A visible chain is rebuilt whole: the slot's shape would only keep columns typed before it was.
            : PivotColumnResolver::refine($chain['visible'] && $slot instanceof TNamedObject ? new TNamedObject($slot->value) : $slot, $types);
        if (!$pivot instanceof TNamedObject) {
            return null;
        }

        // A copy of the receiver keeps what Psalm already expanded on it (`&static`, other slots).
        $params = $relation->type_params;
        $params[2] = new Union([$pivot]);

        return new Union([$relation->setTypeParams($params)]);
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
