<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Diagnostics;

use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

/**
 * A {@see Progress} decorator that captures `warning()` into a {@see DiagnosticsBuffer}
 * instead of printing it immediately, while forwarding every other output call
 * (`debug`, `write`, and the scanning-phase calls) straight to the wrapped instance.
 *
 * The plugin hands this to its initialisation steps so their warnings buffer (tagged with
 * the {@see setStage() current stage}) and surface together at a stable point, rather than
 * interleaving with Psalm's progress bars. {@see stopBuffering()} restores direct output
 * once that point is reached, so a warning raised later still prints.
 *
 * @psalm-import-type DiagnosticStage from Diagnostic
 *
 * @internal
 */
final class BufferedProgress extends Progress
{
    /** @var DiagnosticStage */
    private string $stage = 'internal';

    private bool $buffering = true;

    /** @psalm-mutation-free */
    public function __construct(
        private readonly Progress $inner,
        private readonly DiagnosticsBuffer $diagnostics,
    ) {}

    /**
     * Tag subsequently buffered warnings with the initialisation stage they came from.
     *
     * @param DiagnosticStage $stage
     *
     * @psalm-external-mutation-free
     */
    public function setStage(string $stage): void
    {
        $this->stage = $stage;
    }

    /**
     * Stop capturing warnings and route them straight to the wrapped progress again.
     * Called once the buffer has been flushed at the end of init, so a warning raised
     * later (e.g. by a handler that retained this instance) is printed, not dropped.
     *
     * @psalm-external-mutation-free
     */
    public function stopBuffering(): void
    {
        $this->buffering = false;
    }

    #[\Override]
    public function warning(string $message): void
    {
        if ($this->buffering) {
            $this->diagnostics->warning($this->stage, $message);

            return;
        }

        $this->inner->warning($message);
    }

    #[\Override]
    public function debug(string $message): void
    {
        $this->inner->debug($message);
    }

    // `write()` is concrete on Progress (not abstract), so override it explicitly:
    // a bare `Progress::write()` would go straight to STDERR and bypass the wrapped
    // instance (e.g. a VoidProgress's output suppression).
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
