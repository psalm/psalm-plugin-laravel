--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * `withPivot()` adds its literal columns to TPivotModel (#1944), so a declared pivot shape is
 * accepted. Inline models have no migration here: every column reads `mixed`.
 */
class User extends Model {}

class Membership extends Pivot {}

class Team extends Model
{
    // Issue reproducer.
    /** @return BelongsToMany<User, self, Pivot&object{role: string}, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return MorphToMany<User, self, MorphPivot&object{role: string}, 'pivot'> */
    public function taggedUsers(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'taggable')->withPivot('role');
    }

    // A `$table` that may name a model class can switch the pivot class: decline.
    /** @return BelongsToMany<User, self, Pivot, 'pivot'> */
    public function viaPivotModel(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, Membership::class)->withPivot('role');
        /** @psalm-trace $relation */

        return $relation;
    }
}

/** @param BelongsToMany<User, Team, Pivot, 'pivot'> $relation */
function merges(BelongsToMany $relation): void
{
    $_merged = $relation->withPivot('a')->withPivot(['b'])->withPivot('a', 'c');
    /** @psalm-trace $_merged */

    // Forwarded builder calls and `$this` returns keep the refined slot.
    $_chained = $relation->withPivot('role')->where('active', true)->withTimestamps()->orderByPivot('role');
    /** @psalm-trace $_chained */
}

/** @param BelongsToMany<User, Team, Pivot, 'pivot'> $relation */
function skips_dynamic_and_dotted(BelongsToMany $relation, string $column): void
{
    $_skipped = $relation->withPivot('ok', $column, 'team_user.x');
    /** @psalm-trace $_skipped */
    // Laravel reads only an array first argument.
    $_array_only = $relation->withPivot(['a'], 'b');
    /** @psalm-trace $_array_only */
    // A dynamic first argument may be that array: nothing else is known.
    $_none = $relation->withPivot($column, 'ok');
    /** @psalm-trace $_none */
    // An unpacked item may overwrite a key, so the whole array is dropped.
    $_unpacked = $relation->withPivot(['key' => 'gone', ...['key' => 'present']]);
    /** @psalm-trace $_unpacked */
    // Laravel merges with array_merge(): a string key overwrites an earlier call's column.
    $_string_keys = $relation->withPivot(['key' => 'role'])->withPivot(['key' => 'rank']);
    /** @psalm-trace $_string_keys */
    $_string_key_unpacked = $relation->withPivot(['key' => 'role'])->withPivot([...['key' => 'rank']]);
    /** @psalm-trace $_string_key_unpacked */
}

/** @param BelongsToMany<User, Team, Membership, 'pivot'> $relation */
function custom_pivot_declines(BelongsToMany $relation): void
{
    $_custom = $relation->withPivot('role');
    /** @psalm-trace $_custom */
}

/** @param MorphToMany<User, Team, MorphPivot, 'pivot'> $relation */
function morph_keeps_class(MorphToMany $relation): void
{
    $_morph = $relation->withPivot('role');
    /** @psalm-trace $_morph */
}
?>
--EXPECTF--
Trace on line %d: $relation: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_merged: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed, b:mixed, c:mixed}, 'pivot'>
Trace on line %d: $_chained: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed}, 'pivot'>&static
Trace on line %d: $_skipped: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot&object{ok:mixed}, 'pivot'>
Trace on line %d: $_array_only: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot&object{a:mixed}, 'pivot'>
Trace on line %d: $_none: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_unpacked: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_string_keys: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_string_key_unpacked: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_custom: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Membership, 'pivot'>&static
Trace on line %d: $_morph: Illuminate\Database\Eloquent\Relations\MorphToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\WithPivotShape\Team, Illuminate\Database\Eloquent\Relations\MorphPivot&object{role:mixed}, 'pivot'>
