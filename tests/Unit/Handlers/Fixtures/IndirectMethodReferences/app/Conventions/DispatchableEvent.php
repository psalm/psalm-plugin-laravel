<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Events `dispatch()` runs `new static()` in the event's own scope, so a private constructor is valid.
 * The public handle() is no entry point: an event is not a bus job, so it stays reportable.
 */
final class PrivateConstructorEvent
{
    use Dispatchable;

    private function __construct(private readonly string $payload) {}

    public function handle(): void
    {
        \assert($this->payload !== '');
    }
}
