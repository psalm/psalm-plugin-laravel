<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Psalm\Codebase;
use Psalm\Type;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Resolves the argument passed to collect() / Collection::make() / LazyCollection::make() to the
 * TKey/TValue Union pair Laravel's runtime actually produces, for the input shapes the stub's own
 * template inference can't bind: null, scalars, UnitEnum cases, and array inputs whose range keys
 * (`int<0, 1>`) the stub would otherwise bind verbatim.
 *
 * Returns null ("defer to the stub's own template inference") for every other shape, including
 * plain objects: the stub's own widened-but-unbound `object` union member already infers
 * `Collection<array-key, mixed>` for them without this resolver's help (verified empirically -
 * see issue #808), so there is nothing left to resolve beyond null/scalar/enum/array.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1766
 * @internal
 */
final class CollectionInputTypeResolver
{
    /**
     * Cached invariant Unions this handler fires repeatedly with (once per stub-unreachable
     * collect()/make() call in the analysed codebase), so re-allocating them per call adds up.
     */
    private static ?Union $neverUnion = null;

    private static ?Union $literalZeroKeyUnion = null;

    /**
     * @return array{Union, Union}|null [TKey, TValue]
     */
    public static function resolve(?Union $argType, Codebase $codebase): ?array
    {
        if (!$argType instanceof Union) {
            return null;
        }

        $atomics = $argType->getAtomicTypes();

        // A union of more than one atomic (e.g. `int|string`, `Foo|null`) is left to the stub's
        // template inference rather than trying to merge branch-by-branch here.
        if (\count($atomics) !== 1) {
            return null;
        }

        $atomic = \reset($atomics);

        // Array input: the stub binds a range key verbatim (`[$a, $b]` -> `Collection<int<0, 1>, A>`), which
        // fails the invariant `TKey` of a declared `Collection<int, A>` (#1766).
        if ($atomic instanceof TKeyedArray || $atomic instanceof TArray) {
            return self::resolveArrayKeys($atomic);
        }

        if ($atomic instanceof TNull) {
            // Arr::wrap(null) === [] -> same empty result as collect()/collect([]).
            $never = self::$neverUnion ??= Type::getNever();

            return [$never, $never];
        }

        if ($atomic instanceof Scalar) {
            // Arr::wrap($scalar) === [0 => $scalar].
            return [self::$literalZeroKeyUnion ??= Type::getInt(value: 0), new Union([$atomic])];
        }

        // TClosure extends TNamedObject, so this must be checked before the enum check below -
        // resolve() doesn't know which entry point called it, so a bare Closure passed to
        // collect()/Collection::make() (not just LazyCollection::make()'s closure-generator form)
        // also defers here, landing on the stub's unbound-template fallback, which happens to
        // coincide with the sound (array) $closure property-bag typing.
        if ($atomic instanceof TClosure) {
            return null;
        }

        // UnitEnum, including a bare `UnitEnum $x` param (identity, not classImplements(), since
        // classImplements() is non-reflexive - see CollectInterfaceTypedInputsTest.phpt). Across the
        // supported Laravel range, getArrayableItems()'s dispatch - `is_null() ||
        // is_scalar() || $items instanceof UnitEnum ? Arr::wrap($items) : Arr::from($items)` -
        // checks UnitEnum unconditionally, no probe, no interface-deferral needed:
        // Arr::wrap($enumCase) === [0 => $enumCase].
        if ($atomic instanceof TNamedObject
            && ($codebase->classImplements($atomic->value, \UnitEnum::class)
                || \strtolower($atomic->value) === \strtolower(\UnitEnum::class))
        ) {
            return [self::$literalZeroKeyUnion ??= Type::getInt(value: 0), new Union([$atomic])];
        }

        return null;
    }

    /**
     * Declines (null) when no key is a range, so the stub binding and its diagnostics stay untouched
     * (`[]` binds `never` and keeps its empty-collection meaning).
     *
     * @return array{Union, Union}|null
     * @psalm-pure
     */
    private static function resolveArrayKeys(TKeyedArray|TArray $array): ?array
    {
        if ($array instanceof TKeyedArray) {
            $keyType = $array->getGenericKeyType();
            $valueType = $array->getGenericValueType();
        } else {
            [$keyType, $valueType] = $array->type_params;
        }

        // Literal keys stay: Psalm skips the invariant check for an argument holding a literal, so
        // `Collection<0, A>` / `Collection<'x', A>` already satisfy `Collection<int|string, A>`, while
        // widening them would add errors at bare `Collection` params. Only ranges (`int<0, 1>`,
        // `int<0, max>`) are strict, and they are the actual false positives.
        $changed = false;
        $keys = [];
        foreach ($keyType->getAtomicTypes() as $atomic) {
            $isRange = $atomic instanceof TIntRange;
            $changed = $changed || $isRange;
            $keys[] = $isRange ? new TInt() : $atomic;
        }

        return $changed ? [new Union($keys), $valueType] : null;
    }
}
