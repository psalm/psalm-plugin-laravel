<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures;

use Psalm\Progress\Progress;

/**
 * Records warnings instead of writing them. Prefer it over VoidProgress in unit tests: plugin
 * code falls back to writing straight to STDERR under VoidProgress, which leaks into test output.
 *
 * Psalm 6's Progress has no abstract methods, unlike Psalm 7's (debug(), startPhase(), ...), so
 * only the two output methods are overridden here.
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
}
