--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * `withTimestamps()` adds the created/updated columns Laravel selects to the pivot shape (#1944).
 * They read as the app's date type only where the pivot hydration provably casts them, else `mixed`.
 * Inline models have no migration here: every other column reads `mixed`.
 */
class User extends Model {}

class Stamp extends Pivot {}

class Team extends Model
{
    public function defaults(): void
    {
        $_default = $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
        /** @psalm-trace $_default */

        // Columns accumulate in call order, like Laravel's `pivotColumns`.
        $_first = $this->belongsToMany(User::class)->withTimestamps()->withPivot('role');
        /** @psalm-trace $_first */

        $_twice = $this->belongsToMany(User::class)->withTimestamps()->withTimestamps('made_at');
        /** @psalm-trace $_twice */

        $_morph = $this->morphToMany(User::class, 'taggable')->withTimestamps();
        /** @psalm-trace $_morph */
    }

    public function explicitNames(): void
    {
        // The pivot casts the PARENT's created/updated names, not these: plain columns.
        $_named = $this->belongsToMany(User::class)->withTimestamps('made_at', 'changed_at');
        /** @psalm-trace $_named */

        $_named_args = $this->belongsToMany(User::class)->withTimestamps(updatedAt: 'changed_at');
        /** @psalm-trace $_named_args */

        // `false` disables the column (no column in either Laravel version), `null` is the default.
        $_disabled = $this->belongsToMany(User::class)->withTimestamps(false, 'changed_at');
        /** @psalm-trace $_disabled */

        $_null = $this->belongsToMany(User::class)->withTimestamps(null, 'changed_at');
        /** @psalm-trace $_null */

        // A dynamic name is unknown: only the other column is added.
        $_dynamic = $this->belongsToMany(User::class)->withTimestamps($this->dynamic(), 'changed_at');
        /** @psalm-trace $_dynamic */
    }

    public function declines(): void
    {
        $_off = $this->belongsToMany(User::class)->withTimestamps(false, false);
        /** @psalm-trace $_off */

        $_kept = $this->belongsToMany(User::class)->withPivot('role')->withTimestamps(false, false);
        /** @psalm-trace $_kept */
    }

    private function dynamic(): string
    {
        return 'x';
    }
}

class Renamed extends Model
{
    /** @psalm-suppress MissingClassConstType typed constants need PHP 8.3 */
    public const CREATED_AT = 'made_at';

    /** @psalm-suppress MissingClassConstType */
    public const UPDATED_AT = 'changed_at';

    public function gate(): void
    {
        // A plain pivot switches its casts on from its OWN `created_at`, not the parent's `made_at`.
        $_unprovable = $this->belongsToMany(User::class)->withTimestamps();
        /** @psalm-trace $_unprovable */

        $_opened = $this->belongsToMany(User::class)->withTimestamps()->withPivot('created_at');
        /** @psalm-trace $_opened */

        // A using() pivot switches them on from the parent's created-at column, but this inline pivot class
        // is not registered: its casts are unproven, so its columns stay `mixed`.
        $_using = $this->belongsToMany(User::class)->using(Stamp::class)->withTimestamps();
        /** @psalm-trace $_using */

        $_using_last = $this->belongsToMany(User::class)->withTimestamps()->using(Stamp::class);
        /** @psalm-trace $_using_last */
    }
}

class NoCreatedAt extends Model
{
    /** @psalm-suppress MissingClassConstType */
    public const CREATED_AT = null;

    public function disabled(): void
    {
        // Laravel falls back to `created_at` for a disabled constant; only `updated_at` is a date.
        $_disabled = $this->belongsToMany(User::class)->withTimestamps();
        /** @psalm-trace $_disabled */
    }
}

class Overridden extends Model
{
    #[\Override]
    public function getCreatedAtColumn(): string
    {
        return 'when_created';
    }

    public function unknown(): void
    {
        // The parent's names cannot be proven: explicit names only, and nothing when none is given.
        $_default = $this->belongsToMany(User::class)->withTimestamps();
        /** @psalm-trace $_default */

        $_explicit = $this->belongsToMany(User::class)->withTimestamps('made_at', 'changed_at');
        /** @psalm-trace $_explicit */
    }
}

/** @param BelongsToMany<User, Team, Pivot, 'pivot'> $relation */
function receiver_chain_is_hidden(BelongsToMany $relation): void
{
    // The slot may hide a using() pivot: names are known, the date casts are not provable.
    $_hidden = $relation->withTimestamps();
    /** @psalm-trace $_hidden */
}
?>
--EXPECTF--
Trace on line %d: $_default: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed, created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $_first: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null, role:mixed}, 'pivot'>
Trace on line %d: $_twice: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null, made_at:mixed}, 'pivot'>
Trace on line %d: $_morph: Illuminate\Database\Eloquent\Relations\MorphToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\MorphPivot&object{created_at:Illuminate\Support\Carbon|null, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $_named: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{made_at:mixed, changed_at:mixed}, 'pivot'>
Trace on line %d: $_named_args: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, changed_at:mixed}, 'pivot'>
Trace on line %d: $_disabled: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{changed_at:mixed}, 'pivot'>
Trace on line %d: $_null: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:Illuminate\Support\Carbon|null, changed_at:mixed}, 'pivot'>
Trace on line %d: $_dynamic: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{changed_at:mixed}, 'pivot'>
Trace on line %d: $_off: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_kept: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team&static, Illuminate\Database\Eloquent\Relations\Pivot&object{role:mixed}, 'pivot'>
Trace on line %d: $_unprovable: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Renamed&static, Illuminate\Database\Eloquent\Relations\Pivot&object{made_at:mixed, changed_at:mixed}, 'pivot'>
Trace on line %d: $_opened: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Renamed&static, Illuminate\Database\Eloquent\Relations\Pivot&object{made_at:Illuminate\Support\Carbon|null, changed_at:Illuminate\Support\Carbon|null, created_at:mixed}, 'pivot'>
Trace on line %d: $_using: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Renamed&static, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Stamp&object{made_at:mixed, changed_at:mixed}, 'pivot'>&static
Trace on line %d: $_using_last: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Renamed&static, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Stamp&object{made_at:mixed, changed_at:mixed}, 'pivot'>
Trace on line %d: $_disabled: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\NoCreatedAt&static, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:mixed, updated_at:Illuminate\Support\Carbon|null}, 'pivot'>
Trace on line %d: $_default: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Overridden&static, Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>&static
Trace on line %d: $_explicit: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Overridden&static, Illuminate\Database\Eloquent\Relations\Pivot&object{made_at:mixed, changed_at:mixed}, 'pivot'>
Trace on line %d: $_hidden: Illuminate\Database\Eloquent\Relations\BelongsToMany<Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\User, Tests\Psalm\LaravelPlugin\Sandbox\WithTimestampsPivot\Team, Illuminate\Database\Eloquent\Relations\Pivot&object{created_at:mixed, updated_at:mixed}, 'pivot'>
