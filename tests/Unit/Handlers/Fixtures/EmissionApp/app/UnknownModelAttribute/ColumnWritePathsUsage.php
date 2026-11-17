<?php

declare(strict_types=1);

namespace KnownAttributeFixture;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Expression;
use KnownAttributeFixture\Models\Author;
use KnownAttributeFixture\Models\Post;
use KnownAttributeFixture\Models\UpsertBuilder;
use KnownAttributeFixture\Models\UpsertingPost;

/**
 * Builder writes whose arguments name columns (#2339). Keys prefixed `typo_` must be flagged; keys
 * prefixed `silent_` sit on a receiver, argument or shape the rule must decline.
 */

// ---- must flag -------------------------------------------------------------------------------

function upsert_rows_and_columns(): void
{
    Post::query()->upsert([['typo_up_rows' => 1, 'title' => 'x']], ['title']);
    Post::query()->upsert(['typo_up_row' => 1, 'title' => 'x'], 'title');
    Post::query()->upsert([['title' => 'x']], 'typo_up_unique_string');
    Post::query()->upsert([['title' => 'x']], ['title', 'typo_up_unique_list']);
    Post::query()->upsert([['title' => 'x']], ['title'], ['body', 'typo_up_update_list']);
    Post::query()->upsert([['title' => 'x']], ['title'], ['typo_up_update_map' => 1]);
}

function upsert_named_arguments_in_any_order(): void
{
    Post::query()->upsert(
        update: ['typo_up_named_update'],
        values: [['typo_up_named_values' => 1]],
        uniqueBy: ['title'],
    );
}

function with_attributes(): void
{
    Post::query()->withAttributes(['typo_wa_map' => 1, 'title' => 'x']);
    Post::query()->withAttributes('typo_wa_string', 1);
    Post::query()->withAttributes(value: 1, attributes: 'typo_wa_named');
}

function fill_and_insert_family(): void
{
    Post::query()->fillAndInsert([['typo_fai_rows' => 1], ['title' => 'x']]);
    Post::query()->fillAndInsert(['typo_fai_row' => 1]);
    Post::query()->fillAndInsertOrIgnore([['typo_faio' => 1]]);
    Post::query()->fillAndInsertGetId(['typo_faig' => 1]);
    Post::query()->fillForInsert([['typo_ffi' => 1]]);
}

function increment_each(): void
{
    Post::query()->incrementEach(['typo_inc_columns' => 1], ['typo_inc_extra' => 'x']);
    Post::query()->decrementEach(extra: ['typo_dec_extra' => 'x'], columns: ['typo_dec_columns' => 1]);
}

function model_receivers(Post $post): void
{
    Post::upsert([['typo_static_upsert' => 1]], ['title']);
    Post::withAttributes(['typo_static_wa' => 1]);
    Post::fillAndInsert([['typo_static_fai' => 1]]);
    Post::incrementEach(['typo_static_inc' => 1]);
    $post->incrementEach(['typo_model_inc' => 1], ['typo_model_inc_extra' => 'x']);
    $post->decrementEach(['typo_model_dec' => 1]);
    $post->upsert([['typo_model_upsert' => 1]], ['title']);
}

function relation_receivers(Author $author): void
{
    $author->posts()->upsert([['typo_rel_upsert' => 1]], ['title']);
    $author->posts()->withAttributes(['typo_rel_wa' => 1]);
    $author->posts()->fillAndInsert([['typo_rel_fai' => 1]]);
    $author->posts()->incrementEach(['typo_rel_inc' => 1]);
}

function belongs_to_many_receivers(Post $post): void
{
    $post->tags()->upsert([['typo_btm_upsert' => 1]], ['label']);
    $post->tags()->withAttributes(['typo_btm_wa' => 1]);
    $post->tags()->fillAndInsert([['typo_btm_fai' => 1]]);
}

// ---- must stay silent ------------------------------------------------------------------------

function silent_known_columns(Post $post, Author $author): void
{
    Post::query()->upsert([['title' => 'x', 'body' => 'y', 'author_id' => 1]], ['title'], ['body', 'author_id']);
    Post::query()->upsert(['title' => 'x', 'body' => 'y'], 'title', ['body' => 'z']);
    Post::query()->withAttributes(['title' => 'x']);
    Post::query()->withAttributes('title', 'x');
    Post::query()->fillAndInsert([['title' => 'x'], ['body' => 'y']]);
    Post::query()->fillAndInsertGetId(['title' => 'x']);
    Post::query()->incrementEach(['author_id' => 1], ['title' => 'x']);
    $post->incrementEach(['author_id' => 1]);
    $author->posts()->upsert([['title' => 'x']], ['title']);
}

/** @param array<string, mixed> $row  @param list<array<string, mixed>> $rows */
function silent_dynamic_shapes(array $row, array $rows, string $column): void
{
    Post::query()->upsert($rows, ['title']);
    Post::query()->upsert([$column => 1], $column, [$column]);
    Post::query()->upsert([$row, $row], ['title']);
    Post::query()->withAttributes($column);
    Post::query()->withAttributes(new Expression('silent_expression'));
    Post::query()->fillAndInsert($row);
    Post::query()->incrementEach($row, $row);
}

function silent_unprovable_rows(): void
{
    // A spread or an explicit outer key can merge or overwrite rows.
    Post::query()->upsert([...[['silent_up_spread' => 1]], ['title' => 'x']], ['title']);
    Post::query()->upsert(['a' => ['silent_up_keyed' => 1]], ['title']);
}

function silent_qualified_columns(): void
{
    Post::query()->upsert([['title' => 'x']], ['posts.silent_qualified_unique'], ['posts.silent_qualified_update']);
    Post::query()->withAttributes('posts.silent_qualified_wa', 1);
    Post::query()->incrementEach(['posts.silent_qualified_inc' => 1]);
}

function silent_unpacked_and_callable(array $args): void
{
    Post::query()->upsert(...$args);
    $callable = Post::query()->upsert(...);
    $callable([['silent_callable' => 1]], 'title');
}

function silent_query_builder_receiver(): void
{
    // The base query builder takes raw columns of any table; only the Eloquent builder maps to a model.
    Post::query()->toBase()->upsert([['silent_base_upsert' => 1]], ['title']);
    Post::query()->toBase()->incrementEach(['silent_base_inc' => 1]);
}

function silent_belongs_to_many_increment_each(Post $post): void
{
    // The pivot join makes a pivot column a legitimate key here.
    $post->tags()->incrementEach(['silent_btm_inc' => 1]);
}

function silent_userland_builder_override(UpsertBuilder $builder): void
{
    $builder->upsert([['silent_override_upsert' => 1]], ['title']);
    $builder->withAttributes(['silent_override_wa' => 1]);
}

/** @param HasMany<Post, Author> $relation */
function silent_relation_unlisted_methods(HasMany $relation): void
{
    $relation->incrementOrCreate(['silent_increment_or_create' => 1]);
    $relation->insert(['silent_relation_insert' => 1]);
}

function silent_model_declaring_its_own_upsert(): void
{
    UpsertingPost::upsert(['silent_model_own_upsert' => 1], 'title');
}
