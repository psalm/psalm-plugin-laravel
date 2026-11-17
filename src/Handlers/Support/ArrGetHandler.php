<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Arr;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Issues\ArrKeyNotFound;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * Narrows `Arr::get($array, $key, $default = null)` and `Arr::pull(&$array, $key, $default = null)`
 * when `$array` is a single known, sealed array shape (`TKeyedArray` with `fallback_params === null`)
 * and `$key` is a string literal, mirroring `Arr::get()`'s actual resolution order: the WHOLE key is
 * checked via `exists()` before any dot-split, then dot segments are walked one at a time
 * (`Illuminate\Support\Arr::get()`). `Arr::pull()` is `get()` followed by `forget()`, so it shares the
 * lookup.
 *
 * `Arr::get()` and `Arr::pull()` are not stubbed (no `Arr.phpstub` under `stubs/` declares them),
 * so without this handler Psalm falls back to reflection's plain `@return mixed`.
 *
 * Two more things ride on the same lookup:
 *
 *  - After `Arr::pull($data, 'k')` the by-reference `$data` loses the key. Psalm itself overwrites the
 *    variable with the declared `array` right after the arguments are analysed, so
 *    {@see self::afterMethodCallAnalysis()} runs later and restores a shape. It handles a plain
 *    variable and a literal top-level key only; a dot key (`forget()` unsets a nested leaf) and every
 *    other receiver keep Psalm's `array`.
 *  - With `findArrKeyNotFound` on, a key that definitely cannot exist is reported as
 *    {@see ArrKeyNotFound}, for both methods.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1387
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2345
 * @internal
 */
final class ArrGetHandler implements MethodReturnTypeProviderInterface, AfterMethodCallAnalysisInterface
{
    private const PULL_METHOD_ID = Arr::class . '::pull';

    /** Parameter names of `get()` and `pull()`, by position (both share them). */
    private const PARAMETERS = ['array', 'key', 'default'];

    private static bool $reportMissingKeys = false;

    public static function init(bool $reportMissingKeys): void
    {
        self::$reportMissingKeys = $reportMissingKeys;
    }

    public static function reset(): void
    {
        self::$reportMissingKeys = false;
    }

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Arr::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $methodName = $event->getMethodNameLowercase();
        if ($methodName !== 'get' && $methodName !== 'pull') {
            return null;
        }

        $args = $event->getCallArgs();
        if (\count($args) < 2 || !self::usesParameterOrder($args)) {
            // Missing the required $key argument, or named/unpacked arguments that
            // would make the positions below mean something else: not a call to narrow.
            return null;
        }

        $source = $event->getSource();
        $nodeTypeProvider = $source->getNodeTypeProvider();

        $atomic = self::asSealedKeyedArray($nodeTypeProvider->getType($args[0]->value));
        if (!$atomic instanceof TKeyedArray) {
            return null;
        }

        $key = self::literalKey($nodeTypeProvider->getType($args[1]->value));
        if ($key === null) {
            return null;
        }

        $lookup = self::lookup($atomic, $key);
        if (!$lookup instanceof ArrKeyLookup) {
            return null;
        }

        if ($lookup->isDefinitelyAbsent() && self::$reportMissingKeys) {
            IssueBuffer::accepts(
                new ArrKeyNotFound(
                    "Key '{$key}' does not exist in {$atomic->getId()}, so Arr::{$methodName}() always returns its default",
                    new CodeLocation($source, $args[1]->value),
                ),
                $source->getSuppressedIssues(),
            );
        }

        $defaultType = null;
        if (isset($args[2])) {
            $defaultType = $nodeTypeProvider->getType($args[2]->value);
            if (!$defaultType instanceof Union) {
                return null;
            }

            foreach ($defaultType->getAtomicTypes() as $defaultAtomic) {
                if ($defaultAtomic instanceof TClosure || $defaultAtomic instanceof TCallable) {
                    // Laravel's value() helper invokes a Closure default (and a plain
                    // `callable` type may resolve to one at runtime) — its return type
                    // isn't reliably known here, so defer to the stub's default.
                    return null;
                }
            }
        }

        $default = $defaultType ?? Type::getNull();

        if (!$lookup->value instanceof \Psalm\Type\Union) {
            return $default;
        }

        if ($lookup->maybeAbsent) {
            return Type::combineUnionTypes($lookup->value, $default, $source->getCodebase());
        }

        return $lookup->value;
    }

    /**
     * Runs after Psalm has overwritten the by-reference `$array` argument with the parameter's `array`.
     * The pre-call shape is still on the argument node, so the post-call shape is rebuilt from it.
     */
    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        // Fires for every analysed method call: bail on the cheapest check first.
        if (\strcasecmp($event->getDeclaringMethodId(), self::PULL_METHOD_ID) !== 0) {
            return;
        }

        $expr = $event->getExpr();
        if ($expr->isFirstClassCallable()) {
            return;
        }

        $args = $expr->getArgs();
        if (\count($args) < 2 || !self::usesParameterOrder($args)) {
            return;
        }

        $variable = $args[0]->value;
        // Only a plain variable: a property or array-item receiver is also written back to its
        // owner by Psalm, and narrowing a typed property below its declared type is not modelled here.
        if (!$variable instanceof Variable || !\is_string($variable->name)) {
            return;
        }

        // The arg-0 node type is recorded before later args are analysed, so an arg that can write
        // the variable (`Arr::pull($d, 'a', Arr::pull($d, 'b'))`, `..., $d = []`) would make the
        // rebuilt shape stale. Only args that cannot have side effects keep it valid.
        foreach (\array_slice($args, 1) as $laterArg) {
            if (!self::isSideEffectFree($laterArg->value)) {
                return;
            }
        }

        $varId = '$' . $variable->name;
        $context = $event->getContext();
        $current = $context->vars_in_scope[$varId] ?? null;
        if (!$current instanceof Union || self::isReference($context, $varId)) {
            return;
        }

        $nodeTypeProvider = $event->getStatementsSource()->getNodeTypeProvider();

        $before = $nodeTypeProvider->getType($variable);
        $atomic = self::asSealedKeyedArray($before);
        $key = self::literalKey($nodeTypeProvider->getType($args[1]->value));
        if (!$before instanceof Union || !$atomic instanceof TKeyedArray || $key === null) {
            return;
        }

        $after = self::withoutKey($before, $atomic, $key);
        if (!$after instanceof Union) {
            return;
        }

        // Keep the data-flow parents Psalm attached to the out type.
        $context->vars_in_scope[$varId] = $after->setParentNodes($current->parent_nodes);
    }

    /**
     * What `Arr::forget($array, $key)` leaves behind, or null when it cannot be told. `forget()` checks the
     * whole key with `exists()` first and unsets it, so a present key (optional or not) is simply gone; only
     * an optional key that contains a dot may instead fall through to the dot walk. An absent key without a
     * dot is a no-op, while an absent dotted key may unset a nested leaf, which is not modelled.
     *
     * @psalm-pure
     */
    private static function withoutKey(Union $before, TKeyedArray $atomic, string $key): ?Union
    {
        $hasDot = \str_contains($key, '.');

        if (!\array_key_exists($key, $atomic->properties)) {
            return $hasDot ? null : $before;
        }

        if ($hasDot && $atomic->properties[$key]->possibly_undefined) {
            return null;
        }

        $properties = $atomic->properties;
        unset($properties[$key]);

        if ($properties === []) {
            return Type::getEmptyArray();
        }

        return new Union([$atomic->setProperties($properties)]);
    }

    /**
     * @psalm-capabilities read-props
     */
    private static function isSideEffectFree(Expr $expr): bool
    {
        return $expr instanceof Scalar\String_
            || $expr instanceof Scalar\Int_
            || $expr instanceof Scalar\Float_
            || $expr instanceof ConstFetch
            || $expr instanceof Variable && \is_string($expr->name)
            || $expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier;
    }

    /**
     * A variable bound by `=&`, `global`, `static`, or a by-reference parameter shares storage with
     * something Psalm tracks separately, so rewriting only its own entry could desynchronise the two.
     *
     * @psalm-mutation-free
     */
    private static function isReference(Context $context, string $varId): bool
    {
        return isset($context->references_in_scope[$varId])
            || isset($context->references_to_external_scope[$varId])
            || isset($context->references_possibly_bound_to[$varId])
            || \in_array($varId, $context->references_in_scope, true);
    }

    /**
     * Positional use, or named arguments that sit at their own parameter's position. Anything else
     * (reordered names, unpacking) would make `$args[0]` something other than `$array`.
     *
     * @param array<Arg> $args
     * @psalm-capabilities read-props
     */
    private static function usesParameterOrder(array $args): bool
    {
        foreach (\array_values($args) as $position => $arg) {
            if ($arg->unpack) {
                return false;
            }

            if ($arg->name !== null && $arg->name->name !== (self::PARAMETERS[$position] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Also declines a null or int-literal $key: neither is a string literal, and
     * `Arr::get(..., null)` returns the whole array, not worth narrowing.
     *
     * @psalm-pure
     */
    private static function literalKey(mixed $keyType): ?string
    {
        if (!$keyType instanceof Union || !$keyType->isSingleStringLiteral()) {
            return null;
        }

        return $keyType->getSingleStringLiteral()->value;
    }

    /**
     * Where the key lands, or null when that cannot be told.
     *
     * Arr::get() checks the WHOLE key via exists() BEFORE ever splitting on '.',
     * so a literal key that itself contains a dot must win over the segment walk —
     * but only when it's guaranteed present. If it's merely optional AND contains a
     * dot, Arr::exists() can return false for it at runtime, falling through into the
     * dot-split walk instead of $default; that walk may resolve to a type this
     * shortcut never sees, so decline rather than guess at the union.
     *
     * @psalm-pure
     */
    private static function lookup(TKeyedArray $atomic, string $key): ?ArrKeyLookup
    {
        if (\array_key_exists($key, $atomic->properties)) {
            $prop = $atomic->properties[$key];
            if ($prop->possibly_undefined && \str_contains($key, '.')) {
                return null;
            }

            return new ArrKeyLookup($prop->setPossiblyUndefined(false), $prop->possibly_undefined);
        }

        if (!\str_contains($key, '.')) {
            return new ArrKeyLookup(null, true);
        }

        return self::walkDotPath($atomic, \explode('.', $key));
    }

    /**
     * Only a single-atomic, sealed `TKeyedArray` (no `fallback_params`, which would
     * admit unknown extra keys at runtime) is narrowed. A plain `TArray<K, V>` carries
     * no literal keys, and a multi-atomic union would force flattening shapes that
     * disagree on which keys exist.
     *
     * @psalm-pure
     */
    private static function asSealedKeyedArray(mixed $type): ?TKeyedArray
    {
        if (!$type instanceof Union) {
            return null;
        }

        $atomics = $type->getAtomicTypes();
        if (\count($atomics) !== 1) {
            // Defensive by design, not merely untested: reachable via a mixed-type union
            // (e.g. array{a: int}|string), where Psalm's combiner leaves the atomics
            // separate. Declines rather than guessing which atomic is the real shape.
            return null;
        }

        $atomic = \reset($atomics);
        if (!$atomic instanceof TKeyedArray || $atomic->fallback_params !== null) {
            return null;
        }

        return $atomic;
    }

    /**
     * Walk dot-separated segments the way `Arr::get()` does: descend into each nested
     * shape, landing on "absent" the moment a segment is missing. An intermediate
     * segment that is itself optional (`possibly_undefined`) can be absent at runtime
     * without failing the `array_key_exists()` check the loop performs on the CURRENT
     * level, so its absence surfaces as $default there too: the final value is flagged
     * `maybeAbsent` and the walk keeps going, matching Laravel's foreach exactly.
     *
     * @param non-empty-list<string> $segments
     * @psalm-pure
     */
    private static function walkDotPath(TKeyedArray $atomic, array $segments): ?ArrKeyLookup
    {
        $current = $atomic;
        $sawOptionalSegment = false;
        $lastIndex = \count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if (!\array_key_exists($segment, $current->properties)) {
                return new ArrKeyLookup(null, true);
            }

            $propType = $current->properties[$segment];
            if ($propType->possibly_undefined) {
                $sawOptionalSegment = true;
            }

            if ($i === $lastIndex) {
                return new ArrKeyLookup($propType->setPossiblyUndefined(false), $sawOptionalSegment);
            }

            $next = self::asSealedKeyedArray($propType);
            if (!$next instanceof TKeyedArray) {
                // Not a further-describable shape (plain TArray, unsealed, union, or a
                // scalar) but segments remain — can't prove whether the remaining path
                // exists at runtime.
                return null;
            }

            $current = $next;
        }

        // Unreachable: the loop always returns on its last iteration.
        return null;
    }
}
