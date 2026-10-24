--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Without a callback `times($n)` is `range(1, $n)`, so the values are ints (not `never`).
 * A callback keeps Laravel's reflected inference, including contextual typing of an untyped
 * closure parameter.
 */
final class CollectionTimesDefaultTest
{
    public function withoutCallback(): void
    {
        $_bare = Collection::times(3);
        /** @psalm-check-type-exact $_bare = Collection<int, int> */

        $_null = Collection::times(3, null);
        /** @psalm-check-type-exact $_null = Collection<int, int> */

        $_lazy = LazyCollection::times(3);
        /** @psalm-check-type-exact $_lazy = LazyCollection<int, int> */
    }

    public function withCallback(): void
    {
        $_untyped = Collection::times(3, static function ($i) {
            /** @psalm-check-type-exact $i = int */
            return $i * 2;
        });
        /** @psalm-check-type-exact $_untyped = Collection<int, int> */

        $_typed = Collection::times(3, static fn (int $i): bool => $i > 1);
        /** @psalm-check-type-exact $_typed = Collection<int, bool> */

        $_lazyUntyped = LazyCollection::times(3, static fn ($i) => $i * 2);
        /** @psalm-check-type-exact $_lazyUntyped = LazyCollection<int, int> */
    }

    public function lazyBoolCallbackKeepsStubResult(): void
    {
        $_lazyBool = LazyCollection::times(3, static fn (int $i): bool => $i > 1);
        /** @psalm-check-type-exact $_lazyBool = LazyCollection<int, bool> */
    }

    public function namedArgs(): void
    {
        $_numberOnly = Collection::times(number: 3);
        /** @psalm-check-type-exact $_numberOnly = Collection<int, int> */

        $_namedNull = Collection::times(number: 3, callback: null);
        /** @psalm-check-type-exact $_namedNull = Collection<int, int> */

        $_reversed = Collection::times(callback: null, number: 3);
        /** @psalm-check-type-exact $_reversed = Collection<int, int> */

        $_lazyNamedNull = LazyCollection::times(callback: null, number: 3);
        /** @psalm-check-type-exact $_lazyNamedNull = LazyCollection<int, int> */

        $_namedCallback = Collection::times(number: 3, callback: static fn (int $i): string => (string) $i);
        /** @psalm-check-type-exact $_namedCallback = Collection<int, numeric-string> */

        $_reversedCallback = Collection::times(callback: static fn (int $i): string => (string) $i, number: 3);
        /** @psalm-check-type-exact $_reversedCallback = Collection<int, numeric-string> */
    }

    /** The unpacked list may carry a callback the handler cannot see. */
    public function unpackedArgsDecline(): void
    {
        $_packed = Collection::times(...[3, static fn (int $i): string => (string) $i]);
        /** @psalm-check-type-exact $_packed = Collection<int, numeric-string> */

        $_lazyPacked = LazyCollection::times(...[3, static fn (int $i): string => (string) $i]);
        /** @psalm-check-type-exact $_lazyPacked = LazyCollection<int, numeric-string> */
    }

    /**
     * A template-bounded receiver (class-string or instance) must keep its template: the call is
     * not a plain `Collection::times()`.
     *
     * @template T of Collection<int, int>
     * @param class-string<T> $class
     * @return T
     */
    public function classStringFactory(string $class): Collection
    {
        return $class::times(3);
    }

    /**
     * @template T of Collection<int, int>
     * @param T $items
     * @return T
     */
    public function templateInstance(Collection $items): Collection
    {
        return $items->times(3);
    }

    public function eloquentCollectionDeclines(): void
    {
        $_eloquent = EloquentCollection::times(3);
        /** @psalm-check-type-exact $_eloquent = EloquentCollection<int, never> */
    }

    public function subclassDeclines(): void
    {
        // The stub's `static<int, ...>` on a non-generic subclass is a Psalm-level
        // TooManyTemplateParams; what matters is that the handler leaves the stub result alone.
        $_sub = TimesDefaultSubclass::times(3);
        /** @psalm-check-type-exact $_sub = TimesDefaultSubclass<int, never> */
    }
}

/**
 * @extends Collection<int, Customer>
 */
final class TimesDefaultSubclass extends Collection {}
?>
--EXPECTF--
TooManyTemplateParams on line %d: TimesDefaultSubclass<int, never> has too many template params, expecting 0
