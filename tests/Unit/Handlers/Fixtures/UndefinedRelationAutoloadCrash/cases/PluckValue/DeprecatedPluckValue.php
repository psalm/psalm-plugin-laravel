<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\PluckValue;

// Collection value type of a pluck() receiver: ModelPropertyResolver::extractModelFromUnion().
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

final class DeprecatedPluckValue
{
    public string $name = '';
}
