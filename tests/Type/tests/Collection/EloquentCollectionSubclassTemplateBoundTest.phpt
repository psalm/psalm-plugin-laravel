--FILE--
<?php declare(strict_types=1);

use App\Models\Part;
use Illuminate\Database\Eloquent\Collection;

/**
 * A userland collection with its own bounded model template (#1699): the inherited chunk()
 * must resolve to the Eloquent stub, not to Support\Collection's `static<int, static>` that
 * puts the chunk into `TModel of Part`.
 *
 * @template TKey of array-key
 * @template TModel of Part
 *
 * @extends Collection<TKey, TModel>
 */
final class PriceCollection extends Collection
{
    /** @return int<0, max> */
    public function chunkedCount(): int
    {
        $count = 0;
        foreach ($this->chunk(3) as $chunk) {
            $count += $chunk->count();
        }

        return $count;
    }
}

/** @param PriceCollection<int, Part> $prices */
function chunk_on_userland_collection(PriceCollection $prices): void
{
    $_chunks = $prices->chunk(3);
    /** @psalm-check-type-exact $_chunks = Illuminate\Support\Collection<int, Collection<int, Part>> */

    $_split = $prices->split(2);
    /** @psalm-check-type-exact $_split = Illuminate\Support\Collection<int, Collection<int, Part>> */

    // A subclass has its own TModel bound, so a map of models widens to the base Eloquent class.
    $_mapped = $prices->map(static fn (Part $p): Part => $p);
    /** @psalm-check-type-exact $_mapped = Collection<int, Part> */

    $_scalars = $prices->map(static fn (Part $p): string => $p->name);
    /** @psalm-check-type-exact $_scalars = Illuminate\Support\Collection<int, string> */
}
?>
--EXPECTF--
