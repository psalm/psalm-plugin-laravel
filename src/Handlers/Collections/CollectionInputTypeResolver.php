<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Psalm\Codebase;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TEnumCase;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Resolves the argument passed to collect() / Collection::make() / LazyCollection::make() to the
 * TKey/TValue Union pair Laravel's runtime actually produces, for the input shapes the stub's own
 * template inference can't bind: null, scalars, and UnitEnum cases; plus array inputs whose scalar
 * literals and range keys the stub would otherwise bind verbatim (see resolveArray(), and decisions.md,
 * "Collection literal generalization", for what is deliberately kept).
 *
 * Returns null ("defer to the stub's own template inference") for every other shape, including
 * plain objects: the stub's own widened-but-unbound `object` union member already infers
 * `Collection<array-key, mixed>` for them without this resolver's help (verified empirically -
 * see issue #808), so there is nothing left to resolve beyond null/scalar/enum.
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

        // Array input: the stub binds the literal shape verbatim (`[$a, $b]` -> `Collection<int<0, 1>, A>`,
        // `[1]` -> `Collection<0, 1>`), which makes correct code like `@return Collection<int, Item>` or
        // `->prepend(2)` fail on the invariant templates (#1766). Widen the way PHPStan's template
        // generalization does.
        if ($atomic instanceof TKeyedArray || $atomic instanceof TArray) {
            return self::resolveArray($atomic);
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

        return null;
    }

    /**
     * Declines (null) for `[]` (never keys/values keep their empty-collection meaning) and when
     * there was nothing to widen, so the stub binding and its diagnostics stay untouched.
     *
     * @return array{Union, Union}|null
     */
    private static function resolveArray(TKeyedArray|TArray $array): ?array
    {
        if ($array instanceof TKeyedArray) {
            $keyType = $array->getGenericKeyType();
            $valueType = $array->getGenericValueType();
        } else {
            [$keyType, $valueType] = $array->type_params;
        }

        if ($keyType->isNever() || $valueType->isNever()) {
            return null;
        }

        // Literal keys stay: Psalm skips the invariant check for an argument holding a literal, so
        // `Collection<0, A>` / `Collection<'x', A>` already satisfy `Collection<int|string, A>`, while
        // widening them would add errors at bare `Collection` params. Only ranges (`int<0, 1>`, `int<0, max>`)
        // are strict, and they are the actual false positives.
        $newKey = self::mapAtomics($keyType, static fn(Atomic $atomic): Atomic => $atomic instanceof TIntRange
            ? new TInt()
            : $atomic);
        $newValue = self::generalizeValue($valueType);

        if ($newKey === $keyType && $newValue === $valueType) {
            return null;
        }

        return [$newKey, $newValue];
    }

    private static function generalizeValue(Union $type): Union
    {
        // Psalm treats the WHOLE argument leniently at an invariant param as soon as one literal is present
        // (`Collection<'a'|'b', Parent|int>` accepts `['a' => $child, 'b' => 1]`), so scalar literals are
        // widened only when nothing else in the union relies on that leniency. Enum cases are strict anyway.
        $widenScalars = true;
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!self::isWidenableScalarLiteral($atomic) && !$atomic instanceof TEnumCase) {
                $widenScalars = false;
                break;
            }
        }

        return self::mapAtomics($type, static fn(Atomic $atomic): Atomic => match (true) {
            $atomic instanceof TEnumCase => new TNamedObject($atomic->value),
            !$widenScalars => $atomic,
            $atomic instanceof TLiteralString => new TString(),
            $atomic instanceof TLiteralInt => new TInt(),
            $atomic instanceof TLiteralFloat => new TFloat(),
            default => new TBool(),
        });
    }

    /**
     * A class-string literal is excluded: widening it breaks `new $class()` and `Collection<int, class-string<I>>` targets.
     *
     * @psalm-pure
     */
    private static function isWidenableScalarLiteral(Atomic $atomic): bool
    {
        return ($atomic instanceof TLiteralString && !$atomic instanceof TLiteralClassString)
            || $atomic instanceof TLiteralInt
            || $atomic instanceof TLiteralFloat
            || $atomic instanceof TTrue
            || $atomic instanceof TFalse;
    }

    /**
     * Returns $type itself when no atomic changed, so callers can detect "nothing widened" by identity.
     *
     * @param callable(Atomic): Atomic $map
     */
    private static function mapAtomics(Union $type, callable $map): Union
    {
        $changed = false;
        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            $mapped = $map($atomic);
            $changed = $changed || $mapped !== $atomic;
            $atomics[] = $mapped;
        }

        return $changed ? new Union($atomics) : $type;
    }
}
