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
