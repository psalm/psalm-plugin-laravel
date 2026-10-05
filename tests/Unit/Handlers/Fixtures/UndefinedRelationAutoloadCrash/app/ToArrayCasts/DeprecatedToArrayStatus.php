<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\ToArrayCasts;

// Enum cast target: the registry must classify it from Psalm's storage. Loading it ends the run.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

enum DeprecatedToArrayStatus: string
{
    case Open = 'open';
}
