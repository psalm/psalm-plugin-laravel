--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A handler that answers a generic object over its receiver's type arguments answers a NEW type: it
 * is not the docblock type of the receiver, even when the receiver's atomic is reused to keep a `*`
 * type argument. Carrying the receiver's docblock origin over would turn `RedundantCondition` into
 * `RedundantConditionGivenDocblockType` for every receiver typed by a docblock, with or without a `*`.
 *
 * @param Collection<int, Customer|null> $collection
 * @param HasMany<Vehicle, Customer> $relation
 */
function rebuilt_answers_are_not_docblock_types(Collection $collection, HasMany $relation): void
{
    $narrowed = $collection->whereNotNull();
    if ($narrowed instanceof Collection) {
        echo 'narrowed';
    }

    $proxied = $collection->filter->isDirty();
    if ($proxied instanceof Collection) {
        echo 'proxied';
    }

    $forwarded = $relation->where('id', 1);
    if ($forwarded instanceof HasMany) {
        echo 'forwarded';
    }
}
--EXPECTF--
RedundantCondition on line %d: Type Illuminate\Support\Collection<int, App\Models\Customer> for $narrowed is always Illuminate\Support\Collection
RedundantCondition on line %d: Type Illuminate\Support\Collection<int, App\Models\Customer|null> for $proxied is always Illuminate\Support\Collection
RedundantCondition on line %d: Type Illuminate\Database\Eloquent\Relations\HasMany<App\Models\Vehicle, App\Models\Customer> for $forwarded is always Illuminate\Database\Eloquent\Relations\HasMany
