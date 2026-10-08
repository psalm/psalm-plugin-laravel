<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Contracts\Queue\ShouldQueue;
use IndirectMethodReferencesFixture\Dependencies\ListenerEvent;

final class QueuedListener implements ShouldQueue
{
    public int $tries = 2;

    public function handle(ListenerEvent $event): void
    {
        \assert(\is_object($event));
    }
}
