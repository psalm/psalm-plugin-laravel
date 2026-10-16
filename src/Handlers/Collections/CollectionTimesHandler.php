<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\EnumeratesValues;
use PhpParser\Node\Expr\ConstFetch;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Types `Collection::times($n)` / `LazyCollection::times($n)` without a callback as `<int, int>`.
 *
 * Laravel's `@return static<int, TTimesValue>` leaves `TTimesValue` unbound when there is no
 * callback, so the stub infers `never` (and `mixed` for a literal `null`), while the runtime
 * value is `range(1, $n)`. A stub-only fix is not possible: a conditional return on `$callback`
 * costs the contextual typing of an untyped closure parameter, and a conditional on the template
 * itself widens a bool-returning callback to `bool|int` (see decisions.md, "`times()` without a
 * callback").
 *
 * Declines (null) for a call with a callback, and for any called class other than exactly
 * `Collection` or `LazyCollection`: subclasses may reshape the template list.
 * `LazyCollection` does not re-declare `times()`, so the declaring class of both calls is the
 * `EnumeratesValues` trait.
 */
final class CollectionTimesHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [EnumeratesValues::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'times') {
            return null;
        }

        $args = $event->getCallArgs();
        if (isset($args[1]) && !self::isNullLiteral($args[1])) {
            return null;
        }

        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        if (\strcasecmp($calledClass, Collection::class) !== 0 && \strcasecmp($calledClass, LazyCollection::class) !== 0) {
            return null;
        }

        // No `&static`: a static call with a callback resolves to the plain class through the stub,
        // and both forms of the same call should print alike.
        return new Union([new TGenericObject($calledClass, [Type::getInt(), Type::getInt()])]);
    }

    private static function isNullLiteral(\PhpParser\Node\Arg $arg): bool
    {
        return $arg->value instanceof ConstFetch
            && !$arg->unpack
            && !$arg->name instanceof \PhpParser\Node\Identifier
            && $arg->value->name->toLowerString() === 'null';
    }
}
