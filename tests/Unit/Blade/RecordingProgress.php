<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

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

    public function warningText(): string
    {
        return \implode("\n", $this->warnings);
    }
}
