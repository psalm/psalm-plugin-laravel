--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: psalm-tester batches every .phpt into one analysis, so the
// fixture class names must not collide with the other Relation tests.
namespace Tests\Psalm\LaravelPlugin\Sandbox\PivotInBody;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1088.
 *
 * After #1055 replaced `$this` with `static` in the relation-factory returns, a pivot
 * chain inside a relation method body (`belongsToMany(...)->withPivot()->withTimestamps()`)
 * resolved to the stub's 2-of-4 `BelongsToMany<TRelated, static>` and tripped
 * MissingTemplateParam on the RETURN STATEMENT — even when the method's own `@return` was
 * already a complete 4-param type. That is an inferred in-body expression, so the user
 * cannot fix it by annotating the method.
 *
 * Fix (stubs only): the factory returns fill all 4 params (Pivot/MorphPivot + 'pivot'),
 * and `->using()` / `->as()` re-narrow TPivotModel / TAccessor via `@return static<...>`
 * so custom-pivot/accessor methods stay sound (no InvalidReturnStatement). The empty
 * `--EXPECTF--` block is the oracle: every relation method below must analyse clean.
 */
class Tag extends Model
{
}

class Membership extends Pivot
{
}

class Post extends Model
{
    // Issue reproducer: common pivot chain, no using()/as().
    /** @return BelongsToMany<Tag, self, Pivot, 'pivot'> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id')
            ->withPivot('role')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    // ->as() WITHOUT ->using(): the exact shape that previously regressed to
    // InvalidReturnStatement when the factory pinned 'pivot' — the accessor must
    // re-narrow to 'membership' on its own.
    /** @return BelongsToMany<Tag, self, Pivot, 'membership'> */
    public function asOnly(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->as('membership')->withPivot('role');
    }

    // ->using() only: TPivotModel narrows, accessor keeps the 'pivot' default.
    /** @return BelongsToMany<Tag, self, Membership, 'pivot'> */
    public function usingOnly(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->using(Membership::class);
    }

    // ->using() AND ->as(): both pivot slots re-narrow through the chain.
    /** @return BelongsToMany<Tag, self, Membership, 'membership'> */
    public function customPivotAndAccessor(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('role');
    }

    // A @mixin Builder method (where()) chained AFTER ->using()/->as(): the narrowed
    // `static<...>` must survive a Builder-forwarded call (guards against a Builder&static
    // collapse, a recurring failure mode in this plugin).
    /** @return BelongsToMany<Tag, self, Membership, 'membership'> */
    public function customThenBuilder(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->using(Membership::class)
            ->as('membership')
            ->where('active', true)
            ->withPivot('role');
    }

    // morphToMany: per-relation pivot default is MorphPivot.
    /** @return MorphToMany<Tag, self, MorphPivot, 'pivot'> */
    public function morphTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    // morphToMany with a custom pivot + accessor.
    /** @return MorphToMany<Tag, self, Membership, 'meta'> */
    public function morphCustom(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')
            ->using(Membership::class)
            ->as('meta');
    }

    // morphedByMany: per-relation pivot default is MorphPivot.
    /** @return MorphToMany<Tag, self, MorphPivot, 'pivot'> */
    public function taggedThings(): MorphToMany
    {
        return $this->morphedByMany(Tag::class, 'taggable')->withPivot('x');
    }
}

// External call sites still resolve through ModelRelationReturnTypeHandler; the in-body
// fix and the handler agree on the 4-param shape (handler emission wins at the call site).
function probe_external(Post $post): void
{
    $_common = $post->tags();
    /** @psalm-check-type-exact $_common = BelongsToMany<Tag, Post, Pivot, 'pivot'> */

    $_custom = $post->customPivotAndAccessor();
    /** @psalm-check-type-exact $_custom = BelongsToMany<Tag, Post, Membership, 'membership'> */
}

?>
--EXPECTF--
