<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Dedicated class for the instance-call path (modelFromAtomic() -> isClassOrSubclassOf()), kept
// separate from DeprecatedOnLoad (the static-call path): a crash is fatal, so once one class's
// autoload site crashes, no later statement in the file runs — sharing one class would let a fix
// on one site mask a regression on the other.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

class DeprecatedOnLoadInstance
{
    public function with(string $relation): static
    {
        return $this;
    }
}
