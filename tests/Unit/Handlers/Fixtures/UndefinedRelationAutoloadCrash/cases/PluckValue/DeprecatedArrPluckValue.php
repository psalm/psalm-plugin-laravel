<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\PluckValue;

// Array value type of an Arr::pluck() argument: ModelPropertyResolver::extractExactlyOneModelFromUnion().
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedArrPluckValue
{
    public string $name = '';
}
