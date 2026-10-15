--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.3.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\CollectionGroupingModel as Item;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Laravel 13.3.0 rejects a non-positive sliding() size/step and forwards extra times() arguments; the
 * 13.3.0 stub restates those signatures on top of the widened return shapes of the common stub.
 *
 * @param EloquentCollection<int, Item> $items
 */
function sliding_and_times_keep_the_widened_shape(EloquentCollection $items): void
{
    $_windows = $items->sliding(2, 1);
    /** @psalm-check-type-exact $_windows = Collection<int, EloquentCollection<int, Item>> */

    $_split = $items->split(3);
    /** @psalm-check-type-exact $_split = Collection<int, EloquentCollection<int, Item>> */

    $_times = EloquentCollection::times(2, static fn (int $n): int => $n, []);
    /** @psalm-check-type-exact $_times = Collection<int, int> */

    $items->sliding(0);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Collection::sliding expects int<1, max>, but 0 provided
