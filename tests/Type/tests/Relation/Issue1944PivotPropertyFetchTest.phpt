--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\PivotPropertyFetch;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * End to end for #1944: a pivot column read through the refined TPivotModel.
 */
class User extends Model {}

class Team extends Model
{
    /** @return BelongsToMany<User, $this, Pivot&object{role: string}, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
}

/** @param Pivot&object{role: string} $pivot */
function pivot_shape(Pivot $pivot): string
{
    $role = $pivot->role;
    /** @psalm-trace $role */

    return $role;
}

function declared_shape(Team $team): string
{
    $role = $team->members()->firstOrFail()->pivot->role;
    /** @psalm-trace $role */

    return $role;
}

function with_pivot_columns(WorkOrder $order): void
{
    // The harness has no migrations, so the withPivot() columns read `mixed`.
    $pivot = $order->parts()->firstOrFail()->pivot;
    /** @psalm-trace $pivot */
    $_quantity = $pivot->quantity;
    /** @psalm-trace $_quantity */
}
?>
--EXPECTF--
Trace on line %d: $role: string
Trace on line %d: $role: string
Trace on line %d: $pivot: Illuminate\Database\Eloquent\Relations\Pivot&object{quantity:mixed, unit_price:mixed}
Trace on line %d: $_quantity: mixed
