--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/** @param EloquentCollection<int, Customer> $customers */
function eloquent_find_or_fail_arms(EloquentCollection $customers): void
{
    $_one = $customers->findOrFail(5);
    /** @psalm-check-type-exact $_one = Customer */

    $_many = $customers->findOrFail([1, 2]);
    /** @psalm-check-type-exact $_many = EloquentCollection<int, Customer>&static */

    $_byModel = $customers->findOrFail(new Customer());
    /** @psalm-check-type-exact $_byModel = Customer */
}

/** @param EloquentCollection<string, Customer> $customers */
function eloquent_pad_zip_flip(EloquentCollection $customers): void
{
    $_padded = $customers->pad(5, 'x');
    /** @psalm-check-type-exact $_padded = Illuminate\Support\Collection<int|string, Customer|'x'> */

    $_zipped = $customers->zip(['a', 'b']);
    /** @psalm-check-type-exact $_zipped = Illuminate\Support\Collection<int, Illuminate\Support\Collection<int, 'a'|'b'|Customer>> */

    $_flipped = $customers->flip();
    /** @psalm-check-type-exact $_flipped = Illuminate\Support\Collection<array-key, string> */
}

/** @param EloquentCollection<string, Customer> $customers */
function eloquent_model_keys(EloquentCollection $customers): void
{
    $_keys = $customers->modelKeys();
    /** @psalm-check-type-exact $_keys = array<string, array-key> */
}
--EXPECTF--
