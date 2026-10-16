--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/** @param Collection<int, int> $ints */
function to_array_scalars(Collection $ints): void
{
    $_array = $ints->toArray();
    /** @psalm-check-type-exact $_array = array<int, int> */
}

/** @param Collection<int, Customer> $customers */
function to_array_models(Collection $customers): void
{
    $_array = $customers->toArray();
    /** @psalm-check-type-exact $_array = array<int, array<array-key, mixed>> */
}

/** @param Collection<int, Customer|string> $mixed */
function to_array_model_or_scalar(Collection $mixed): void
{
    $_array = $mixed->toArray();
    /** @psalm-check-type-exact $_array = array<int, array<array-key, mixed>|string> */
}

/** @param Collection<int, mixed> $items */
function to_array_mixed(Collection $items): void
{
    $_array = $items->toArray();
    /** @psalm-check-type-exact $_array = array<int, mixed> */
}

/**
 * A generic Arrayable item next to a scalar must keep the array arm: the object-gated conditional
 * is needed because a bare `TValue is Arrayable` dropped it and reported `array<int, int>`.
 *
 * @param Collection<int, Collection<string, int>|int> $nested
 * @param Collection<int, Arrayable<string, int>|int> $arrayables
 */
function to_array_generic_arrayable_with_scalar(Collection $nested, Collection $arrayables): void
{
    $_nested = $nested->toArray();
    /** @psalm-check-type-exact $_nested = array<int, Collection<string, int>|array<array-key, mixed>|int> */

    $_arrayables = $arrayables->toArray();
    /** @psalm-check-type-exact $_arrayables = array<int, Arrayable<string, int>|array<array-key, mixed>|int> */
}

/** @param EloquentCollection<string, Customer> $customers */
function to_array_eloquent(EloquentCollection $customers): void
{
    $_array = $customers->toArray();
    /** @psalm-check-type-exact $_array = array<string, array<array-key, mixed>> */
}

/** @param LazyCollection<int, Customer|int> $lazy */
function to_array_lazy(LazyCollection $lazy): void
{
    $_array = $lazy->toArray();
    /** @psalm-check-type-exact $_array = array<int, array<array-key, mixed>|int> */
}
/** @extends Collection<int, int> */
final class IntsWithToArrayOverride extends Collection
{
    #[\Override]
    public function toArray(): array
    {
        return parent::toArray();
    }
}

/** @extends EloquentCollection<int, Customer> */
final class CustomerCollectionWithToArrayOverride extends EloquentCollection
{
    #[\Override]
    public function toArray(): array
    {
        return parent::toArray();
    }
}

--EXPECTF--
