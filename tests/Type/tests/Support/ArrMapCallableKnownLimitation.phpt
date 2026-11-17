--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * Known limitation: `Arr::map()` and `Arr::mapWithKeys()` take `callable(TValue, TKey)`, and Psalm rejects a
 * string or array callable that declares fewer params than that (UnionTypeComparator::isContainedBy() param-count
 * check). Closures and first-class callables are accepted. At runtime Laravel retries `Arr::map()` with the value
 * alone, so all of these work. `Collection::map` has the same limitation; see decisions.md, "Conditionable
 * `when()`/`unless()` callback params via a params provider" ("A passed-through callable that declares fewer params
 * is rejected against a 2-param `callable`").
 */
final class ArrMapLimitFixture
{
    public static function shout(string $value): string
    {
        return $value;
    }

    /** @return array<string, string> */
    public static function pair(string $value): array
    {
        return [$value => $value];
    }
}

/** @param array<string, string> $array */
function string_and_array_callables(array $array): void
{
    Arr::map($array, 'strtoupper');
    Arr::map($array, 'strval');
    Arr::map($array, [ArrMapLimitFixture::class, 'shout']);
    Arr::map($array, 'ArrMapLimitFixture::shout');
    Arr::mapWithKeys($array, 'ArrMapLimitFixture::pair');
}
--EXPECTF--
PossiblyInvalidArgument on line %d: Argument 2 of Illuminate\Support\Arr::map expects callable[impure](mixed, array-key):mixed, but possibly different type callable[pure](mixed):string provided
PossiblyInvalidArgument on line %d: Argument 2 of Illuminate\Support\Arr::map expects callable[impure](null|object|scalar, array-key):mixed, but possibly different type callable[pure](null|object|scalar):string provided
PossiblyInvalidArgument on line %d: Argument 2 of Illuminate\Support\Arr::map expects callable[impure](string, array-key):mixed, but possibly different type callable[impure](string):string provided
PossiblyInvalidArgument on line %d: Argument 2 of Illuminate\Support\Arr::map expects callable[impure](string, array-key):mixed, but possibly different type callable[impure](string):string provided
PossiblyInvalidArgument on line %d: Argument 2 of Illuminate\Support\Arr::mapWithKeys expects callable[impure](string, mixed):array<array-key, mixed>, but possibly different type callable[impure](string):array<string, string> provided
