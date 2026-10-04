<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Assignment path (#1652): ModelAggregateLoadHandler tracks `$x = DeprecatedOnLoadAssigned::make()` and
// asked `is_a(..., Model::class, true)` of the inferred type, autoloading this file. Dedicated class so
// a crash here cannot be masked by (or mask) the other sites' classes.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

class DeprecatedOnLoadAssigned
{
    public static function make(): static
    {
        return new static();
    }
}
