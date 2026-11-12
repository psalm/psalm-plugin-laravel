<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Progress\Progress;
use Psalm\Progress\VoidProgress;

/**
 * A warning the user must see even under `--no-progress`, which installs {@see VoidProgress}
 * and drops every {@see Progress::warning()}. STDERR keeps stdout clean for `--output-format=json`.
 *
 * @internal
 */
final class PluginWarning
{
    public static function emit(Progress $output, string $message): void
    {
        $output->warning($message);

        // Silenced: Psalm turns the notice of a write to a closed STDERR into an exception,
        // which would abort plugin registration.
        if ($output instanceof VoidProgress && \defined('STDERR')) {
            @\fwrite(\STDERR, 'Warning: ' . $message . \PHP_EOL);
        }
    }
}
