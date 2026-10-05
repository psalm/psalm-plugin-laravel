<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\AggregateAssignment;

function drive_assignment(): void
{
    $target = AggregateTargetMaker::make();
}
