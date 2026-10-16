<?php

declare(strict_types=1);

namespace DynamicWhereFixture;

use DynamicWhereFixture\Models\UserFilter;

/** A schema-only column is typed from the schema (`user_id` is an unsigned int). */
function typed_param_from_schema(): int
{
    return UserFilter::whereUserId('abc')->count();
}

function unknown_column(): int
{
    return UserFilter::whereNoSuchColumn(1)->count();
}

/** Laravel queries `line1` (snake of `Line1`), never the `line_1` column. */
function unreachable_column(): int
{
    return UserFilter::whereLine1('x')->count();
}
