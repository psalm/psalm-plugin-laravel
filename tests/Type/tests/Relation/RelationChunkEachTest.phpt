--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\SpecializationPivot;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * chunk()/chunkById()/chunkByIdDesc()/each()/eachById() on BelongsToMany hydrate the pivot
 * before calling back, so callback parameters carry the pivot intersection.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_belongsToMany_chunk_family(BelongsToMany $relation): void
{
    $relation->chunk(10, function ($rows, $page): void {
        /** @psalm-check-type-exact $rows = \Illuminate\Database\Eloquent\Collection<int, MechanicSpecialization&object{pivot: SpecializationPivot}> */
        /** @psalm-check-type-exact $page = int */
        echo $rows->count(), $page;
    });

    $relation->chunkById(10, function ($rows): void {
        /** @psalm-check-type-exact $rows = \Illuminate\Database\Eloquent\Collection<int, MechanicSpecialization&object{pivot: SpecializationPivot}> */
        echo $rows->count();
    });

    $relation->chunkByIdDesc(10, function ($rows): void {
        /** @psalm-check-type-exact $rows = \Illuminate\Database\Eloquent\Collection<int, MechanicSpecialization&object{pivot: SpecializationPivot}> */
        echo $rows->count();
    });
}

/**
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_belongsToMany_each_family(BelongsToMany $relation): void
{
    $relation->each(function ($model, $index): void {
        /** @psalm-check-type-exact $model = MechanicSpecialization&object{pivot: SpecializationPivot} */
        /** @psalm-check-type-exact $index = int */
        echo $model::class, $index;
    });

    $relation->eachById(function ($model): void {
        /** @psalm-check-type-exact $model = MechanicSpecialization&object{pivot: SpecializationPivot} */
        echo $model::class;
    });
}

/**
 * @param HasManyThrough<Invoice, WorkOrder, Customer> $relation
 */
function test_hasManyThrough_chunk_family(HasManyThrough $relation): void
{
    $relation->chunk(10, function ($rows, $page): void {
        /** @psalm-check-type-exact $rows = \Illuminate\Database\Eloquent\Collection<int, Invoice> */
        /** @psalm-check-type-exact $page = int */
        echo $rows->count(), $page;
    });

    $relation->chunkById(10, function ($rows): void {
        /** @psalm-check-type-exact $rows = \Illuminate\Database\Eloquent\Collection<int, Invoice> */
        echo $rows->count();
    });

    $relation->chunkByIdDesc(10, function ($rows): void {
        /** @psalm-check-type-exact $rows = \Illuminate\Database\Eloquent\Collection<int, Invoice> */
        echo $rows->count();
    });
}

/**
 * @param HasManyThrough<Invoice, WorkOrder, Customer> $relation
 */
function test_hasManyThrough_each_family(HasManyThrough $relation): void
{
    $relation->each(function ($model, $index): void {
        /** @psalm-check-type-exact $model = Invoice */
        /** @psalm-check-type-exact $index = int */
        echo $model::class, $index;
    });

    $relation->eachById(function ($model): void {
        /** @psalm-check-type-exact $model = Invoice */
        echo $model::class;
    });
}
?>
--EXPECTF--
