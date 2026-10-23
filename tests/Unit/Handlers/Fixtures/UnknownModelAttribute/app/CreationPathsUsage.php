<?php

declare(strict_types=1);

namespace KnownAttributeFixture;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use KnownAttributeFixture\Models\Author;
use KnownAttributeFixture\Models\ChildPost;
use KnownAttributeFixture\Models\GenericBuilder;
use KnownAttributeFixture\Models\HookBuilder;
use KnownAttributeFixture\Models\HookPosts;
use KnownAttributeFixture\Models\ImportBuilder;
use KnownAttributeFixture\Models\ImportPosts;
use KnownAttributeFixture\Models\KnownThing;
use KnownAttributeFixture\Models\Post;
use KnownAttributeFixture\Models\PostBuilder;
use KnownAttributeFixture\Models\Tag;
use KnownAttributeFixture\Models\VirtualTag;

/**
 * Creation paths on builder and relation receivers (#1679). Keys prefixed `typo_` must be flagged;
 * keys prefixed `silent_` sit on a receiver/method the rule must decline, so the emission test can
 * assert both directions by key name.
 */

// ---- must flag -------------------------------------------------------------------------------

function builder_concrete(): KnownThing
{
    return KnownThing::query()->create(['typo_query' => 1, 'name' => 'Ada']);
}

function builder_custom_from_query(): Post
{
    return Post::query()->create(['typo_postquery' => 1, 'title' => 'x']);
}

function builder_custom_non_generic(PostBuilder $builder): Post
{
    return $builder->forceCreate(['typo_postbuilder' => 1]);
}

/** @param GenericBuilder<Post> $builder */
function builder_custom_generic(GenericBuilder $builder): Post
{
    return $builder->make(['typo_genericbuilder' => 1]);
}

function relation_has_many(Author $author): Post
{
    return $author->posts()->create(['typo_hasmany' => 1, 'title' => 'x']);
}

function relation_force_create_quietly(Author $author): Post
{
    return $author->posts()->forceCreateQuietly(['typo_forcequiet' => 1]);
}

function relation_chained_through_where(Author $author): Post
{
    return $author->posts()->where('title', 'x')->create(['typo_chained' => 1]);
}

function relation_named_arguments(Author $author): void
{
    $author->posts()->create(attributes: ['typo_named' => 1]);
    $author->posts()->createMany(records: [['typo_namedrecords' => 1]]);
}

function relation_make_and_make_many(Author $author): void
{
    $author->posts()->make(['typo_make' => 1]);
    $author->posts()->makeMany([['typo_makemany' => 1]]);
}

/** Only the literal-array item is inspected; a non-literal item is skipped, a clean one is silent. */
function relation_create_many(Author $author, array $dynamic): void
{
    $author->posts()->createMany([
        ['typo_many' => 1, 'title' => 'x'],
        ['title' => 'clean', 'body' => 'x'],
        $dynamic,
    ]);
}

function relation_belongs_to_many(Post $post): Tag
{
    // Only the first argument is related-model data; `pivot_col` is pivot data.
    return $post->tags()->create(['typo_tag' => 1, 'label' => 'x'], ['pivot_col' => 1]);
}

function relation_belongs_to_many_create_many(Post $post): void
{
    $post->tags()->createMany([['typo_tagmany' => 1]], [['pivot_col' => 1]]);
}

/** @param MorphMany<Post, Author> $relation */
function relation_morph_many(MorphMany $relation): Post
{
    return $relation->create(['typo_morphmany' => 1]);
}

/** @param MorphToMany<Tag, Post> $relation */
function relation_morph_to_many(MorphToMany $relation): Tag
{
    return $relation->create(['typo_morphtomany' => 1]);
}

/**
 * Psalm analyzes `?->` as a plain call on the non-null receiver, so the same check applies as on the
 * Model path; the finding is a true positive (the receiver is `Builder<Post>` whenever it runs).
 *
 * @param Builder<Post>|null $builder
 */
function nullsafe_receiver(?Builder $builder): void
{
    $builder?->create(['typo_nullsafe' => 1]);
}

/** Named arguments in any order: only `attributes` is model data, `joining` is pivot data. */
function relation_reordered_named_arguments(Post $post): void
{
    $post->tags()->create(joining: ['silent_reordered_pivot' => 1], attributes: ['typo_reordered' => 1]);
    $post->tags()->createMany(joinings: [['silent_reordered_joining' => 1]], records: [['typo_reordered_many' => 1]]);
}

// ---- must stay silent ------------------------------------------------------------------------

function silent_clean_calls(Author $author, Post $post): void
{
    KnownThing::query()->create(['name' => 'Ada', 'email' => 'a@b.com']);
    $author->posts()->create(['title' => 'x', 'body' => 'y', 'author_id' => 1]);
    $author->posts()->createMany([['title' => 'x', 'body' => 'y']]);
    $post->tags()->create(['label' => 'x'], ['pivot_col' => 1]);
}

function silent_builder_update(): void
{
    // Builder::update() is a query-builder write that bypasses fill(): not a creation path.
    KnownThing::query()->update(['silent_update' => 1]);
}

function silent_relation_update(Author $author): void
{
    $author->posts()->update(['silent_relation_update' => 1]);
}

function silent_pivot_keys(Post $post): void
{
    $post->tags()->create(['label' => 'x'], ['silent_pivot' => 1]);
    $post->tags()->createMany([['label' => 'x']], [['silent_joining' => 1]]);
}

function silent_belongs_to_many_unlisted_methods(Post $post): void
{
    // Reaches BelongsToMany through __call forwarding, not its own fill path.
    $post->tags()->forceCreate(['silent_btm_force' => 1]);
    $post->tags()->make(['silent_btm_make' => 1]);
}

/**
 * @template TModelFn of Model
 *
 * @param Builder<TModelFn> $builder
 */
function silent_unresolved_template(Builder $builder): void
{
    // Never fall back to the template's `Model` bound.
    $builder->create(['silent_template' => 1]);
}

/** @param Builder<Model> $builder */
function silent_model_builder(Builder $builder): void
{
    $builder->create(['silent_model' => 1]);
}

/** @param Builder<KnownThing>|Builder<Post> $builder */
function silent_union_of_models(Builder $builder): void
{
    $builder->create(['silent_union' => 1]);
}

/** @param Builder<Post>|null $builder */
function silent_nullable_builder(?Builder $builder): void
{
    $builder->create(['silent_null' => 1]);
}

/** @param Builder<Post>&\Countable $builder */
function silent_intersection(Builder&\Countable $builder): void
{
    $builder->create(['silent_intersection' => 1]);
}

/** @param Post|Builder<Post> $receiver */
function silent_model_mixed_with_builder(Post|Builder $receiver): void
{
    $receiver->create(['silent_mixed' => 1]);
}

function silent_non_literal_list(Author $author, array $records): void
{
    $author->posts()->createMany($records);
}

/** Non-generic subclass without `@extends`: no model can be projected. */
class UnboundBuilder extends Builder {}

function silent_unbound_builder(UnboundBuilder $builder): void
{
    $builder->create(['silent_unbound' => 1]);
}

/** @param HasMany<Post, Author> $relation */
function silent_relation_unlisted_method(HasMany $relation): void
{
    $relation->firstOrCreate(['silent_first_or_create' => 1]);
}

function silent_userland_builder_override(ImportBuilder $builder): void
{
    // create() is overridden in userland (key mapping); createQuietly() is inherited but calls it.
    $builder->create(['silent_override_headline' => 1]);
    $builder->createQuietly(['silent_inherited_headline' => 1]);
    $builder->make(['silent_override_make' => 1]);
}

function silent_userland_relation_override(ImportPosts $relation): void
{
    $relation->createMany([['silent_override_many' => 1]]);
    $relation->createManyQuietly([['silent_inherited_many' => 1]]);
    $relation->create(['silent_override_relation_create' => 1]);
}

function silent_list_spread(Author $author): void
{
    $author->posts()->createMany([...['batch' => ['silent_spread_batch' => 1]]]);
}

function silent_list_keyed_outer(Author $author): void
{
    // The later `s` record overwrites the first, so the first's keys are never used.
    $author->posts()->createMany(['s' => ['silent_discarded' => 1], 's' => ['title' => 'x']]);
}

/** @param Builder<Post&ChildPost> $builder */
function silent_intersection_inside_model(Builder $builder): void
{
    $builder->make(['silent_child_only' => 1]);
}

function silent_delegated_builder_hook(HookBuilder $builder): void
{
    $builder->make(['silent_hook_builder_make' => 1]);
    $builder->create(['silent_hook_builder_create' => 1]);
}

function silent_delegated_relation_hook(HookPosts $relation): void
{
    $relation->make(['silent_hook_relation_make' => 1]);
    $relation->create(['silent_hook_relation_create' => 1]);
}

function silent_illuminate_namespaced_userland_builder(\Illuminate\Review\ImportBuilder $builder): void
{
    $builder->make(['silent_illuminate_ns' => 1]);
}

/** Trait-widened guard: the virtual key is accepted at runtime, whichever way the model is reached. */
function silent_model_with_widened_guard(Author $author): void
{
    VirtualTag::create(['custom_fields' => [1]]);
    VirtualTag::query()->create(['custom_fields' => [1]]);
    $author->virtualTags()->create(['custom_fields' => [1]]);
    (new VirtualTag())->fill(['custom_fields' => [1]]);
}

/** Negative control: a stock model on the same table is still checked. */
function stock_model_still_flagged(): void
{
    Tag::create(['typo_stock_control' => 1]);
}
