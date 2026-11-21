<?php

declare(strict_types=1);

namespace ColumnListFixture;

use ColumnListFixture\Models\AttributeScopedPost;
use ColumnListFixture\Models\AuditedPost;
use ColumnListFixture\Models\ChildScopedPost;
use ColumnListFixture\Models\ConstrainedCountPost;
use ColumnListFixture\Models\CountedPost;
use ColumnListFixture\Models\DocumentedPost;
use ColumnListFixture\Models\EntityPost;
use ColumnListFixture\Models\ExtendedPost;
use ColumnListFixture\Models\FactoryBuilderPost;
use ColumnListFixture\Models\FactoryPost;
use ColumnListFixture\Models\GlobalScopesPost;
use ColumnListFixture\Models\HookedPost;
use ColumnListFixture\Models\LimitBuilderPost;
use ColumnListFixture\Models\LinkPost;
use ColumnListFixture\Models\OverriddenPost;
use ColumnListFixture\Models\PlainPost;
use ColumnListFixture\Models\ScopedPost;
use ColumnListFixture\Models\ScopeLimitPost;
use ColumnListFixture\Models\ScopeNamedPost;
use ColumnListFixture\Models\SettingPost;
use ColumnListFixture\Models\SubAttributeScopedPost;
use ColumnListFixture\Models\TraitScopedPost;
use ColumnListFixture\Models\TrashedPost;
use ColumnListFixture\Models\TypedQueryPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use KnownAttributeFixture\Models\Author;
use KnownAttributeFixture\Models\DynamicSchemaThing;
use KnownAttributeFixture\Models\KnownThing;
use KnownAttributeFixture\Models\Post;
use KnownAttributeFixture\Models\PostBuilder;

/**
 * Literal `$columns` lists of Eloquent read methods (#2346). A `typocol_` literal must be flagged, a
 * `silentcol_` literal sits on a shape the rule must decline.
 */

// ---- must flag: the three examples of the issue ----------------------------------------------

function issue_examples(): void
{
    Post::query()->findSole(1, ['id', 'typocol_findsole']);
    Post::findOr(1, ['typocol_findor'], static fn(): null => null);
    Post::query()->first(['typocol_first']);
}

// ---- must flag: every method, both roots --------------------------------------------------------

function static_forwards(): void
{
    KnownThing::find(1, ['typocol_find']);
    KnownThing::findOrFail(1, ['typocol_findorfail']);
    KnownThing::findOrNew(1, ['typocol_findornew']);
    KnownThing::findMany([1, 2], ['typocol_findmany']);
    KnownThing::first(['typocol_staticfirst']);
    KnownThing::firstOrFail(['typocol_firstorfail']);
    KnownThing::firstOr(['typocol_firstor'], static fn(): null => null);
    KnownThing::sole(['typocol_sole']);
    KnownThing::get(['typocol_staticget']);
    KnownThing::all(['typocol_all']);
    KnownThing::paginate(15, ['typocol_staticpaginate']);
}

function query_chains(): void
{
    KnownThing::query()->get(['name', 'typocol_get', 'email']);
    KnownThing::query()->paginate(15, ['typocol_paginate']);
    KnownThing::query()->simplePaginate(15, ['typocol_simplepaginate']);
    KnownThing::query()->cursorPaginate(15, ['typocol_cursorpaginate']);
    KnownThing::where('name', 'x')->orderBy('name')->limit(1)->get(['typocol_chain']);
    KnownThing::query()->whereIn('id', [1])->with([])->latest()->take(5)->get(['typocol_longchain']);
    KnownThing::query()->find(id: 1, columns: ['typocol_named']);
    KnownThing::query()->first('typocol_string');
    KnownThing::query()->get(['typocol_json->theme']);
    KnownThing::query()->get(['typocol_twice_a', 'typocol_twice_b']);
    KnownThing::query()->lockForUpdate()->first(['typocol_lockforupdate']);
    KnownThing::whereKey(1)->sharedLock()->get(['typocol_sharedlock']);
    // An Expression and an alias are skipped entry by entry; the literal beside them is not.
    KnownThing::query()->get([DB::raw('count(*) as silentcol_raw'), 'name as silentcol_alias', 'typocol_mixed']);
}

function soft_deletes_scope_is_framework_owned(): void
{
    TrashedPost::withTrashed()->first(['typocol_softdeletes']);
}

function unrelated_class_attribute(): void
{
    // The scope veto reads attributes from Psalm's storage and never loads the attribute class.
    AuditedPost::query()->get(['typocol_unrelated_attribute']);
}

function internal_calls_of_the_read_method(): void
{
    // `paginate()` reaches `forPage()`, never the Eloquent `limit()` the model's scope overrides.
    ScopeLimitPost::query()->paginate(15, ['typocol_paginate_beside_scope_limit']);
}

// ---- must stay silent: literal shapes ----------------------------------------------------------

/** @param list<string> $dynamic */
function silent_literal_shapes(array $dynamic, string $column): void
{
    KnownThing::query()->get(['id', 'name', 'email', 'created_at', 'updated_at']);
    KnownThing::query()->get(['*']);
    KnownThing::query()->get('*');
    KnownThing::query()->get(['known_things.*']);
    KnownThing::query()->get(['known_things.silentcol_own_qualified']);
    KnownThing::query()->get(['other_table.silentcol_other_qualified']);
    KnownThing::query()->get(['name as silentcol_aliased']);
    KnownThing::query()->get([DB::raw('silentcol_expression')]);
    KnownThing::query()->get(['count(silentcol_function)']);
    KnownThing::query()->get($dynamic);
    KnownThing::query()->get([$column]);
    KnownThing::query()->get([...$dynamic]);
    KnownThing::query()->get(['key' => 'silentcol_keyed']);
    KnownThing::query()->get(['name->silentcol_json_path']);
    // Matched like attribute keys: case and separators are ignored.
    KnownThing::query()->get(['createdAt', 'Name']);
    DocumentedPost::query()->get(['computed_total']);
}

function silent_overwritable_entries(int $offset): void
{
    // An explicit key may overwrite another entry, so no entry is known to reach SQL.
    KnownThing::query()->get(['silentcol_overwritten', $offset => 'name']);
}

function silent_closure_as_columns(): void
{
    KnownThing::findOr(1, static fn(): null => null);
    KnownThing::query()->firstOr(static fn(): null => null);
    KnownThing::query()->find(1);
}

// ---- must stay silent: query shape the receiver type does not carry ----------------------------

function silent_query_shape(bool $flag): void
{
    Post::query()->join('authors', 'authors.id', '=', 'posts.author_id')->get(['silentcol_join']);
    Post::query()->leftJoin('authors', 'authors.id', '=', 'posts.author_id')->first(['silentcol_leftjoin']);
    Post::query()->from('posts_archive')->get(['silentcol_from']);
    Post::query()->select('id')->get(['silentcol_dead_after_select']);
    Post::query()->addSelect('id')->get(['silentcol_dead_after_addselect']);
    Post::query()->selectRaw('1 as one')->get(['silentcol_dead_after_selectraw']);
    Post::query()->withCount('tags')->get(['silentcol_dead_after_withcount']);
    Post::query()->when($flag, static fn(Builder $q): Builder => $q->join('authors', 'authors.id', '=', 'posts.author_id'))->get(['silentcol_when']);
    Post::query()->tap(static fn(Builder $q): Builder => $q->select('id'))->get(['silentcol_tap']);
    Post::query()->toBase()->get(['silentcol_tobase']);
    Post::query()->distinct()->get(['silentcol_unlisted_link']);
    Post::on('other')->get(['silentcol_unlisted_root']);
    DB::table('posts')->get(['silentcol_query_builder']);
}

/**
 * @template TModelFn of Model
 *
 * @param Builder<Post>                      $builder
 * @param Builder<Post>|null                 $nullable
 * @param Builder<KnownThing>|Builder<Post>  $union
 * @param Builder<Model>                     $unbound
 * @param Builder<TModelFn>                  $template
 * @param Builder<Post>&\Countable           $intersection
 */
function silent_unproven_receivers(
    Builder $builder,
    ?Builder $nullable,
    Builder $union,
    Builder $unbound,
    Builder $template,
    Builder&\Countable $intersection,
    PostBuilder $custom,
    Post $model,
    Author $author,
): void {
    // A builder in a variable may already carry a join, a FROM or a select.
    $builder->first(['silentcol_variable']);
    $builder->where('title', 'x')->get(['silentcol_variable_chain']);
    $nullable?->first(['silentcol_nullsafe']);
    $union->first(['silentcol_union']);
    $unbound->first(['silentcol_unbound']);
    $template->first(['silentcol_template']);
    $intersection->first(['silentcol_intersection']);
    $custom->first(['silentcol_custom_variable']);
    $model->newQuery()->get(['silentcol_newquery']);
    $author->posts()->get(['silentcol_relation']);
    $model->tags()->get(['silentcol_belongs_to_many']);
    $query = Post::query();
    $query->get(['silentcol_local_variable']);
}

function silent_model_level_gates(): void
{
    // Global scope registered in the model, its parent, a trait, or by attribute.
    ScopedPost::query()->get(['silentcol_scope_closure']);
    ChildScopedPost::query()->get(['silentcol_scope_parent']);
    TraitScopedPost::query()->get(['silentcol_scope_trait']);
    AttributeScopedPost::query()->get(['silentcol_scope_attribute']);
    SubAttributeScopedPost::query()->get(['silentcol_scope_attribute_subclass']);
    GlobalScopesPost::query()->get(['silentcol_get_global_scopes_override']);
    // A scope's `extend()` ran at registration; removing the scope does not undo it.
    ExtendedPost::withoutGlobalScopes()->get(['silentcol_scope_extend']);
    ScopedPost::withoutGlobalScopes()->get(['silentcol_unscoped']);
    // `$withCount` seeds a select, so `$columns` is dead.
    CountedPost::query()->get(['silentcol_withcount_property']);
    ConstrainedCountPost::query()->get(['silentcol_withcount_constraint_map']);
    FactoryPost::query()->get(['silentcol_factory_join']);
    FactoryBuilderPost::query()->get(['silentcol_factory_custom_builder']);
    HookedPost::query()->get(['silentcol_newquery_override']);
    TypedQueryPost::query()->get(['silentcol_query_override']);
    SettingPost::get('silentcol_setting_key');
    ScopeNamedPost::query()->orderBy('title')->get(['silentcol_scope_named_like_link']);
    // `first()` calls `limit(1)` through `Builder::__call`.
    ScopeLimitPost::query()->first(['silentcol_scope_limit']);
    DynamicSchemaThing::query()->get(['silentcol_empty_schema']);
}

function silent_custom_builder_gates(): void
{
    OverriddenPost::query()->get(['silentcol_builder_get_override']);
    OverriddenPost::query()->first(['silentcol_builder_inherited_first']);
    EntityPost::query()->get(['silentcol_builder_constructor_scope']);
    LinkPost::query()->orderBy('title')->get(['silentcol_builder_link']);
    LimitBuilderPost::query()->first(['silentcol_builder_limit']);
}

function plain_model_control(): void
{
    PlainPost::viaSelf();
    PlainPost::viaStatic();
}
