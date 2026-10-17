--FILE--
<?php declare(strict_types=1);

use App\Models\Issue1944Owner;
use App\Models\Issue1944Pivot;
use App\Models\Issue1944Related;
use App\Models\Part;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

function direct(Issue1944Owner $owner): void
{
    $relation = $owner->belongsToMany(Issue1944Related::class, table: 'owner_related')
        ->withPivot('role')
        ->withPivot('active', 'score');
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: mixed, active: mixed, score: mixed}, 'pivot'> */
    $relation->get();
}

function external(Issue1944Owner $owner): void
{
    $relation = $owner->related();
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: mixed}, 'pivot'> */
    $relation->get();
}

function arrayColumns(Issue1944Owner $owner): void
{
    $relation = $owner->belongsToMany(Issue1944Related::class, table: 'owner_related')
        ->withPivot(['role', 'active']);
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: mixed, active: mixed}, 'pivot'> */
    $relation->get();
}

function customPivot(Issue1944Owner $owner): void
{
    $relation = $owner->belongsToMany(Issue1944Related::class, table: 'owner_related')
        ->using(Issue1944Pivot::class)
        ->withPivot('role');
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Issue1944Pivot, 'pivot'>&static */
    $relation->get();
}

function externalCustomPivot(Issue1944Owner $owner): void
{
    $relation = $owner->customPivot();
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Issue1944Pivot, 'pivot'> */
    $relation->get();
}

function externalCustomAccessor(Issue1944Owner $owner): void
{
    $relation = $owner->customAccessor();
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: mixed}, 'membership'> */
    $relation->get();
}

function variableHeldRepeated(Issue1944Owner $owner): void
{
    $relation = $owner->belongsToMany(Issue1944Related::class, table: 'owner_related')->withPivot('role');
    $relation = $relation->withPivot('active');
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: mixed, active: mixed}, 'pivot'> */
    $relation->get();
}

/** @extends BelongsToMany<Issue1944Related, Issue1944Owner, Pivot, 'pivot'> */
final class Issue1944CustomRelation extends BelongsToMany
{
}

function customRelationSubclass(Issue1944CustomRelation $relation): void
{
    $result = $relation->withPivot('role');
    /** @psalm-check-type-exact $result = Issue1944CustomRelation */
    $result->get();
}

/**
 * @param BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: string}, 'pivot'> $relation
 */
function preserveExistingKnownShape(BelongsToMany $relation): void
{
    $result = $relation->withPivot('missing_column');
    /** @psalm-check-type-exact $result = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: string, missing_column: mixed}, 'pivot'> */
    $result->get();
}

function dynamicColumn(Issue1944Owner $owner): void
{
    $relation = $owner->dynamicPivot();
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot, 'pivot'> */
    $relation->get();
}

function unknownInterveningCall(Issue1944Owner $owner): void
{
    $relation = $owner->belongsToMany(Issue1944Related::class, table: 'owner_related')
        ->wherePivot('active', true)
        ->withPivot('role');
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: mixed}, 'pivot'>&static */
    $relation->get();
}

function unsafeColumn(Issue1944Owner $owner): void
{
    $relation = $owner->belongsToMany(Issue1944Related::class, table: 'owner_related')->withPivot('settings->role');
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot, 'pivot'>&static */
    $relation->get();
}

function dynamicTable(Issue1944Owner $owner, string $table): void
{
    $relation = $owner->belongsToMany(Issue1944Related::class, $table)->withPivot('role');
    /** @psalm-check-type-exact $relation = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot&object{role: mixed}, 'pivot'> */
    $relation->get();
}

/**
 * @param BelongsToMany<Issue1944Related, Issue1944Owner, Pivot, 'pivot'>|BelongsToMany<Part, Issue1944Owner, Pivot, 'pivot'> $relation
 */
function unionReceiver(BelongsToMany $relation): void
{
    $result = $relation->withPivot('role');
    /** @psalm-check-type-exact $result = BelongsToMany<Issue1944Related, Issue1944Owner, Pivot, 'pivot'>&static|BelongsToMany<Part, Issue1944Owner, Pivot, 'pivot'>&static */
    $result->get();
}

?>
--EXPECTF--
