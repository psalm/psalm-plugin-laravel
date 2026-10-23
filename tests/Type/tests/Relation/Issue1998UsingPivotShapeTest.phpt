--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * `using(X::class)` + `withPivot()` / `withTimestamps()` refines the custom pivot to `X&object{col: T}` (#1998),
 * whatever the call order. Psalm reads a native or provider-backed property before an object shape but an
 * `@property`-only column from the shape, so each column carries X's own answer. Inline classes are never in
 * the model registry here: past a native property or an `@property`, a column reads `mixed`. Traces, not
 * check-type: Psalm compares object shapes inside an intersection by name only.
 */
class User extends Model {}

class Plain extends Pivot {}

/** @property int $role */
class Described extends Pivot {}

class Native extends Pivot
{
    public ?string $label = null;

    protected function getScoreAttribute(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

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

/**
 * @property-read static $peer
 * @property-read self $me
 * @property-read list<static> $peers
 */
class Relative extends Pivot {}

/** @template T of Model */
class GenericMembership extends Pivot {}

class Team extends Model
{
    // Issue reproducer.
    /** @return BelongsToMany<User, self, Described&object{role: int}, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(Described::class)->withPivot('role');
    }

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

        $_stamps = $this->belongsToMany(User::class)->using(Plain::class)->withTimestamps();
        /** @psalm-trace $_stamps */

        $_no_columns = $this->belongsToMany(User::class)->using(Plain::class);
        /** @psalm-trace $_no_columns */
    }

    public function owns(): void
    {
        // `@property` only: the shape is what Psalm reads, so it carries the declared type.
        $_property = $this->belongsToMany(User::class)->using(Described::class)->withPivot('role', 'other');
        /** @psalm-trace $_property */

        // A native property keeps its declared type; the accessor is unproven without the registry.
        $_native = $this->belongsToMany(User::class)->using(Native::class)->withPivot('label', 'score');
        /** @psalm-trace $_native */

        // A class that may convert dates its own way proves no raw column type.
        $_odd = $this->belongsToMany(User::class)->withPivot('seen_at', 'note')->using(OddDates::class);
        /** @psalm-trace $_odd */
    }

    public function relative(): void
    {
        // Relative types are bound to the pivot class, as a direct read binds them; unbound they never settle.
        $_relative = $this->belongsToMany(User::class)->using(Relative::class)->withPivot('peer', 'me', 'peers');
        /** @psalm-trace $_relative */

        $_relative_read = $_relative->first()?->pivot->peer;
        /** @psalm-trace $_relative_read */

        // A templated pivot class would lose its parameters when rebuilt from its name: declined.
        $_generic_first = $this->belongsToMany(User::class)->using(GenericMembership::class)->withPivot('role');
        /** @psalm-trace $_generic_first */

        $_generic_last = $this->belongsToMany(User::class)->withPivot('role')->using(GenericMembership::class);
        /** @psalm-trace $_generic_last */
    }

    /** @param class-string<Plain> $class */
    public function dynamic(string $class): void
    {
        // A non-literal using() leaves the pivot class unknown: the chain is not visible, the slot is what it is.
        $_dynamic = $this->belongsToMany(User::class)->using($class)->withPivot('role');
        /** @psalm-trace $_dynamic */

        // The outermost using() wins even when it is the dynamic one.
        $_dynamic_outer = $this->belongsToMany(User::class)->using(Described::class)->withPivot('role')->using($class);
        /** @psalm-trace $_dynamic_outer */
    }
}

/** @param BelongsToMany<User, Team, GenericMembership<User>, 'pivot'> $relation */
function hidden_generic_receiver(BelongsToMany $relation): void
{
    $_hidden_generic = $relation->withPivot('role');
    /** @psalm-trace $_hidden_generic */
}

/** @param BelongsToMany<User, Team, Described, 'pivot'> $relation */
function hidden_receiver(BelongsToMany $relation): void
{
    // The receiver's chain is not visible, but its slot is exactly one custom pivot: its own types only.
    $_hidden = $relation->withPivot('role', 'other');
    /** @psalm-trace $_hidden */

    $_hidden_stamps = $relation->withTimestamps();
    /** @psalm-trace $_hidden_stamps */

    // using() on a hidden receiver is declined: the stub result.
    $_using_hidden = $relation->using(Plain::class);
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
Trace on line %d: $_stamps: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain&object{created_at:mixed, updated_at:mixed}, 'pivot'>&static
Trace on line %d: $_no_columns: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain, 'pivot'>&static
Trace on line %d: $_property: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Described&object{role:int, other:mixed}, 'pivot'>&static
Trace on line %d: $_native: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Native&object{label:null|string, score:mixed}, 'pivot'>&static
Trace on line %d: $_odd: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\OddDates&object{seen_at:mixed, note:mixed}, 'pivot'>
Trace on line %d: $_relative: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Relative&object{peer:Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Relative, me:Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Relative, peers:list<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Relative>}, 'pivot'>&static
Trace on line %d: $_relative_read: Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Relative|null
Trace on line %d: $_generic_first: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\GenericMembership<Illuminate\Database\Eloquent\Model>, 'pivot'>&static
Trace on line %d: $_generic_last: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\GenericMembership<Illuminate\Database\Eloquent\Model>, 'pivot'>&static
Trace on line %d: $_dynamic: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain&object{role:mixed}, 'pivot'>&static
Trace on line %d: $_dynamic_outer: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team&static, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain, 'pivot'>&static
Trace on line %d: $_hidden_generic: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\GenericMembership<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User>, 'pivot'>&static
Trace on line %d: $_hidden: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Described&object{role:int, other:mixed}, 'pivot'>
Trace on line %d: $_hidden_stamps: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Described&object{created_at:mixed, updated_at:mixed}, 'pivot'>
Trace on line %d: $_using_hidden: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\User, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Team, Tests\Psalm\LaravelPlugin\Sandbox\UsingPivotShape\Plain, 'pivot'>&static
