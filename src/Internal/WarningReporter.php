<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Progress\Progress;
use Psalm\Progress\VoidProgress;

/**
 * Keeps plugin diagnostic warnings visible independently of Psalm's progress UI.
 *
 * Psalm deliberately makes {@see VoidProgress::write()} a no-op for
 * `--no-progress`. Because {@see Progress::warning()} delegates to write(),
 * warnings would otherwise disappear along with progress bars. Keep using the
 * configured progress implementation when it is active, but preserve warnings
 * on STDERR when Psalm installs VoidProgress.
 *
 * @internal
 */
final class WarningReporter
{
    /**
     * @param resource|null $fallbackStream Test seam for the VoidProgress path.
     */
    public static function emit(Progress $progress, string $message, mixed $fallbackStream = null): void
    {
        if (!$progress instanceof VoidProgress) {
            $progress->warning($message);

            return;
        }

        $fallbackStream ??= \STDERR;

        // Warning output must never replace the diagnostic it is trying to
        // surface. Psalm promotes PHP warnings to exceptions, and fwrite()
        // can also throw a TypeError for a stream that was closed meanwhile.
        try {
            @\fwrite($fallbackStream, 'Warning: ' . $message . \PHP_EOL);
        } catch (\Throwable) {
            // There is no secondary output channel left to report this failure.
        }
    }
}
