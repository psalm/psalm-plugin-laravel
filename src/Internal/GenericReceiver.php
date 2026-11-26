<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Answers a generic object over a receiver's type arguments without losing Psalm's star projection.
 *
 * A `*` type argument (`Collection<*, Post>`) is a marker on the receiver's atomic. It is not part of
 * `type_params`, which holds only the bound of that slot, and it has no public getter or setter: the one public
 * operation that keeps it is `TGenericObject::setTypeParams()` on the atomic that carries it. So
 * `new TGenericObject($class, $receiver->type_params)` turns "some unknown type" into "exactly the bound" and
 * accepts the writes the receiver rejects.
 *
 * @internal
 */
final class GenericReceiver
{
    /**
     * The generic atomic of $class a method return type provider is answering for, or null (a static call, a
     * non-generic or template receiver).
     *
     * Psalm calls the provider once per atomic of the receiver and hands over that atomic's bare type arguments,
     * so the atomic is found again in the node type of the receiver expression, by those very arguments: a union
     * may hold the class twice (`Collection<*, A>|Collection<int, B>`). Without arguments on the event it is the
     * first atomic of the class.
     */
    public static function of(MethodReturnTypeProviderEvent $event, string $class): ?TGenericObject
    {
        $stmt = $event->getStmt();
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        $type = $event->getSource()->getNodeTypeProvider()->getType($stmt->var);
        if (!$type instanceof Union) {
            return null;
        }

        $class = \strtolower($class);
        $typeParams = $event->getTemplateTypeParameters();

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TGenericObject
                && \strtolower($atomic->value) === $class
                && ($typeParams === null || $atomic->type_params === $typeParams)
            ) {
                return $atomic;
            }
        }

        return null;
    }

    /**
     * `$class<$typeParams>` as a plain object (not late static, no intersection), keeping the `*` of every slot
     * that is handed the receiver's own argument.
     *
     * Pass-through is by identity: a slot keeps its marker only when it receives the very Union of
     * `$receiver->type_params`. `setTypeParams()` alone compares by equality, so a slot the handler computed
     * afresh (the `mixed` a proxied call returns, an `array-key` group key) would stay a `*` whenever it happens
     * to equal the bound.
     *
     * Without a marker to keep (no receiver, another class or arity, no `*`, a Psalm without star projections)
     * the answer is the newly built object, exactly as before.
     *
     * @param non-empty-list<Union> $typeParams
     * @psalm-pure
     */
    public static function rebuild(?TGenericObject $receiver, string $class, array $typeParams): TGenericObject
    {
        $rebuilt = new TGenericObject($class, $typeParams);

        // Markers are kept per offset of the receiver's own class: another class or arity has no slot for them.
        if (!$receiver instanceof TGenericObject
            || \strtolower($receiver->value) !== \strtolower($rebuilt->value)
            || \count($receiver->type_params) !== \count($typeParams)
        ) {
            return $rebuilt;
        }

        $cleared = $receiver->type_params;
        foreach ($typeParams as $offset => $typeParam) {
            if ($typeParam !== $cleared[$offset]) {
                $cleared[$offset] = $cleared[$offset]->isNever() ? Type::getMixed() : Type::getNever();
            }
        }

        $kept = $receiver
            ->setTypeParams($cleared)
            ->setTypeParams($typeParams)
            ->setValueIsStatic($rebuilt->value, false, false)
            ->setIntersectionTypes([])
            ->setFromDocblock(false);

        // Equal types mean no marker survived.
        return $kept->equals($rebuilt, false) ? $rebuilt : $kept;
    }
}
