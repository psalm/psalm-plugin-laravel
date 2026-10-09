<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Psalm\Codebase;
use Psalm\Type;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNever;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Resolves the argument passed to collect() / Collection::make() / LazyCollection::make() to the
 * TKey/TValue Union pair Laravel's runtime actually produces, for the three input shapes the
 * stub's own template inference can't bind: null, scalars, and UnitEnum cases.
 *
 * Arrays are the fourth shape: the stub binds them, but too precisely. Psalm infers the keys of
 * `[$a, $b]` as `int<0, 1>`, of a `list<T>` as `int<0, max>`, and of `['x' => $a]` as `'x'`, and
 * TKey is invariant, so the result is rejected where `Collection<int, T>` or
 * `Collection<string, T>` is declared (vimeo/psalm#10985). Literal and range keys widen to
 * `int`/`string`; the widening is sound and drops only precision that is rarely declared.
 *
 * Returns null ("defer to the stub's own template inference") for every other shape, including
 * plain objects: the stub's own widened-but-unbound `object` union member already infers
 * `Collection<array-key, mixed>` for them without this resolver's help (verified empirically -
 * see issue #808).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
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
        // classImplements() is non-reflexive - see CollectInterfaceTypedInputsTest.phpt). With the
        // 12.20+ Laravel floor (see composer.json), getArrayableItems()'s dispatch - `is_null() ||
        // is_scalar() || $items instanceof UnitEnum ? Arr::wrap($items) : Arr::from($items)` -
        // checks UnitEnum unconditionally, no probe, no interface-deferral needed:
        // Arr::wrap($enumCase) === [0 => $enumCase].
        if ($atomic instanceof TNamedObject
            && ($codebase->classImplements($atomic->value, \UnitEnum::class)
                || \strtolower($atomic->value) === \strtolower(\UnitEnum::class))
        ) {
            return [self::$literalZeroKeyUnion ??= Type::getInt(value: 0), new Union([$atomic])];
        }

        if ($atomic instanceof TKeyedArray) {
            return self::widenArrayKeys($atomic->getGenericKeyType(), $atomic->getGenericValueType());
        }

        if ($atomic instanceof TArray) {
            return self::widenArrayKeys($atomic->type_params[0], $atomic->type_params[1]);
        }

        return null;
    }

    /**
     * Null when nothing widens (already plain keys, or `never` for `[]`), so the stub's binding stays.
     *
     * @return array{Union, Union}|null
     * @psalm-capabilities read-props
     */
    private static function widenArrayKeys(Union $keyType, Union $valueType): ?array
    {
        $widened = false;
        $keyAtomics = [];

        foreach ($keyType->getAtomicTypes() as $keyAtomic) {
            if ($keyAtomic instanceof TNever) {
                return null;
            }

            if ($keyAtomic instanceof TInt && $keyAtomic::class !== TInt::class) {
                $keyAtomic = new TInt();
                $widened = true;
            } elseif ($keyAtomic instanceof TLiteralString) {
                $keyAtomic = new TString();
                $widened = true;
            }

            $keyAtomics[] = $keyAtomic;
        }

        if (!$widened) {
            return null;
        }

        return [new Union($keyAtomics), $valueType];
    }
}
