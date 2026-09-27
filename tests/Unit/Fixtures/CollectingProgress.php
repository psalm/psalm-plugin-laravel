<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures;

use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

/**
 * Records warnings instead of writing them. Prefer it over VoidProgress in unit tests: plugin
 * code falls back to writing straight to STDERR under VoidProgress, which leaks into test output.
 *
 * @internal
 */
final class CollectingProgress extends Progress
{
    /** @var list<string> */
    public array $warnings = [];

    #[\Override]
    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    #[\Override]
    public function write(string $message): void {}

    #[\Override]
    public function debug(string $message): void {}

    #[\Override]
    public function startPhase(Phase $phase, int $threads = 1): void {}

    #[\Override]
    public function expand(int $number_of_tasks): void {}

    #[\Override]
    public function taskDone(int $level): void {}

    #[\Override]
    public function finish(): void {}

    #[\Override]
    public function alterFileDone(string $file_name): void {}
}
