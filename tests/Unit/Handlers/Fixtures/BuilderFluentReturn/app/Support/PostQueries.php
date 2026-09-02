<?php

declare(strict_types=1);

namespace BuilderFluentReturnFixture\Support;

use BuilderFluentReturnFixture\Models\PostBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class PostQueries
{
    public function run(PostBuilder $query, QueryBuilder $queryBuilder): void
    {
        $query->publishedSelf();
        $query->publishedIntersectionBuilderPrimary();
        $query->publishedIntersectionBuilderSecondary();
        $query->publishedStaticNative();
        $query->publishedStaticDocblock();
        $query->publishedOwnClassName();
        $query->discardedControl();
        PostBuilder::forGuest($queryBuilder);
    }
}
