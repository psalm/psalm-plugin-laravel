<?php

declare(strict_types=1);

namespace DynamicWhereFixture;

use DynamicWhereFixture\Models\ArchivedFilter;
use DynamicWhereFixture\Models\CustomFilter;
use DynamicWhereFixture\Models\Owner;
use DynamicWhereFixture\Models\RealMethodFilter;
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
