<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Not @-suppressed: Psalm's error handler turns this into a thrown exception, crashing the run.
// Not a Model subclass: ModelRegistrationHandler autoloads every Model on its own, which would
// crash here first and defeat the repro's isolation of this handler's fix.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

class DeprecatedOnLoad
{
    public static function with(string $relation): static
    {
        return new static();
    }
}
