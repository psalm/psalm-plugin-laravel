<?php

declare(strict_types=1);

namespace AutoloadCrashFixture\Cases\DirectCast;

// Enum named by an `enum:` cast: CastResolver checks that it is an enum.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

enum DeprecatedDirectStatus: string
{
    case Open = 'open';
}
