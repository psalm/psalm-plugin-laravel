--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1890
 *
 * prepend(), unshift(), add(), splice() and getOrPut() mutate the receiver in place, like push()/put().
 * They take independent value templates plus @psalm-this-out, so writing a value outside the current
 * TValue widens the collection instead of raising InvalidArgument. Array-access writes ($c[] = $v)
 * stay strict: this-out does not apply to offsetSet.
 */

use Illuminate\Support\Collection;

final class Cat {}

final class Dog {}

function consume(mixed $_value): void {}

/** @param Collection<int, Dog> $dogs */
function prepend_widens_accumulator(Collection $dogs): void
{
    $dogs->prepend(new Cat());
    /** @psalm-check-type-exact $dogs = Collection<int, Cat|Dog>&static */
    consume($dogs);
}

/** @param Collection<string, int> $ints */
function prepend_with_key_widens_key_and_value(Collection $ints, string $label, string $value): void
{
    $ints->prepend($value, $label);
    /** @psalm-check-type-exact $ints = Collection<string, int|string>&static */
    consume($ints);
}

/** @param Collection<int, Dog> $dogs */
function unshift_widens_accumulator(Collection $dogs): void
{
    $dogs->unshift(new Cat());
    /** @psalm-check-type-exact $dogs = Collection<int, Cat|Dog>&static */
    consume($dogs);
}

/** @param Collection<int, Dog> $dogs */
function add_widens_accumulator(Collection $dogs): void
{
    $dogs->add(new Cat());
    /** @psalm-check-type-exact $dogs = Collection<int, Cat|Dog>&static */
    consume($dogs);
}

/** @param Collection<int, Dog> $dogs */
function splice_widens_receiver_but_returns_removed_slice(Collection $dogs): void
{
    $removed = $dogs->splice(0, 1, [new Cat()]);
    /** @psalm-check-type-exact $removed = Collection<int, Dog>&static */
    /** @psalm-check-type-exact $dogs = Collection<int, Cat|Dog>&static */
    consume($removed);
    consume($dogs);
}

/** @param Collection<string, int> $ints */
function get_or_put_widens_key_and_value(Collection $ints, int $id, float $default): void
{
    $value = $ints->getOrPut($id, $default);
    /** @psalm-check-type-exact $value = float|int */
    /** @psalm-check-type-exact $ints = Collection<int|string, float|int>&static */
    consume($value);
    consume($ints);
}

/** @param Collection<string, int> $ints */
function get_or_put_string_key_keeps_key_type(Collection $ints, string $key): void
{
    $ints->getOrPut($key, 1);
    /** @psalm-check-type-exact $ints = Collection<string, int>&static */
    consume($ints);
}

/** @param Collection<string, int> $ints */
function get_or_put_accepts_nullable_key(Collection $ints, ?string $key): void
{
    $ints->getOrPut($key, 1);
    /** @psalm-check-type-exact $ints = Collection<array-key, int>&static */
    consume($ints);
}

/** @param Collection<string, int> $ints */
function prepend_omitted_key_adds_int_key(Collection $ints): void
{
    $ints->prepend(1);
    /** @psalm-check-type-exact $ints = Collection<int|string, int>&static */
    consume($ints);
}

/** @param Collection<string, int> $ints */
function prepend_explicit_null_key_adds_empty_string_key(Collection $ints): void
{
    $ints->prepend(1, null);
    /** @psalm-check-type-exact $ints = Collection<string, int>&static */
    consume($ints);
}

/** @param Collection<int, string> $names */
function prepend_explicit_null_key_on_int_keys(Collection $names, string $name): void
{
    $names->prepend($name, null);
    /** @psalm-check-type-exact $names = Collection<''|int, string>&static */
    consume($names);
}

/** @param Collection<int, string> $names */
function prepend_nullable_int_key_may_add_empty_string_key(Collection $names, string $name, ?int $key): void
{
    $names->prepend($name, $key);
    /** @psalm-check-type-exact $names = Collection<''|int, string>&static */
    consume($names);
}

function fluent_chain_widens(string $name, int $count): void
{
    $chain = collect()->add($name)->prepend($count);
    /** @psalm-check-type-exact $chain = Collection<int, int|string>&static */
    consume($chain);
}

/** @param Collection<string, int> $_ints */
function accepts_string_int_collection(Collection $_ints): void {}

/** @param Collection<string, int> $ints */
function get_or_put_closure_default_keeps_receiver(Collection $ints): void
{
    $value = $ints->getOrPut('k', static fn (): int => 1);
    /** @psalm-check-type-exact $value = int */
    /** @psalm-check-type-exact $ints = Collection<string, int>&static */
    consume($value);
    accepts_string_int_collection($ints);
}

/** @param Collection<string, int> $ints */
function get_or_put_untyped_closure_binds_closure_return(Collection $ints): void
{
    $value = $ints->getOrPut('k', fn () => 1);
    /** @psalm-check-type-exact $value = int */
    /** @psalm-check-type-exact $ints = Collection<string, int>&static */
    consume($value);
    accepts_string_int_collection($ints);
}

/** @param Collection<string, int> $ints */
function get_or_put_closure_default_widens_by_closure_return(Collection $ints, string $label): void
{
    $value = $ints->getOrPut('k', static fn (): string => $label);
    /** @psalm-check-type-exact $value = int|string */
    /** @psalm-check-type-exact $ints = Collection<string, int|string>&static */
    consume($value);
    consume($ints);
}

/**
 * @template T of string
 * @param Collection<string, T> $items
 * @param T $fallback
 * @return T
 */
function get_or_put_bounded_template_default_stays_precise(Collection $items, string $fallback): string
{
    return $items->getOrPut('key', $fallback);
}

/** @param Collection<string, string> $_items */
function accepts_string_string_collection(Collection $_items): void {}

/**
 * @template T of string
 * @param Collection<string, T> $items
 * @param T $fallback
 */
function get_or_put_bounded_template_default_keeps_receiver(Collection $items, string $fallback): void
{
    $items->getOrPut('key', $fallback);
    accepts_string_string_collection($items);
}

/**
 * @param Collection<string, iterable<int, int>> $lists
 * @param iterable<int, int> $fallback
 */
function get_or_put_iterable_value_stays_precise(Collection $lists, iterable $fallback): void
{
    $value = $lists->getOrPut('k', $fallback);
    /** @psalm-check-type-exact $value = iterable<int, int> */
    /** @psalm-check-type-exact $lists = Collection<string, iterable<int, int>>&static */
    consume($value);
    consume($lists);
}

/** @param Collection<string, Countable> $items */
function get_or_put_interface_value_stays_precise(Collection $items, Countable $fallback): void
{
    $value = $items->getOrPut('k', $fallback);
    /** @psalm-check-type-exact $value = Countable */
    /** @psalm-check-type-exact $items = Collection<string, Countable>&static */
    consume($value);
    consume($items);
}

/**
 * @template T of \stdClass
 * @param Collection<string, T> $items
 * @param T $fallback
 * @return T
 */
function get_or_put_object_bounded_template_stays_precise(Collection $items, \stdClass $fallback): \stdClass
{
    return $items->getOrPut('key', $fallback);
}

final class GetOrPutPropertyReceiver
{
    /** @var Collection<string, int> */
    private Collection $items;

    public function __construct()
    {
        $this->items = new Collection();
    }

    public function cached(string $key): int
    {
        return $this->items->getOrPut($key, static fn (): int => 1);
    }
}

/** @param Collection<int, Dog> $dogs */
function array_access_write_stays_strict(Collection $dogs): void
{
    $dogs[] = new Cat();
}

?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Collection::offsetSet expects Dog, but Cat provided
