<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

/**
 * A {@see Progress} that records warnings and debug lines instead of writing them to STDERR, so a
 * test can assert which degradation cause {@see \Psalm\LaravelPlugin\Blade\BladeBootstrapper} named.
 */
final class RecordingProgress extends Progress
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $debugMessages = [];

    #[\Override]
    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    #[\Override]
    public function debug(string $message): void
    {
        $this->debugMessages[] = $message;
    }

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

    public function warningText(): string
    {
        return \implode("\n", $this->warnings);
    }
}
