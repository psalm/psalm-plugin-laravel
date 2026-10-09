<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Foundation\Bus\Dispatchable;

/** `dispatch()` runs `new static()` in the job's own scope, so a private constructor is valid. */
final class PrivateConstructorJob
{
    use Dispatchable;

    private function __construct(private readonly string $payload) {}

    public function handle(): void
    {
        \assert($this->payload !== '');
    }
}
