<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateProperty;

function drive_aggregate_property(AggregatePropertyModel $model): void
{
    $count = $model->stats_count;
}
