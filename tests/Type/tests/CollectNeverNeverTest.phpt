--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/721
 *
 * collect() with no args should return Collection<never, never> (the bottom-type empty collection),
 * not Collection<array-key, mixed>. The never type is the bottom type: it is a subtype of everything,
 * so it is assignable to any Collection<TKey, TValue>. Methods like merge() and push() are stubbed to
 * widen generics on their return so that fluent chains propagate concrete types correctly.
 */

use Illuminate\Support\Collection;

// collect() with no arguments infers Collection<never, never> from the [] default.
$_c_no_args = collect();
/** @psalm-check-type-exact $_c_no_args = Collection<never, never> */

// collect([]) with an explicit empty array also infers Collection<never, never>.
$_c_empty_arr = collect([]);
/** @psalm-check-type-exact $_c_empty_arr = Collection<never, never> */

// Collection<never, never> is the bottom type and is assignable to any Collection<TKey, TValue>.
/** @return Collection<int, string> */
function collect_no_args_is_assignable_to_concrete_collection(): Collection
{
    return collect();
}

final class CollectNeverNeverTests
{
    /**
     * push() on Collection<never, never> widens generics so the result is assignable
     * to a concrete Collection type — the fluent chain pattern works without @psalm-suppress.
     * @return Collection<int, string>
     */
    public function push_on_never_never_is_assignable_to_concrete(): Collection
    {
        return collect()->push('hello');
    }

    /**
     * Chained push() calls accumulate types; the final result is assignable to a concrete type.
     * @return Collection<int, int|string>
     */
    public function chained_push_accumulates_types(): Collection
    {
        return collect()->push('hello')->push(42);
    }

    /**
     * merge() on Collection<never, never> uses independent key/value templates,
     * so the result carries the concrete types of the merged collection.
     * @return Collection<int, string>
     */
    public function merge_on_never_never_is_assignable(): Collection
    {
        /** @var Collection<int, string> $items */
        $items = new Collection(['a', 'b']);

        return collect()->merge($items);
    }

    /**
     * merge() on a concrete Collection widens both key and value types.
     * @return Collection<int|string, int|string>
     */
    public function merge_widens_both_key_and_value_types(): Collection
    {
        /** @var Collection<int, string> $strings */
        $strings = new Collection(['a', 'b']);

        /** @var Collection<string, int> $ints */
        $ints = new Collection(['x' => 1]);

        return $strings->merge($ints);
    }

    /**
     * put() on Collection<never, never> widens generics in the fluent chain.
     * @return Collection<string, Closure(): bool>
     */
    public function put_on_never_never_is_assignable_to_concrete(): Collection
    {
        return collect()->put('label', fn (): bool => true);
    }
}

/**
 * Mutable accumulator pattern: put() + each() (issue #723).
 * @psalm-this-out on put() updates $tasks so each() sees the concrete types.
 * No @var annotation is needed on the collect() line.
 */
function mutable_accumulator_put_then_each(): void
{
    $tasks = collect();
    $tasks->put('label', fn (): bool => true);
    $tasks->each(function (\Closure $task, string $description): void {
        echo $description;
        $task();
    });
}

/**
 * Mutable accumulator pattern: push() + each().
 * @psalm-this-out on push() updates $items so each() sees the concrete types.
 */
function mutable_accumulator_push_then_each(): void
{
    $items = collect();
    $items->push('hello');
    $items->each(fn (string $item) => strtoupper($item));
}

?>
--EXPECT--
