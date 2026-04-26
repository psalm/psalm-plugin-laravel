--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\SpecializationPivot;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Relation firstOr() implementations pass selected columns through helpers
 * that require arrays, unlike the base Eloquent builder.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_belongsToMany_firstOr_rejects_string_columns(BelongsToMany $relation): void
{
    $relation->firstOr('id', static function (): never {
        throw new \RuntimeException('Missing specialization');
    });
}

function test_hasManyThrough_firstOr_rejects_string_columns(): void
{
    /** @var HasManyThrough<WorkOrder, Vehicle, Customer> $relation */
    $relation = (new Customer())->workOrders();
    $relation->firstOr('id', static function (): never {
        throw new \RuntimeException('Missing work order');
    });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Relations\BelongsToMany::firstOr expects %s, but 'id' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Relations\HasManyThrough::firstOr expects %s, but 'id' provided
