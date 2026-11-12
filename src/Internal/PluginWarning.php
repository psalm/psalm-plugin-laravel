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

        // A closed STDERR (an app bootstrap may fclose() it) makes fwrite() throw a TypeError, and Psalm
        // turns a failed-write notice into an exception; either would abort plugin registration.
        if ($output instanceof VoidProgress && \defined('STDERR') && \get_resource_type(\STDERR) === 'stream') {
            @\fwrite(\STDERR, 'Warning: ' . $message . \PHP_EOL);
        }
    }
}
