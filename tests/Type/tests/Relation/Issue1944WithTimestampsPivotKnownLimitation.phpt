--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivotLimit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Known limitation (#1944, decisions.md "`withPivot()` columns refine TPivotModel"): `when()` returns the receiver's previous static type, so the date
 * types the first `withTimestamps()` inferred survive a `using()` the callback adds, although at runtime the
 * `using()` pivot hydrates them as plain strings. The plugin cannot tell its own earlier inference from a
 * user-declared shape. Today's output is pinned; the dates should be `mixed`.
 */
class User extends Model {}

class Team extends Model
{
    public function run(): void
    {
        $_limit = $this->belongsToMany(User::class)
            ->withTimestamps()
            ->when(true, /** @param BelongsToMany<User, Team, Pivot, 'pivot'> $relation */ fn (BelongsToMany $relation): BelongsToMany => $relation->using(Pivot::class))
            ->withTimestamps();
        /** @psalm-trace $_limit */
    }
}
?>
--EXPECTF--
Trace on line %d: $_limit: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivotLimit\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivotLimit\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
