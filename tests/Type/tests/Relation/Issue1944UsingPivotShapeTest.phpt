--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * `using(X::class)` + `withPivot()` refines the custom pivot to `X&object{col: T}` (#1944), whatever
 * the call order. The class's own `@property` wins; a pivot class this harness has not registered has
 * unproven casts, so its other columns read `mixed`.
 */
class User extends Model {}

class Plain extends Pivot {}

/** @property int $role */
class Described extends Pivot {}

class OddDates extends Pivot
{
    /** @return list<string> */
    #[\Override]
    public function getDates(): array
    {
        return ['seen_at'];
    }
}

class TaggedPivot extends MorphPivot {}

class Team extends Model
{
    public function order(): void
    {
        $_first = $this->belongsToMany(User::class)->using(Plain::class)->withPivot('role');
        /** @psalm-trace $_first */

        $_last = $this->belongsToMany(User::class)->withPivot('role')->using(Plain::class);
        /** @psalm-trace $_last */

        $_accumulated = $this->belongsToMany(User::class)->withPivot('a')->using(Plain::class)->where('x', 1)->withPivot('b', 'a');
        /** @psalm-trace $_accumulated */

        // The outermost using() runs last and wins.
        $_outer = $this->belongsToMany(User::class)->using(Plain::class)->withPivot('role')->using(Described::class);
        /** @psalm-trace $_outer */

        $_morph = $this->morphToMany(User::class, 'taggable')->using(TaggedPivot::class)->withPivot('weight');
        /** @psalm-trace $_morph */

        $_explicit_pivot = $this->belongsToMany(User::class)->using(Pivot::class)->withPivot('role');
        /** @psalm-trace $_explicit_pivot */
    }

    public function declared(): void
    {
        $_declared = $this->belongsToMany(User::class)->using(Described::class)->withPivot('role', 'other');
        /** @psalm-trace $_declared */

        // A class that may convert dates its own way proves no column type.
        $_odd = $this->belongsToMany(User::class)->withPivot('seen_at', 'note')->using(OddDates::class);
        /** @psalm-trace $_odd */
    }

    /** @param class-string<Plain> $class */
    public function declines(string $class): void
    {
        // A non-literal using() leaves the pivot class unknown.
        $_dynamic = $this->belongsToMany(User::class)->using($class)->withPivot('role');
        /** @psalm-trace $_dynamic */

        $_no_columns = $this->belongsToMany(User::class)->using(Plain::class);
        /** @psalm-trace $_no_columns */
    }
}

/** @param BelongsToMany<User, Team, Plain, 'pivot'> $relation */
function hidden_receiver_keeps_class(BelongsToMany $relation): void
{
    // The receiver's chain is not visible: a declared custom pivot stays as is.
    $_hidden = $relation->withPivot('role');
    /** @psalm-trace $_hidden */

    $_using_hidden = $relation->using(Described::class);
    /** @psalm-trace $_using_hidden */
}
?>
--EXPECTF--
Trace on line %d: $_first: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain&object{role:mixed}, 'pivot'>&static
Trace on line %d: $_last: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain&object{role:mixed}, 'pivot'>
Trace on line %d: $_accumulated: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain&object{a:mixed, b:mixed}, 'pivot'>
Trace on line %d: $_outer: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Described&object{role:int}, 'pivot'>&static
Trace on line %d: $_morph: Illuminate\Database\Eloquent\Relations\MorphToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\TaggedPivot&object{weight:mixed}, 'pivot'>&static
Trace on line %d: $_explicit_pivot: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed}, 'pivot'>&static
Trace on line %d: $_declared: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Described&object{role:int, other:mixed}, 'pivot'>&static
Trace on line %d: $_odd: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\OddDates&object{seen_at:mixed, note:mixed}, 'pivot'>
Trace on line %d: $_dynamic: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain, 'pivot'>&static
Trace on line %d: $_no_columns: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain, 'pivot'>&static
Trace on line %d: $_hidden: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain, 'pivot'>&static
Trace on line %d: $_using_hidden: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Described, 'pivot'>&static
