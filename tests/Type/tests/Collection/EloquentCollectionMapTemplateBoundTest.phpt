--FILE--
<?php declare(strict_types=1);

use App\Models\CollectionGroupingModel;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * map()/flatMap() to scalars on an Eloquent collection must not put a scalar into the
 * `TModel of Model` slot, even when the result is chained (#1699).
 *
 * @param EloquentCollection<int, CollectionGroupingModel> $items
 */
function map_to_scalars_then_chain(EloquentCollection $items): void
{
    $_ids = $items->map(static fn (CollectionGroupingModel $m): int => $m->foreign_id)->unique()->values();
    /** @psalm-check-type-exact $_ids = Collection<int, int>&static */

    $_flat = $items->flatMap(static fn (CollectionGroupingModel $m): array => [$m->foreign_id])->unique()->values();
    /** @psalm-check-type-exact $_flat = Collection<int, int>&static */
}

/** @param EloquentCollection<int, CollectionGroupingModel> $items */
function map_to_models_stays_eloquent(EloquentCollection $items): void
{
    $_mapped = $items->map(static fn (CollectionGroupingModel $_m): Vehicle => new Vehicle());
    /** @psalm-check-type-exact $_mapped = EloquentCollection<int, Vehicle>&static */

    // The handler's result keeps Eloquent-only methods available.
    $_loaded = $_mapped->load('customer');
    /** @psalm-check-type-exact $_loaded = EloquentCollection<int, Vehicle>&static */

    $_keyed = $items->mapWithKeys(static fn (CollectionGroupingModel $m): array => [$m->foreign_id => new Vehicle()]);
    /** @psalm-check-type-exact $_keyed = EloquentCollection<int, Vehicle>&static */
}

/** @param EloquentCollection<int, CollectionGroupingModel> $items */
function map_to_non_models_declines(EloquentCollection $items): void
{
    $_nullable = $items->map(static fn (CollectionGroupingModel $m): ?Vehicle => $m->active ? new Vehicle() : null);
    /** @psalm-check-type-exact $_nullable = Collection<int, Vehicle|null> */

    $_mixed = $items->map(static fn (CollectionGroupingModel $m): Vehicle|int => $m->active ? new Vehicle() : $m->foreign_id);
    /** @psalm-check-type-exact $_mixed = Collection<int, Vehicle|int> */

    $_array = $items->map(static fn (CollectionGroupingModel $m): array => [$m->foreign_id]);
    /** @psalm-check-type-exact $_array = Collection<int, array{int}> */

    $_keyedScalars = $items->mapWithKeys(static fn (CollectionGroupingModel $m): array => [$m->foreign_id => $m->active]);
    /** @psalm-check-type-exact $_keyedScalars = Collection<int, bool> */
}
?>
--EXPECTF--
