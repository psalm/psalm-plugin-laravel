--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

// Laravel bounds saveMany()'s container by Collection<array-key, TRelatedModel>. Collection's
// TKey is invariant, so an int-keyed Eloquent collection used to fail that bound with
// ArgumentTypeCoercion and lose its type on the return.

/**
 * @param EloquentCollection<int, MechanicSpecialization> $specializations
 * @return EloquentCollection<int, MechanicSpecialization>
 */
function save_eloquent_collection(Mechanic $mechanic, EloquentCollection $specializations): EloquentCollection
{
    /** @psalm-check-type-exact $saved = EloquentCollection<int, MechanicSpecialization> */
    $saved = $mechanic->specializations()->saveMany($specializations);

    return $saved;
}

/**
 * @param Collection<string, MechanicSpecialization> $specializations
 * @return Collection<string, MechanicSpecialization>
 */
function save_quietly_string_keyed(Mechanic $mechanic, Collection $specializations): Collection
{
    /** @psalm-check-type-exact $saved = Collection<string, MechanicSpecialization> */
    $saved = $mechanic->specializations()->saveManyQuietly($specializations);

    return $saved;
}

/** @return list{MechanicSpecialization} */
function save_array(Mechanic $mechanic): array
{
    /** @psalm-check-type-exact $saved = list{MechanicSpecialization} */
    $saved = $mechanic->specializations()->saveMany([new MechanicSpecialization()]);

    return $saved;
}
?>
--EXPECTF--
