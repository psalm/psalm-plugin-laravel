<?php

declare(strict_types=1);

namespace AutoloadCrashFixture;

// Cast target and foreignIdFor() class: loading it fails, so its cast types must come from Psalm's storage.
\trigger_error('deprecated on load', \E_USER_DEPRECATED);

enum DeprecatedStatus: string
{
    case Open = 'open';
}
