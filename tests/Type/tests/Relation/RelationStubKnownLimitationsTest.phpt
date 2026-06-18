--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: psalm-tester batches every .phpt into one analysis, so the fixture
// class names must not collide with the other Relation tests.
namespace Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Pins the two known Psalm-7 limitations that the #913 fix exposes. Both used to be hidden behind
 * the `mixed` collapse; once relation chains resolve to real types, Psalm type-checks the relation
 * method return expressions and these surface. Neither is a plugin bug. This test asserts the
 * CURRENT (limited) output so it flips loudly when the upstream fix lands.
 *
 * Limitation 1 (pivot relations, MissingTemplateParam): belongsToMany()/morphToMany() resolve to
 * the stub's 2-arg BelongsToMany<TRelated, static>, but the class declares 4 templates (the last
 * two, TPivotModel/TAccessor, defaulted). Psalm 7 does not honor @template defaults for a partial
 * generic, so a chained pivot relation trips MissingTemplateParam. Laravel itself ships under-filled
 * @returns (3-of-4) that pass under PHPStan/Larastan, which honor the defaults. The return must NOT
 * be "completed" to 4 args: pinning TAccessor='pivot' false-positives legitimate ->as('name')
 * overrides (Psalm's @psalm-this-out generic narrowing is also inert). Tracked upstream:
 * vimeo/psalm#5407 (support template default types) and PR vimeo/psalm#11790.
 *
 * Limitation 2 (morphTo narrowed related type): morphTo()'s target is resolved at runtime via the
 * morph map, so the stub can only return MorphTo<Model, static>. An app @return that narrows the
 * related side (a union or intersection) is more specific than the stub can prove, so it mismatches.
 * Inherent to polymorphic relations; resolvable app-side (widen the annotation) or by a future
 * morph-map-aware resolver in the plugin, not by a stub or Psalm variance change.
 */
class Tag extends Model
{
}

class Post extends Model
{
    /** @return BelongsToMany<Tag, self> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }
}

class Comment extends Model
{
}

class Article extends Model
{
}

class Reaction extends Model
{
    /** @return MorphTo<Comment|Article, self> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}

?>
--EXPECTF--
MissingTemplateParam on line %d: Illuminate\Database\Eloquent\Relations\BelongsToMany has missing template params, expecting 4
MissingTemplateParam on line %d: Illuminate\Database\Eloquent\Relations\BelongsToMany has missing template params, expecting 4
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Article|Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Comment, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction>' for Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction::target is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Article|Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Comment, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction>' for Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction::target
