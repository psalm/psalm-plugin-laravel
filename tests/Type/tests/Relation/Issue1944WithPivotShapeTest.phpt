--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\Issue1944Repro;

use App\Models\Part;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The reproduction of https://github.com/psalm/psalm-plugin-laravel/issues/1944.
 *
 * The empty `--EXPECTF--` is the oracle: `withPivot()` refines the pivot slot, so the declared
 * `Pivot&object{role: string}` return is accepted (the column is `mixed` here: the type-test harness
 * runs no migrations, and `mixed` satisfies a declared `string`), and `->pivot->role` resolves.
 */
final class Team extends Model
{
    /** @return BelongsToMany<Part, self, Pivot&object{role: string}, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Part::class, 'team_part')
            ->withPivot('role')
            ->withTimestamps();
    }
}

/** @param Pivot&object{role: string} $p */
function f(Pivot $p): string
{
    return $p->role;
}

function g(Team $team): string
{
    $role = $team->members()->firstOrFail()->pivot->role;
    /** @psalm-check-type-exact $role = string */

    return $role;
}
?>
--EXPECTF--
