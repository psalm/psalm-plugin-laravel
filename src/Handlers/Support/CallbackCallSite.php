<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

/**
 * What the per-call-site closure params handlers ({@see ConditionableCallbackParamsHandler},
 * {@see CollectionCallbackParamsHandler}) share: a params provider event carries no call node, so
 * the handlers stash the call in `beforeExpressionAnalysis` and read the receiver and the closure
 * literal's declared param types back here.
 *
 * @internal
 */
final class CallbackCallSite
{
    /**
     * Calls awaiting their params lookup, keyed by the call's first Arg (the only call-identifying
     * object the provider event exposes). Both ends are weak: the call owns its Arg, so a strong
     * value would keep the key alive and the entry would outlive its AST.
     *
     * @psalm-var \WeakMap<Arg, \WeakReference<MethodCall|NullsafeMethodCall>>|null
     */
    private static ?\WeakMap $calls = null;

    /**
     * Declared closure-literal param types by literal node (null: decline the slot).
     *
     * @psalm-var \WeakMap<Closure|ArrowFunction, array<int, Union>|null>|null
     */
    private static ?\WeakMap $declaredTypes = null;

    public static function reset(): void
    {
        self::$calls = null;
        self::$declaredTypes = null;
    }

    /** Stashes a call that has at least one arg under its first Arg. */
    public static function remember(MethodCall|NullsafeMethodCall $call): void
    {
        $args = $call->getArgs();
        if ($args === []) {
            return;
        }

        if (!self::$calls instanceof \WeakMap) {
            /** @psalm-var \WeakMap<Arg, \WeakReference<MethodCall|NullsafeMethodCall>> $fresh */
            $fresh = new \WeakMap();
            self::$calls = $fresh;
        }

        self::$calls->offsetSet($args[0], \WeakReference::create($call));
    }

    /** The stashed call for a first Arg; null on a miss (static or forwarded call). */
    public static function callOf(Arg $firstArg): MethodCall|NullsafeMethodCall|null
    {
        return (self::$calls[$firstArg] ?? null)?->get();
    }

    /**
     * The arg's Closure/ArrowFunction literal, unless its first param is variadic.
     *
     * @psalm-mutation-free
     */
    public static function closureLiteral(?Arg $arg): Closure|ArrowFunction|null
    {
        $value = $arg?->value;
        if (!$value instanceof Closure && !$value instanceof ArrowFunction) {
            return null;
        }

        return isset($value->params[0]) && $value->params[0]->variadic ? null : $value;
    }

    /**
     * The receiver as the callback will see it: exactly one object atomic of the dispatched class
     * (null dropped for `?->`), else null. `$this` in a trait is `Host&static`; a final host has
     * no subclass, and Psalm reads the expected param as the plain host, so `&static` is dropped.
     */
    public static function receiver(
        StatementsAnalyzer $source,
        MethodCall|NullsafeMethodCall $call,
        string $dispatchedClass,
    ): ?TNamedObject {
        $type = $source->getNodeTypeProvider()->getType($call->var);
        if (!$type instanceof Union) {
            return null;
        }

        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNull) {
                $atomics[] = $atomic;
            }
        }

        if (\count($atomics) !== 1) {
            return null;
        }

        $atomic = $atomics[0];
        if (!$atomic instanceof TNamedObject || \strtolower($atomic->value) !== \strtolower($dispatchedClass)) {
            return null;
        }

        if (!$atomic->is_static) {
            return $atomic;
        }

        try {
            $final = $source->getCodebase()->classlike_storage_provider->get($dispatchedClass)->final;
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        return $final ? $atomic->setIsStatic(false) : $atomic;
    }

    /**
     * Native or docblock param types a closure literal declares, by offset, or null to decline
     * the slot: a late-bound type (`self`/`static`/`parent`, at any depth) is declared, or the
     * closure storage is missing. Memoized on first sight: ArgumentsAnalyzer later overwrites the
     * storage param type with the inferred one (marking it `type_inferred`), so a loop's second
     * pass would no longer see the declaration.
     *
     * @return array<int, Union>|null
     */
    public static function declaredParamTypes(
        StatementsAnalyzer $source,
        Codebase $codebase,
        Closure|ArrowFunction $literal,
    ): ?array {
        if (!self::$declaredTypes instanceof \WeakMap) {
            /** @psalm-var \WeakMap<Closure|ArrowFunction, array<int, Union>|null> $fresh */
            $fresh = new \WeakMap();
            self::$declaredTypes = $fresh;
        }

        $memo = self::$declaredTypes;
        if ($memo->offsetExists($literal)) {
            return $memo->offsetGet($literal);
        }

        $types = self::readDeclaredParamTypes($source, $codebase, $literal);
        $memo->offsetSet($literal, $types);

        return $types;
    }

    /** @return array<int, Union>|null */
    private static function readDeclaredParamTypes(
        StatementsAnalyzer $source,
        Codebase $codebase,
        Closure|ArrowFunction $literal,
    ): ?array {
        // Same id scheme as Psalm's closure-param inference (ArgumentsAnalyzer).
        $closureId = \strtolower($source->getFilePath())
            . ':' . $literal->getLine()
            . ':' . (int) $literal->getAttribute('startFilePos')
            . ':-:closure';

        try {
            $storage = $codebase->getClosureStorage($source->getFilePath(), $closureId);
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return null;
        }

        $types = [];
        foreach ($storage->params as $offset => $param) {
            if (!$param->type instanceof Union || $param->type_inferred) {
                continue;
            }

            if (self::containsLateBound($param->type)) {
                return null;
            }

            $types[$offset] = $param->type;
        }

        return $types;
    }

    /**
     * Whether `self`/`static`/`parent` appears anywhere in the type, generics included. Closure
     * storage keeps these unexpanded, and Psalm never matches them against the host type.
     */
    private static function containsLateBound(Union $type): bool
    {
        $visitor = new class extends TypeVisitor {
            /** @psalm-pure */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                $lateBound = $type instanceof TNamedObject
                    && \in_array(\strtolower($type->value), ['self', 'static', 'parent'], true);

                return $lateBound ? self::STOP_TRAVERSAL : null;
            }
        };

        // traverse() returns false exactly when enterNode() stopped the traversal.
        return !$visitor->traverse($type);
    }
}
