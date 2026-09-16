<?php

declare(strict_types=1);

namespace BuilderFluentReturnFixture;

use BuilderFluentReturnFixture\Models\PostBuilder;
use BuilderFluentReturnFixture\Support\PostQueries;
use Illuminate\Database\Query\Builder as QueryBuilder;

// Without a reachable reference into this fixture, findUnusedCode consolidates PostQueries and
// every PostBuilder method as unused code (UnusedClass / PossiblyUnusedMethod), and Psalm never
// reaches the discarded-return-value check at all — the regression would then pass vacuously.
function entry(PostBuilder $query, QueryBuilder $queryBuilder): void
{
    (new PostQueries())->run($query, $queryBuilder);
}
