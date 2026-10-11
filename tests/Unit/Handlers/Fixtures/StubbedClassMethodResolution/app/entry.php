<?php

declare(strict_types=1);

namespace StubbedClassMethodResolutionFixture;

// #1616: `Illuminate\Events\Dispatcher` is re-declared by a partial stub and never named here, so
// nothing queues its vendor file during Psalm's main scan. Without the plugin queueing it, the stub
// becomes the class's only source, and `hasListeners()` (absent from the stub) falls through
// Macroable `__call` to `mixed` with no issue reported.
function exerciseDispatcher(): bool
{
    $hasListeners = app('events')->hasListeners('event');
    /** @psalm-check-type-exact $hasListeners = bool */

    return $hasListeners;
}
