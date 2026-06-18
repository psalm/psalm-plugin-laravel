--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: psalm-tester batches every .phpt into one analysis, so the fixture
// class names must not collide with the other Relation tests.
namespace Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Pins the morphTo() related-type limitation that the #913 fix exposes. It used to be hidden
 * behind the `mixed` collapse; once relation chains resolve to real types, Psalm type-checks the
 * morphTo() return expression and this surfaces. It is NOT a plugin bug. This test asserts the
 * CURRENT (limited) output so it flips loudly when a future morph-map-aware resolver lands.
 *
 * (The sibling pivot limitation — belongsToMany()/morphToMany() chains tripping
 * MissingTemplateParam — is fixed as of #1088; see Issue1088PivotChainInBodyTest.)
 *
 * morphTo()'s target is resolved at runtime via the morph map, so the stub can only return
 * MorphTo<Model, static>. An app @return that narrows the related side (a union or intersection)
 * is more specific than the stub can prove, so it mismatches. Inherent to polymorphic relations;
 * resolvable app-side (widen the annotation) or by a future morph-map-aware resolver in the
 * plugin, not by a stub or Psalm variance change.
 */
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
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Article|Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Comment, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction>' for Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction::target is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Article|Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Comment, Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction>' for Tests\Psalm\LaravelPlugin\Sandbox\RelationLimits\Reaction::target
