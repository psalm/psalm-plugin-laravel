--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use App\Collections\CollectionGroupingCollection;
use App\Models\CollectionGroupingModel;
use App\Models\Customer;

/** @param Collection<string, CollectionGroupingModel> $items */
function group_by_on_support_collection(Collection $items): void
{
    $_default = $items->groupBy('foreign_id');
    /** @psalm-check-type-exact $_default = Collection<int, Collection<int, CollectionGroupingModel>&static>&static */

    $_preserved = $items->groupBy('foreign_id', true);
    /** @psalm-check-type-exact $_preserved = Collection<int, Collection<string, CollectionGroupingModel>&static>&static */

    $_false = $items->groupBy('foreign_id', false);
    /** @psalm-check-type-exact $_false = Collection<int, Collection<int, CollectionGroupingModel>&static>&static */

    $preserveKeys = \rand(0, 1) === 1;
    $_dynamic = $items->groupBy('foreign_id', $preserveKeys);
    /** @psalm-check-type-exact $_dynamic = Collection<int, Collection<int|string, CollectionGroupingModel>&static>&static */

    $_bool = $items->groupBy('active');
    /** @psalm-check-type-exact $_bool = Collection<int, Collection<int, CollectionGroupingModel>&static>&static */

    $_enum = $items->groupBy('kind');
    /** @psalm-check-type-exact $_enum = Collection<int, Collection<int, CollectionGroupingModel>&static>&static */
}

/** @param EloquentCollection<int, CollectionGroupingModel> $items */
function key_by_on_eloquent_collection(EloquentCollection $items): void
{
    $_int = $items->keyBy('foreign_id');
    /** @psalm-check-type-exact $_int = EloquentCollection<int, CollectionGroupingModel>&static */

    $_bool = $items->keyBy('active');
    /** @psalm-check-type-exact $_bool = EloquentCollection<int, CollectionGroupingModel>&static */

    // Laravel 12.14 stringifies enum objects in keyBy(), so this must not narrow.
    $_enum = $items->keyBy('kind');
    /** @psalm-check-type-exact $_enum = EloquentCollection<array-key, CollectionGroupingModel>&static */
}

function key_by_on_custom_collection(CollectionGroupingCollection $items): void
{
    $_result = $items->keyBy('foreign_id');
    /** @psalm-check-type-exact $_result = CollectionGroupingCollection<int, CollectionGroupingModel>&static */
}

/** @param Collection<int, CollectionGroupingModel|Customer> $items */
function union_models_and_nullable_columns_defer(Collection $items): void
{
    $_union = $items->groupBy('foreign_id');
    /** @psalm-check-type-exact $_union = Collection<array-key, Collection<int, CollectionGroupingModel|Customer>&static>&static */

    $_nullable = $items->keyBy('nullable_id');
    /** @psalm-check-type-exact $_nullable = Collection<array-key, CollectionGroupingModel|Customer>&static */
}
?>
--EXPECTF--
