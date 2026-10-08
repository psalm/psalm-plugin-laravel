<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

final class HookedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public string $notAHook = 'plain';

    /** @return list<object> */
    public function middleware(): array
    {
        return [];
    }

    public function uniqueId(): string
    {
        return self::class;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return new \DateTimeImmutable();
    }

    public function handle(): void
    {
        \assert(true);
    }
}
