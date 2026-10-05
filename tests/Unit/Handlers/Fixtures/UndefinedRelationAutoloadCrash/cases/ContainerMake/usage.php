<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\ContainerMake;

function drive_container_make(): void
{
    $service = app('AutoloadCrashFixture\Cases\ContainerMake\DeprecatedService');
}
