<?php

declare(strict_types=1);

namespace DynamicWhereFixture;

use DynamicWhereFixture\Models\ArchivedFilter;
use DynamicWhereFixture\Models\CustomFilter;
use DynamicWhereFixture\Models\Owner;
use DynamicWhereFixture\Models\PrivateMethodFilter;
use DynamicWhereFixture\Models\RealMethodFilter;
use DynamicWhereFixture\Models\StringIdFilter;
use DynamicWhereFixture\Models\TraitAdaptedFilter;
use DynamicWhereFixture\Models\UserFilter;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Every call below must resolve without UndefinedMagicMethod, Mixed* or argument issues. */
function static_form(): int
{
    return UserFilter::whereUserId(1)->count();
}

function instance_form(UserFilter $filter): int
{
    return $filter->whereUserId(1)->count();
}

function relation_chain(Owner $owner): ?UserFilter
{
    return $owner->filters()->whereUserId(1)->first();
}

function multi_segment(): ?UserFilter
{
    return UserFilter::whereUserIdAndFilterType(1, 'a')->first();
}

/** `CustomFilterBuilder::whereUserId(string)` must win over the schema-derived `user_id` (int). */
function custom_builder_method_wins(): int
{
    return CustomFilter::whereUserId('abc')->count();
}

/** Laravel queries `user_id` for `whereuser_id`, as the precise resolver already assumes. */
function underscore_spelling(): int
{
    return UserFilter::whereuser_id(1)->count();
}

/** A builder macro wins over the schema column; its string return type is kept. */
function macro_wins_on_instance(UserFilter $filter): string
{
    return $filter->whereTagId('abc');
}

function macro_wins_on_relation(Owner $owner): string
{
    return $owner->filters()->whereTagId('abc');
}

/** A real model method wins over the schema column; its int return type is kept. */
function real_model_method_wins(): int
{
    return RealMethodFilter::whereUserId('abc');
}

/**
 * @param HasMany<UserFilter|ArchivedFilter, Owner> $relation
 * @return HasMany<UserFilter|ArchivedFilter, Owner>
 */
function union_where_every_alternative_has_the_column(HasMany $relation): HasMany
{
    return $relation->whereUserId(1);
}

/** A Relation macro applies to relation receivers only, never to direct model calls. */
function relation_macro_does_not_veto_model_calls(UserFilter $filter): int
{
    return UserFilter::whereFilterType('a')->count() + $filter->whereFilterType('a')->count();
}

function relation_macro_wins_on_relation(Owner $owner): string
{
    return $owner->filters()->whereFilterType('a');
}

/** A Query\Builder macro never changes the wrapper's return value. */
function query_builder_macro_does_not_veto(UserFilter $filter, Owner $owner): int
{
    return UserFilter::whereOwnerId(1)->count() + $filter->whereOwnerId(1)->count() + $owner->filters()->whereOwnerId(1)->count();
}

/** A `@method` on the model's custom builder keeps its signature (int param, string return) over the string column. */
function custom_builder_pseudo_method_wins_on_instance(CustomFilter $filter): string
{
    return $filter->whereKind(1);
}

/** The relation chain stays mixed (the relation has no @mixin of the builder) but no `string` param is imposed. */
function custom_builder_pseudo_method_is_not_retyped_on_relation(Owner $owner): void
{
    $owner->customFilters()->whereKind(1);
}

/** An inaccessible real method is not dispatched: PHP falls through to the dynamic where. */
function private_model_method_is_not_dispatched(PrivateMethodFilter $filter): int
{
    return $filter->whereFilterType('x')->count();
}

/**
 * @param HasMany<UserFilter|StringIdFilter, Owner> $relation
 * @return HasMany<UserFilter|StringIdFilter, Owner>
 */
function union_with_different_column_types(HasMany $relation): HasMany
{
    return $relation->whereUserId(1);
}

/** A Query\Builder macro's arguments are not column values: no schema param type is imposed. */
function query_builder_macro_args_are_not_typed_from_the_column(Owner $owner, UserFilter $filter): int
{
    return $owner->filters()->whereSlug(['a'])->count() + $filter->whereSlug(['a'])->count();
}

/** `use T { f as private; }` makes the trait's public method non-public on the model: magic dispatch. */
function trait_method_adapted_to_private_is_not_dispatched(TraitAdaptedFilter $filter): int
{
    return $filter->whereState('x')->count();
}

/** Laravel binds any int; an unsigned column must not demand `int<0, max>`. */
function unsigned_column_accepts_a_plain_int(int $id): int
{
    return UserFilter::whereUserId($id)->count();
}
