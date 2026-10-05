<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateProperty;

use Illuminate\Database\Eloquent\Model;

final class AggregatePropertyModel extends Model
{
    public function stats(): DeprecatedAggregateStats
    {
        return new DeprecatedAggregateStats();
    }
}
