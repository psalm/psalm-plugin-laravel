<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Support;

use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

/**
 * Test spy for {@see Progress}: records warnings without writing to STDERR.
 *
 * All Psalm `Progress` subclasses must implement every abstract method, so
 * the other hooks are stubbed as no-ops. Only `warning()` is captured because
 * that's the surface plugin handlers use to emit user-facing diagnostics
 * (config typos, missing project `config/` directory, etc).
 */
final class RecordingProgress extends Progress
{
    public int $warningCount = 0;

    public string $lastWarning = '';

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

    #[\Override]
    public function write(string $message): void {}

    #[\Override]
    public function warning(string $message): void
    {
        $this->warningCount++;
        $this->lastWarning = $message;
    }
}
