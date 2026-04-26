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
 * Relation `firstOr()` implementations route `$columns` through the protected
 * `shouldSelect(array $columns)` helper, which hard-rejects bare strings at
 * runtime. The Builder version, by contrast, lets `Arr::wrap()` widen a string
 * into an array — which is why only the Builder stub permits the
 * `non-empty-string` shape.
 *
 * @param BelongsToMany<MechanicSpecialization, Mechanic, SpecializationPivot, 'pivot'> $relation
 */
function test_belongsToMany_firstOr_rejects_string_columns(BelongsToMany $relation): void
{
    $relation->firstOr('id', static function (): never {
        throw new \RuntimeException('Missing specialization');
    });
}

/**
 * @param HasManyThrough<Invoice, WorkOrder, Customer> $relation
 */
function test_hasManyThrough_firstOr_rejects_string_columns(HasManyThrough $relation): void
{
    $relation->firstOr('id', static function (): never {
        throw new \RuntimeException('Missing invoice');
    });
}

?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Relations\BelongsToMany::firstOr expects %s, but 'id' provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Relations\HasManyThrough::firstOr expects %s, but 'id' provided
