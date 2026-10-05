<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\ContainerStringBinding;

function drive_class_string_binding(): void
{
    $service = app('service.class');
    /** @psalm-check-type-exact $service = DeprecatedBoundService */
}
