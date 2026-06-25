<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Diagnostics;

use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

/**
 * A {@see Progress} decorator that captures init-time `warning()` calls into a
 * {@see DiagnosticsBuffer} instead of writing them straight to Psalm's output.
 *
 * The plugin wraps Psalm's real progress in this for the duration of
 * {@see \Psalm\LaravelPlugin\Plugin::__invoke()}. Warnings emitted while booting
 * Laravel and registering handlers/stubs would otherwise interleave with Psalm's
 * progress bars; buffering lets the plugin flush one grouped block at a stable
 * point, or fold them into the internal-error report on failure.
 *
 * Every other Progress call forwards to the wrapped progress unchanged, so
 * progress bars, debug output, and phase reporting behave exactly as before.
 *
 * @psalm-import-type DiagnosticStage from Diagnostic
 *
 * @internal
 */
final class BufferedProgress extends Progress
{
    private readonly DiagnosticsBuffer $diagnostics;

    /**
     * Lifecycle stage tagging subsequent {@see warning()} calls. {@see \Psalm\LaravelPlugin\Plugin}
     * advances this as it moves through boot → schema → … → stubs, so each captured
     * warning records its init phase without changing the `$output->warning(...)` call sites.
     *
     * @psalm-var DiagnosticStage
     */
    private string $stage = 'internal';

    /** @psalm-mutation-free */
    public function __construct(private readonly Progress $inner)
    {
        $this->diagnostics = new DiagnosticsBuffer();
    }

    /**
     * Tag subsequent {@see warning()} calls with the current init lifecycle stage.
     *
     * @param DiagnosticStage $stage
     *
     * @psalm-external-mutation-free
     */
    public function stage(string $stage): void
    {
        $this->stage = $stage;
    }

    /** @psalm-mutation-free */
    public function inner(): Progress
    {
        return $this->inner;
    }

    /**
     * Emit the buffered diagnostics as one grouped block to the wrapped progress.
     * No-op when nothing was collected.
     *
     * Clears before writing so the buffer is single-shot regardless of the write's
     * outcome: a later flush (or the internal-error reporter) cannot reprint the
     * block, and a throwing write can never leave entries behind to be re-emitted.
     */
    public function flush(): void
    {
        if ($this->diagnostics->isEmpty()) {
            return;
        }

        $block = $this->diagnostics->format();
        $this->diagnostics->clear();
        $this->inner->write($block . "\n");
    }

    /**
     * Captured into the buffer instead of writing through — see the class docblock.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public function warning(string $message): void
    {
        $this->diagnostics->warning($this->stage, $message);
    }

    #[\Override]
    public function setErrorReporting(): void
    {
        $this->inner->setErrorReporting();
    }

    #[\Override]
    public function debug(string $message): void
    {
        $this->inner->debug($message);
    }

    #[\Override]
    public function write(string $message): void
    {
        $this->inner->write($message);
    }

    #[\Override]
    public function startPhase(Phase $phase, int $threads = 1): void
    {
        $this->inner->startPhase($phase, $threads);
    }

    #[\Override]
    public function expand(int $number_of_tasks): void
    {
        $this->inner->expand($number_of_tasks);
    }

    #[\Override]
    public function taskDone(int $level): void
    {
        $this->inner->taskDone($level);
    }

    #[\Override]
    public function finish(): void
    {
        $this->inner->finish();
    }

    #[\Override]
    public function alterFileDone(string $file_name): void
    {
        $this->inner->alterFileDone($file_name);
    }
}
