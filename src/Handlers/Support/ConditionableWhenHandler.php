<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Traits\Conditionable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNever;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\Union;

/**
 * Narrows {@see Conditionable::when()} / {@see Conditionable::unless()} to the callback's
 * return type when it carries information the receiver does not — the Option B follow-up
 * to the `@return $this` stub (#993).
 *
 * The stub alone already types the common case: a callback returning void / null / the
 * receiver type collapses to `$this` via Laravel's `$callback($this, $value) ?? $this`.
 * This handler adds only the remaining gap — a callback returning a genuinely different
 * type (e.g. `when($v, fn(): int => ...)`) — producing `<receiver>&static | <callback-return>`.
 * The `$default` callback runs on the other branch (`$default($this, $value) ?? $this`), so its
 * return joins the union too. Both are bound by name or position.
 *
 * That union is a deliberate, sound over-approximation: a falsy `$value` (or a `null`
 * callback result) still yields `$this` at runtime, so the receiver always belongs in the
 * result. {@see self::narrowsBeyondReceiver()} defines what counts as a genuine narrowing.
 * The 0/1-arg `HigherOrderWhenProxy` branch defers to the stub's `$this`.
 *
 * Registered on the `Conditionable` trait: Psalm dispatches a method return-type provider on
 * the declaring class, which for a trait method is the trait, so one registration covers every
 * host (Builder, Collection, Stringable, Request, user classes). The precise receiver type
 * (with template params) is read from the call's left-hand side via the node type provider.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/993
 */
final class ConditionableWhenHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // The declaring class of a trait method is the trait, and Psalm dispatches on it,
        // so this single entry covers every class that uses Conditionable.
        return [Conditionable::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        if ($method !== 'when' && $method !== 'unless') {
            return null;
        }

        // Need the callback; the 0/1-arg proxy branch defers to the stub. An unpack at or before the
        // callback's position makes its binding ambiguous.
        $args = $event->getCallArgs();
        $callbackArg = ArgUtil::boundTo($args, 'callback', 1);
        if (! $callbackArg instanceof Arg) {
            return null;
        }

        foreach ($args as $offset => $arg) {
            if ($arg->unpack && $offset <= 1) {
                return null;
            }
        }

        $source = $event->getSource();
        if (! $source instanceof StatementsAnalyzer) {
            return null;
        }

        $stmt = $event->getStmt();
        if (! $stmt instanceof MethodCall) {
            return null;
        }

        $receiver = $source->getNodeTypeProvider()->getType($stmt->var);
        if (! $receiver instanceof Union) {
            return null;
        }

        $callbackReturn = self::callableReturn($source, $callbackArg->value);
        if (! $callbackReturn instanceof Union) {
            return null; // callback return undeterminable (e.g. string callable) — defer to stub
        }

        // A default that is absent or a literal `null` adds nothing; one of unknown type (including
        // a trailing unpack that may bind it) declines.
        $defaultReturn = self::defaultReturn($source, ArgUtil::boundTo($args, 'default', 2));
        if ($defaultReturn === false) {
            return null;
        }

        $codebase = $source->getCodebase();

        $extra = [];
        foreach ([...$callbackReturn->getAtomicTypes(), ...($defaultReturn?->getAtomicTypes() ?? [])] as $atomic) {
            if (self::narrowsBeyondReceiver($atomic, $receiver, $codebase)) {
                $extra[] = $atomic;
            }
        }

        // Nothing new beyond the receiver → let the stub's `@return $this` (rendered as
        // `<receiver>&static`) stand, keeping every receiver-only call byte-identical.
        if ($extra === []) {
            return null;
        }

        $receiverStatic = [];
        foreach ($receiver->getAtomicTypes() as $atomic) {
            $receiverStatic[] = $atomic instanceof TNamedObject ? $atomic->setIsStatic(true) : $atomic;
        }

        return new Union([...$receiverStatic, ...$extra]);
    }

    /**
     * Does this callback-return atomic carry information the receiver does not?
     *
     * Everything excluded here collapses to `$this` at runtime or would pollute the type:
     *  - null / void / never — `$callback(...) ?? $this` yields `$this`;
     *  - mixed — an untyped callback; unioning it discards the stub's `$this` and reintroduces
     *    the mixed leak #704 fixed;
     *  - anything already contained by the receiver type (the fluent case);
     *  - a same-class atomic that merely widens the receiver's generics (`Builder<Model>` beside
     *    `Builder<Customer>`, what a `Builder $q` param hands back): unioning it only widens the
     *    type and breaks variance against a declared return. A same-class atomic with
     *    incomparable generics (`Collection<string, string>` beside `Collection<int, int>`) is a
     *    different value at runtime and is kept.
     */
    private static function narrowsBeyondReceiver(Atomic $atomic, Union $receiver, Codebase $codebase): bool
    {
        if ($atomic instanceof TNull || $atomic instanceof TVoid || $atomic instanceof TNever || $atomic instanceof TMixed) {
            return false;
        }

        if (UnionTypeComparator::isContainedBy($codebase, new Union([$atomic]), $receiver)) {
            return false;
        }

        return ! $atomic instanceof TNamedObject || ! self::widensReceiver($codebase, $atomic, $receiver);
    }

    /**
     * Whether the atomic is a receiver class whose every type param is comparable (one contains
     * the other) with the receiver's: the same value seen through wider generics, not another one.
     */
    private static function widensReceiver(Codebase $codebase, TNamedObject $atomic, Union $receiver): bool
    {
        foreach ($receiver->getAtomicTypes() as $candidate) {
            if (! $candidate instanceof TNamedObject || \strcasecmp($candidate->value, $atomic->value) !== 0) {
                continue;
            }

            $own = $atomic instanceof TGenericObject ? $atomic->type_params : [];
            $theirs = $candidate instanceof TGenericObject ? $candidate->type_params : [];
            if (\count($own) !== \count($theirs)) {
                return $own === [] || $theirs === [];
            }

            foreach ($own as $position => $param) {
                if (! UnionTypeComparator::isContainedBy($codebase, $param, $theirs[$position])
                    && ! UnionTypeComparator::isContainedBy($codebase, $theirs[$position], $param)
                ) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The `$default` arg's callback return: null for an absent or literal-null arg, false when it
     * cannot be determined (an unpack, a non-callable or a callable without a known return).
     *
     * @psalm-capabilities read-props
     */
    private static function defaultReturn(StatementsAnalyzer $source, ?Arg $arg): Union|false|null
    {
        if (! $arg instanceof Arg) {
            return null;
        }

        if ($arg->unpack) {
            return false;
        }

        $type = $source->getNodeTypeProvider()->getType($arg->value);
        if ($type instanceof Union && $type->isNull()) {
            return null;
        }

        return self::callableReturn($source, $arg->value) ?? false;
    }

    /**
     * Extract the return type of a callable argument from its inferred type.
     *
     * Returns null when any atomic is a callable without a known return type (or the arg
     * is not callable at all) — the caller then defers to the stub rather than guess.
     *
     * @psalm-pure
     */
    private static function callableReturn(StatementsAnalyzer $source, \PhpParser\Node\Expr $expr): ?Union
    {
        $type = $source->getNodeTypeProvider()->getType($expr);
        if (! $type instanceof Union) {
            return null;
        }

        $returns = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            // A passed closure literal infers as TClosure (extends TNamedObject); a `callable`
            // type infers as TCallable. Both carry their inferred return type via CallableTrait.
            if (! $atomic instanceof TClosure && ! $atomic instanceof TCallable) {
                return null;
            }

            if (! $atomic->return_type instanceof Union) {
                return null;
            }

            foreach ($atomic->return_type->getAtomicTypes() as $returnAtomic) {
                $returns[] = $returnAtomic;
            }
        }

        return new Union($returns);
    }
}
