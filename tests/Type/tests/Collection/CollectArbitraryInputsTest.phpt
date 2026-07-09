--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 *
 * Laravel's runtime accepts far more than array/iterable/Arrayable in collect():
 * EnumeratesValues::getArrayableItems() routes null|scalar|UnitEnum through Arr::wrap()
 * and any other object through Arr::from(). Before this fix, every case below raised a
 * false-positive InvalidArgument. CollectHandler narrows TKey/TValue precisely for null,
 * scalars, and UnitEnum cases; every other object shape (DateTime, plain DTOs, WeakMap,
 * Jsonable, JsonSerializable, ...) defers to the stub's own widened-but-unbound
 * `array-key, mixed` fallback, which is already the sound answer for them - see
 * CollectionInputTypeResolver's docblock.
 */

use Illuminate\Support\Collection;

enum BackedSuit: string
{
    case Hearts = 'hearts';
}

final class PlainDto
{
    public int $id = 1;
    public string $name = 'dto';
}

final class SerializableThing implements \JsonSerializable
{
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['a' => 1];
    }
}

final class JsonableThing implements \Illuminate\Contracts\Support\Jsonable
{
    #[\Override]
    public function toJson($options = 0): string
    {
        return '{}';
    }
}

$_null = collect(null);
/** @psalm-check-type-exact $_null = Collection<never, never> */

$_string = collect('foo');
/** @psalm-check-type-exact $_string = Collection<0, 'foo'> */

$_int = collect(1);
/** @psalm-check-type-exact $_int = Collection<0, 1> */

$_float = collect(3.14);
/** @psalm-check-type-exact $_float = Collection<0, 3.14> */

$_bool = collect(true);
/** @psalm-check-type-exact $_bool = Collection<0, true> */

$_enum = collect(BackedSuit::Hearts);
/** @psalm-check-type-exact $_enum = Collection<0, BackedSuit> */

$_carbon = collect(now());
/** @psalm-check-type-exact $_carbon = Collection<array-key, mixed> */

$_dateTime = collect(new \DateTime());
/** @psalm-check-type-exact $_dateTime = Collection<array-key, mixed> */

$_dto = collect(new PlainDto());
/** @psalm-check-type-exact $_dto = Collection<array-key, mixed> */

// (array) $obj coerces numeric-string property names to int keys (e.g. (array)
// json_decode('{"1":"a"}') === [1 => 'a']), so the plain-object fallback's key type must be
// array-key, not string (issue #808 review).
$_mixedKeyObject = collect((object) ['1' => 'a', 'foo' => 'b']);
/** @psalm-check-type-exact $_mixedKeyObject = Collection<array-key, mixed> */

$_jsonSerializable = collect(new SerializableThing());
/** @psalm-check-type-exact $_jsonSerializable = Collection<array-key, mixed> */

$_jsonable = collect(new JsonableThing());
/** @psalm-check-type-exact $_jsonable = Collection<array-key, mixed> */

// No WeakMap-specific handling: it defers to the stub's own fallback like any other object,
// same as DateTime/PlainDto/Jsonable/JsonSerializable above.
$_weakMap = collect(new \WeakMap());
/** @psalm-check-type-exact $_weakMap = Collection<array-key, mixed> */

// Bare `object` (no known class, e.g. behind a parameter type-hint) defers the same way.
function bareObjectInput(object $o): Collection
{
    $collection = collect($o);
    /** @psalm-check-type-exact $collection = Collection<array-key, mixed> */
    return $collection;
}

// Multi-atomic unions (e.g. `int|string`, `?string`) are left to the stub's own template
// inference rather than resolved branch-by-branch by the resolver (CollectionInputTypeResolver
// bails out on `count($atomics) !== 1`). Pins the current deferred behaviour: no false-positive
// InvalidArgument, and the stub's widened-but-unbound fallback shape.
function multiAtomicIntOrString(int|string $value): Collection
{
    $collection = collect($value);
    /** @psalm-check-type-exact $collection = Collection<array-key, mixed> */
    return $collection;
}

function multiAtomicNullableString(?string $value): Collection
{
    $collection = collect($value);
    /** @psalm-check-type-exact $collection = Collection<array-key, mixed> */
    return $collection;
}

// Canary for the partial trait re-declaration in EnumeratesValues.phpstub: make() is the only
// method re-declared there, but non-make() EnumeratesValues methods (avg(), contains()) must
// still resolve on base Collection through the trait merge. If a future Psalm change to
// stub-trait merging breaks that, this should fail loudly.
$_containsCanary = collect([1, 2, 3])->contains(2);
/** @psalm-check-type-exact $_containsCanary = bool */

$_avgCanary = collect([1, 2, 3])->avg();
/** @psalm-check-type-exact $_avgCanary = float|int|null */

// An enum that ALSO implements JsonSerializable must still wrap as [0 => $enumCase]: with the
// 12.14+ Laravel floor (see composer.json), Arr::wrap() checks UnitEnum FIRST, unconditionally,
// regardless of what else the enum implements (issue #808 review).
enum SerializableSuit: string implements \JsonSerializable
{
    case Spades = 'spades';

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->value;
    }
}

$_enumImplementsJsonSerializable = collect(SerializableSuit::Spades);
/** @psalm-check-type-exact $_enumImplementsJsonSerializable = Collection<0, SerializableSuit> */

?>
--EXPECTF--
