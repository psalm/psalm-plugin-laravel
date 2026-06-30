<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Traits\Conditionable;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
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
 *
 * That union is a deliberate, sound over-approximation: a falsy `$value` (or a `null`
 * callback result) still yields `$this` at runtime, so the receiver always belongs in the
 * result. {@see self::narrowsBeyondReceiver()} defines what counts as a genuine narrowing.
 * Only the two-argument `when($value, $callback)` form is inspected; the 0/1-arg
 * `HigherOrderWhenProxy` branch and the `$default` argument defer to the stub's `$this`.
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

        // Need at least the callback ($args[1]); the 0/1-arg proxy branch defers to the stub.
        $args = $event->getCallArgs();
        if (\count($args) < 2) {
            return null;
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

        $callbackReturn = self::callableReturn($source, $args[1]->value);
        if (! $callbackReturn instanceof Union) {
            return null; // callback return undeterminable (e.g. string callable) — defer to stub
        }

        $receiverClasses = self::classNames($receiver);
        $codebase = $source->getCodebase();

        $extra = [];
        foreach ($callbackReturn->getAtomicTypes() as $atomic) {
            if (self::narrowsBeyondReceiver($atomic, $receiver, $receiverClasses, $codebase)) {
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
     *  - the same class as the receiver, regardless of template args — the fluent case; unioning
     *    a sibling generic (`Builder<Model>` beside `Builder<Customer>`) only widens the type and
     *    breaks variance against a declared return;
     *  - anything already contained by the receiver type.
     *
     * @param array<string, true> $receiverClasses lowercased receiver class names
     */
    private static function narrowsBeyondReceiver(
        Atomic $atomic,
        Union $receiver,
        array $receiverClasses,
        Codebase $codebase,
    ): bool {
        if ($atomic instanceof TNull || $atomic instanceof TVoid || $atomic instanceof TNever || $atomic instanceof TMixed) {
            return false;
        }

        if ($atomic instanceof TNamedObject && isset($receiverClasses[\strtolower($atomic->value)])) {
            return false;
        }

        return ! UnionTypeComparator::isContainedBy($codebase, new Union([$atomic]), $receiver);
    }

    /**
     * Lowercased class names of the receiver's object atomics, as a set.
     *
     * @return array<string, true>
     * @psalm-mutation-free
     */
    private static function classNames(Union $receiver): array
    {
        $names = [];
        foreach ($receiver->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject) {
                $names[\strtolower($atomic->value)] = true;
            }
        }

        return $names;
    }

    /**
     * Extract the return type of a callable argument from its inferred type.
     *
     * Returns null when any atomic is a callable without a known return type (or the arg
     * is not callable at all) — the caller then defers to the stub rather than guess.
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
