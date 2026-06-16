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
 * Companion to BelongsToChainSelfTemplateCollapseTest, widening the pinned coverage from
 * belongsTo() to the WHOLE relation-factory family. The #913 collapse is not specific to
 * belongsTo()/withoutGlobalScopes(): every factory stub returns Relation<..., $this>
 * (stubs/common/Database/Eloquent/Concerns/HasRelationships.phpstub), and Psalm 7 does not
 * substitute the `$this` template argument when the returned relation is chained, so chaining
 * ANY Builder/Relation method directly off the raw factory call collapses the receiver to
 * `mixed` (MixedMethodCall + MixedReturnStatement).
 *
 * Each method below pins the CURRENT (broken) behavior; ideally every one of these flips to
 * zero output once the stub fix (`$this` -> `static` + covariant TDeclaringModel) lands.
 *
 * Contrast: chaining off a relation FIRST bound to a typed local works today
 * (tests/Type/tests/Relation/ForwardingHandlerTest.phpt) — the collapse is unique to the raw
 * stub call.
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
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MissingTemplateParam on line %d: Illuminate\Database\Eloquent\Relations\BelongsToMany has missing template params, expecting 4
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MissingTemplateParam on line %d: Illuminate\Database\Eloquent\Relations\MorphToMany has missing template params, expecting 4
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
