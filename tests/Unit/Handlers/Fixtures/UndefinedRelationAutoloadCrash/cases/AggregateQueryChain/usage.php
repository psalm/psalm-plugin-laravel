<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateQueryChain;

function drive_query_chain(QueryTargetMaker $maker): void
{
    $first = $maker->query()->first();
}
