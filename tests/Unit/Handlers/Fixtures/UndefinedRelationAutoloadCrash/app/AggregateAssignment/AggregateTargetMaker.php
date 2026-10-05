<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\AggregateAssignment;

final class AggregateTargetMaker
{
    public static function make(): DeprecatedAggregateTarget
    {
        return new DeprecatedAggregateTarget();
    }
}
