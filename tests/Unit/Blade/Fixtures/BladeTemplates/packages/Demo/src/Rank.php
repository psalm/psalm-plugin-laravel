<?php

declare(strict_types=1);

namespace Fx\Demo;

/**
 * An enum Psalm never scans: `packages/` is outside `<projectFiles>` and nothing autoloads it, so it is
 * known to the analysis only because the plugin's alias stub names it. Registered as the `Rank` alias.
 */
enum Rank: string
{
    case First = '1st';
}
