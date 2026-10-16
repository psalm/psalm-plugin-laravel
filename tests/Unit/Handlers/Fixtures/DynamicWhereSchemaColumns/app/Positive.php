<?php

declare(strict_types=1);

namespace DynamicWhereFixture;

use DynamicWhereFixture\Models\CustomFilter;
use DynamicWhereFixture\Models\Owner;
use DynamicWhereFixture\Models\UserFilter;

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
