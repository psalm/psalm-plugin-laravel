<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Foundation\Bus\Dispatchable;

final class DispatchedJob
{
    use Dispatchable;

    public function __construct(private readonly string $payload) {}

    public function handle(): void
    {
        \assert($this->payload !== '');
    }
}
