--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-pivot-schema.xml
--FILE--
<?php declare(strict_types=1);

use App\Models\PivotSchemaOwner;
use App\Models\MechanicSpecialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** @internal */
final class WithPivotSchemaBodyOwner extends Model
{
    /** @return BelongsToMany<MechanicSpecialization, self, Pivot&object{role: string}, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(MechanicSpecialization::class, table: 'pivot_schema_owner_user')
            ->withPivot('role')
            ->withTimestamps();
    }
}

function declaredBody(WithPivotSchemaBodyOwner $owner): void
{
    $relation = $owner->users();
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, WithPivotSchemaBodyOwner, Pivot&object{role: string}, 'pivot'> */
    $relation->get();
}

function directSchema(PivotSchemaOwner $owner): void
{
    $relation = $owner->belongsToMany(MechanicSpecialization::class, table: 'pivot_schema_owner_user')
        ->withPivot('role')
        ->withTimestamps();
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, PivotSchemaOwner, Pivot&object{role: string}, 'pivot'>&static */
    $relation->get();
}

function morphSchema(PivotSchemaOwner $owner): void
{
    $relation = $owner->morphToMany(MechanicSpecialization::class, 'specializable', table: 'pivot_schema_morph')
        ->withPivot('role')
        ->withTimestamps();
    /** @psalm-check-type-exact $relation = MorphToMany<MechanicSpecialization, PivotSchemaOwner, MorphPivot&object{role: string}, 'pivot'>&static */
    $relation->get();
}

function externalSchema(PivotSchemaOwner $owner): void
{
    $relation = $owner->users();
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, PivotSchemaOwner, Pivot&object{role: string}, 'pivot'> */
    $relation->get();
}

function namedFactoryAndArrayColumns(PivotSchemaOwner $owner): void
{
    $relation = $owner->belongsToMany(MechanicSpecialization::class, table: 'pivot_schema_named')
        ->withPivot(columns: ['active', 'score']);
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, PivotSchemaOwner, Pivot&object{active: bool, score: int}, 'pivot'> */
    $relation->get();
}

function variadicAndRepeatedColumns(PivotSchemaOwner $owner): void
{
    $relation = $owner->belongsToMany(MechanicSpecialization::class, table: 'pivot_schema_named')
        ->withPivot('role', 'active')
        ->withPivot('score', 'role');
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, PivotSchemaOwner, Pivot&object{score: int, role: string, active: bool}, 'pivot'> */
    $relation->get();
}


/** @internal */
abstract class WithPivotSchemaUnknownFactory
{
    /**
     * @return BelongsToMany<MechanicSpecialization, Model, Pivot, 'pivot'>
     * @psalm-impure
     */
    abstract public function belongsToMany(string $related, string $table): BelongsToMany;

    /** @return BelongsToMany<MechanicSpecialization, Model, Pivot, 'pivot'> */
    public function selected(): BelongsToMany
    {
        $relation = $this->belongsToMany(MechanicSpecialization::class, 'pivot_schema_owner_user')
            ->withPivot('role');
        /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, Model, Pivot&object{role: mixed}, 'pivot'> */

        return $relation;
    }
}

function unknownFactoryDoesNotTrustSchema(WithPivotSchemaUnknownFactory $factory): void
{
    $relation = $factory->selected();
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, Model, Pivot, 'pivot'> */
    $relation->get();
}

function nullableAndUnknownColumns(PivotSchemaOwner $owner): void
{
    $relation = $owner->belongsToMany(MechanicSpecialization::class, table: 'pivot_schema_owner_user')
        ->withPivot('note', 'missing_column');
    /** @psalm-check-type-exact $relation = BelongsToMany<MechanicSpecialization, PivotSchemaOwner, Pivot&object{note: string|null, missing_column: mixed}, 'pivot'> */
    $relation->get();
}

?>
--EXPECTF--
