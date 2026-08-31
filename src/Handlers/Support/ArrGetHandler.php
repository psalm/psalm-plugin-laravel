<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Arr;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * Narrows `Arr::get($array, $key, $default = null)` when `$array` is a single known,
 * sealed array shape (`TKeyedArray` with `fallback_params === null`) and `$key` is a
 * string literal, mirroring `Arr::get()`'s actual resolution order: the WHOLE key is
 * checked via `exists()` before any dot-split, then dot segments are walked one at a
 * time (`Illuminate\Support\Arr::get()`).
 *
 * `Arr::get()` is not stubbed (`stubs/common/Support/Arr.phpstub` is an empty class),
 * so without this handler Psalm falls back to reflection's plain `@return mixed`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1387
 * @internal
 */
final class ArrGetHandler implements MethodReturnTypeProviderInterface
{
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
        if ($event->getMethodNameLowercase() !== 'get') {
            return null;
        }

        $args = $event->getCallArgs();
        if (\count($args) < 2) {
            // Missing the required $key argument — not a valid call to narrow.
            return null;
        }

        $nodeTypeProvider = $event->getSource()->getNodeTypeProvider();

        $atomic = self::asSealedKeyedArray($nodeTypeProvider->getType($args[0]->value));
        if (!$atomic instanceof TKeyedArray) {
            return null;
        }

        $keyType = $nodeTypeProvider->getType($args[1]->value);
        if (!$keyType instanceof Union || !$keyType->isSingleStringLiteral()) {
            // Also declines a null or int-literal $key: neither is a string literal,
            // and Arr::get(..., null) returns the whole array — not worth narrowing.
            return null;
        }

        $key = $keyType->getSingleStringLiteral()->value;

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

        $codebase = $event->getSource()->getCodebase();

        // Arr::get() checks the WHOLE key via exists() BEFORE ever splitting on '.',
        // so a literal key that itself contains a dot must win over the segment walk —
        // but only when it's guaranteed present. If it's merely optional AND contains a
        // dot, Arr::exists() can return false for it at runtime, falling through into the
        // dot-split walk instead of $default; that walk may resolve to a type this
        // shortcut never sees, so decline rather than guess at the union.
        if (\array_key_exists($key, $atomic->properties)) {
            $prop = $atomic->properties[$key];
            if (!$prop->possibly_undefined || !\str_contains($key, '.')) {
                return self::resolveValue($prop, $defaultType, $codebase);
            }

            return null;
        }

        if (!\str_contains($key, '.')) {
            return $defaultType ?? Type::getNull();
        }

        return self::walkDotPath($atomic, \explode('.', $key), $defaultType, $codebase);
    }

    /**
     * Only a single-atomic, sealed `TKeyedArray` (no `fallback_params`, which would
     * admit unknown extra keys at runtime) is narrowed. A plain `TArray<K, V>` carries
     * no literal keys, and a multi-atomic union would force flattening shapes that
     * disagree on which keys exist.
     *
     * @psalm-mutation-free
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
     * shape, returning $default the moment a segment is missing. An intermediate
     * segment that is itself optional (`possibly_undefined`) can be absent at runtime
     * without failing the `array_key_exists()` check the loop performs on the CURRENT
     * level, so its absence surfaces as $default there too — union it into the final
     * result and keep walking, matching Laravel's foreach exactly.
     *
     * @param non-empty-list<string> $segments
     * @psalm-external-mutation-free
     */
    private static function walkDotPath(TKeyedArray $atomic, array $segments, ?Union $defaultType, Codebase $codebase): ?Union
    {
        $current = $atomic;
        $sawOptionalSegment = false;
        $lastIndex = \count($segments) - 1;

        foreach ($segments as $i => $segment) {
            if (!\array_key_exists($segment, $current->properties)) {
                return $defaultType ?? Type::getNull();
            }

            $propType = $current->properties[$segment];
            if ($propType->possibly_undefined) {
                $sawOptionalSegment = true;
            }

            if ($i === $lastIndex) {
                return self::resolveValue($propType, $defaultType, $codebase, $sawOptionalSegment);
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

    /**
     * Resolve a found property's value type: strip `possibly_undefined` (Psalm's
     * "key may be absent" flag, not a runtime value) and, when the key COULD be
     * absent — either this property itself or an earlier optional segment on the
     * walk to it — union in $default (or null when none was given), mirroring
     * `value($default)`.
     *
     * @psalm-external-mutation-free
     */
    private static function resolveValue(Union $propType, ?Union $defaultType, Codebase $codebase, bool $forceOptional = false): Union
    {
        $possiblyUndefined = $forceOptional || $propType->possibly_undefined;
        $result = $propType->setPossiblyUndefined(false);

        if ($possiblyUndefined) {
            return Type::combineUnionTypes($result, $defaultType ?? Type::getNull(), $codebase);
        }

        return $result;
    }
}
