--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/913
 *
 * Companion to BelongsToChainSelfTemplateCollapseTest, widening regression coverage from
 * belongsTo() to the WHOLE relation-factory family. The #913 fix is live on this branch
 * (`$this` -> `static` in the HasRelationships factory returns + covariant TDeclaringModel),
 * so chaining a Builder/Relation method directly off a raw factory call no longer collapses to
 * `mixed`. Every non-pivot factory below resolves cleanly (zero output).
 *
 * Two relations still emit one diagnostic each: the pivot factories belongsToMany() and
 * morphToMany() resolve to the stub's 2-arg BelongsToMany/MorphToMany, but those classes declare
 * 4 templates with the last two (TPivotModel/TAccessor) defaulted, and Psalm 7 does not honor
 * @template defaults for a partial generic -> MissingTemplateParam. This is the documented pivot
 * limitation (tracked upstream: vimeo/psalm#5407 and PR vimeo/psalm#11790); it is intentionally
 * reported rather than silently completed, since pinning the accessor would break ->as() overrides.
 */
class Tag extends Model
{
}

class Mechanic extends Model
{
}

class Car extends Model
{
}

class Owner extends Model
{
}

class Garage extends Model
{
    /** @return HasOne<Mechanic, self> */
    public function lead(): HasOne
    {
        return $this->hasOne(Mechanic::class)->withoutGlobalScopes();
    }

    /** @return HasMany<Mechanic, self> */
    public function mechanics(): HasMany
    {
        return $this->hasMany(Mechanic::class)->withoutGlobalScopes();
    }

    /** @return MorphOne<Mechanic, self> */
    public function leadMorph(): MorphOne
    {
        return $this->morphOne(Mechanic::class, 'morphable')->withoutGlobalScopes();
    }

    /** @return MorphMany<Mechanic, self> */
    public function mechanicsMorph(): MorphMany
    {
        return $this->morphMany(Mechanic::class, 'morphable')->withoutGlobalScopes();
    }

    /** @return HasOneThrough<Car, Mechanic, self> */
    public function leadCar(): HasOneThrough
    {
        return $this->hasOneThrough(Car::class, Mechanic::class)->withoutGlobalScopes();
    }

    /** @return HasManyThrough<Car, Mechanic, self> */
    public function cars(): HasManyThrough
    {
        return $this->hasManyThrough(Car::class, Mechanic::class)->withoutGlobalScopes();
    }

    /** @return BelongsToMany<Tag, self> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withoutGlobalScopes();
    }

    /** @return MorphToMany<Tag, self> */
    public function tagsMorph(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withoutGlobalScopes();
    }
}

?>
--EXPECTF--
MissingTemplateParam on line %d: Illuminate\Database\Eloquent\Relations\BelongsToMany has missing template params, expecting 4
MissingTemplateParam on line %d: Illuminate\Database\Eloquent\Relations\MorphToMany has missing template params, expecting 4
