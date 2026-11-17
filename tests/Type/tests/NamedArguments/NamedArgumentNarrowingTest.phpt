--FILE--
<?php declare(strict_types=1);

use App\Models\CollectionGroupingModel;
use App\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Handlers that narrow a call's return type must bind arguments by parameter, not by written
 * position: PHP lets named arguments appear in any order, so written position 0 is not always the
 * first parameter. Each function pairs a reordered named call with its positional twin.
 */

function env_default_written_first(): void
{
    $_named = env(default: 5, key: 'FOO');
    /** @psalm-check-type-exact $_named = 5|string */

    $_noDefault = env(key: 'FOO');
    /** @psalm-check-type-exact $_noDefault = string|null */
}

function cache_key_written_second(): void
{
    $_set = cache(default: 1, key: ['a' => 1]);
    /** @psalm-check-type-exact $_set = bool */

    // `$key` is null: Laravel returns the manager, which the handler must not mistake for a write
    $_manager = cache(default: ['a' => 1]);
    /** @psalm-check-type-exact $_manager = mixed */
}

/** @param array{a: int, b: string} $array */
function arr_get_reordered(array $array): void
{
    $_named = Arr::get(key: 'a', array: $array);
    /** @psalm-check-type-exact $_named = int */

    $_withDefault = Arr::get(default: 'x', key: 'b', array: $array);
    /** @psalm-check-type-exact $_withDefault = string */
}

/** @param list<Customer> $customers */
function arr_pluck_reordered(array $customers): void
{
    $_named = Arr::pluck(value: 'id', array: $customers);
    /** @psalm-check-type-exact $_named = list<string> */

    $_withKey = Arr::pluck(key: 'id', value: 'email_verified_at', array: $customers);
    /** @psalm-check-type-exact $_withKey = array<string, \Carbon\CarbonInterface|null> */
}

/** @param Collection<string, CollectionGroupingModel> $items */
function group_by_reordered(Collection $items): void
{
    $_named = $items->groupBy(preserveKeys: true, groupBy: 'foreign_id');
    /** @psalm-check-type-exact $_named = Collection<int, Collection<string, CollectionGroupingModel>> */

    $_positional = $items->groupBy('foreign_id', true);
    /** @psalm-check-type-exact $_positional = Collection<int, Collection<string, CollectionGroupingModel>> */
}

/** @param Collection<int, Customer> $customers */
function collection_pluck_reordered(Collection $customers): void
{
    $_positional = $customers->pluck('email_verified_at', 'id');
    $_named = $customers->pluck(key: 'id', value: 'email_verified_at');
    /** @psalm-check-type-exact $_positional = Collection<string, \Carbon\CarbonInterface|null> */
    /** @psalm-check-type-exact $_named = Collection<string, \Carbon\CarbonInterface|null> */
}

function builder_pluck_reordered(): void
{
    $_named = Customer::query()->pluck(key: 'id', column: 'email_verified_at');
    $_positional = Customer::query()->pluck('email_verified_at', 'id');
    /** @psalm-check-type-exact $_named = \Illuminate\Support\Collection<string, \Carbon\CarbonInterface|null> */
    /** @psalm-check-type-exact $_positional = \Illuminate\Support\Collection<string, \Carbon\CarbonInterface|null> */
}
?>
--EXPECTF--
