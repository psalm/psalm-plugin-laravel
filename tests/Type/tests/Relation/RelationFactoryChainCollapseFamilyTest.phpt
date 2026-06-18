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
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/913
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1088
 *
 * Companion to BelongsToChainSelfTemplateCollapseTest, widening regression coverage from
 * belongsTo() to the WHOLE relation-factory family. The #913 fix in PR #1055 (`$this` -> `static`
 * in the HasRelationships factory returns + covariant TDeclaringModel) stops a chained factory
 * call from collapsing to `mixed`; the #1088 fix fills the pivot factories' returns to the full
 * 4-param shape, so belongsToMany()/morphToMany() no longer trip MissingTemplateParam either.
 * Every factory below now resolves cleanly (zero output).
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

    /** @return BelongsToMany<Tag, self, Pivot, 'pivot'> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withoutGlobalScopes();
    }

    /** @return MorphToMany<Tag, self, MorphPivot, 'pivot'> */
    public function tagsMorph(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withoutGlobalScopes();
    }
}

?>
--EXPECTF--
