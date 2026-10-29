<?php

declare(strict_types=1);

namespace DynamicWhereFixture;

use DynamicWhereFixture\Models\Owner;
use DynamicWhereFixture\Models\UserFilter;
use Illuminate\Database\Eloquent\Relations\HasMany;

function unknown_column(): int
{
    return UserFilter::whereNoSuchColumn(1)->count();
}

/** Laravel queries `line1` (snake of `Line1`), never the `line_1` column. */
function unreachable_column(): int
{
    return UserFilter::whereLine1('x')->count();
}

/**
 * `Owner` has no `user_id` column, so the relation must not be claimed from `UserFilter` alone.
 *
 * @param HasMany<UserFilter|Owner, Owner> $relation
 * @return HasMany<UserFilter|Owner, Owner>
 */
function union_where_one_alternative_lacks_the_column(HasMany $relation): HasMany
{
    return $relation->whereUserId(1);
}

/**
 * A receiver union is not narrowed to its first alternative.
 *
 * @param HasMany<UserFilter, Owner>|HasMany<Owner, Owner> $relation
 * @return HasMany<UserFilter, Owner>|HasMany<Owner, Owner>
 */
function receiver_union_where_one_alternative_lacks_the_column(HasMany $relation): HasMany
{
    return $relation->whereUserId(1);
}
