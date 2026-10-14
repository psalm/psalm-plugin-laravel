<?php

declare(strict_types=1);

namespace BoundClassResolutionFixture;

// `Illuminate\Support\Composer` is bound to the container but never named here or by a stub, so it
// is scanned only if the plugin queues the class the 'composer' binding resolves to. Unscanned, the
// resolver declines and `dumpAutoloads()` is a call on `mixed`.
function exerciseComposer(): int
{
    $status = app('composer')->dumpAutoloads();
    /** @psalm-check-type-exact $status = int */

    return $status;
}
