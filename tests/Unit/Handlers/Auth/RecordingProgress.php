<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Auth;

use Psalm\Progress\Progress;

/**
 * A {@see Progress} that records warnings instead of writing them to STDERR, so a test can assert
 * whether {@see \Psalm\LaravelPlugin\Handlers\Auth\GuardTaintHandler} warned about a missing guard
 * method.
 */
final class RecordingProgress extends Progress
{
    /** @var list<string> */
    public array $warnings = [];

    #[\Override]
    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }
}
