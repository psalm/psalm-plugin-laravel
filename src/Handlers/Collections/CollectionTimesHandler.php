<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\EnumeratesValues;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
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
 * Declines (null) for a call with a callback (positional, named or unpacked), for any class other
 * than exactly `Collection` or `LazyCollection` (subclasses may reshape the template list), and for
 * every call that is not a static call on a plain class name (instance calls, `$class::times()`,
 * `static::times()`: a template-typed receiver must keep its template).
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

        // Only a literal `Collection::times(...)` / `LazyCollection::times(...)` call. Instance calls,
        // `$class::times()` and `static::times()` can carry a template or late-bound receiver that a
        // plain `Collection<int, int>` answer would replace.
        $stmt = $event->getStmt();
        if (!$stmt instanceof StaticCall || !$stmt->class instanceof Name) {
            return null;
        }

        $resolved = $stmt->class->getAttribute('resolvedName');
        if (!\is_string($resolved)) {
            return null;
        }

        $isCollection = \strcasecmp($resolved, Collection::class) === 0;
        if (!$isCollection && \strcasecmp($resolved, LazyCollection::class) !== 0) {
            return null;
        }

        if (!self::hasNoCallback($stmt->getArgs())) {
            return null;
        }

        $calledClass = $isCollection ? Collection::class : LazyCollection::class;

        // No `&static`: a static call with a callback resolves to the plain class through the stub,
        // and both forms of the same call should print alike.
        return new Union([new TGenericObject($calledClass, [Type::getInt(), Type::getInt()])]);
    }

    /**
     * True when the callback slot (`$callback`, second positional or named) is absent or a literal
     * `null`. An unpacked argument may carry a callback, so it declines.
     *
     * @param array<array-key, Arg> $args
     */
    private static function hasNoCallback(array $args): bool
    {
        $position = 0;
        foreach ($args as $arg) {
            if ($arg->unpack) {
                return false;
            }

            $name = $arg->name?->toString();
            $isCallback = $name === null ? $position === 1 : $name === 'callback';
            if ($name === null) {
                ++$position;
            }

            if ($isCallback && !self::isNullLiteral($arg)) {
                return false;
            }
        }

        return true;
    }

    private static function isNullLiteral(Arg $arg): bool
    {
        return $arg->value instanceof ConstFetch
            && $arg->value->name->toLowerString() === 'null';
    }
}
