<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateQueryChain;

final class QueryTargetMaker
{
    public function query(): DeprecatedQueryTarget
    {
        return new DeprecatedQueryTarget();
    }
}
