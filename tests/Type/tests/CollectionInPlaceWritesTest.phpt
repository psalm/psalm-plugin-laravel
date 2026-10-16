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

function fluent_chain_widens(string $name, int $count): void
{
    $chain = collect()->add($name)->prepend($count);
    /** @psalm-check-type-exact $chain = Collection<int, int|string>&static */
    consume($chain);
}

/** @param Collection<int, Dog> $dogs */
function array_access_write_stays_strict(Collection $dogs): void
{
    $dogs[] = new Cat();
}

?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Collection::offsetSet expects Dog, but Cat provided
