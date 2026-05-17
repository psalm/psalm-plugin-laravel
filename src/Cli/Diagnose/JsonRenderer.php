<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

/**
 * JSON renderer for the {@see Diagnostics} report.
 *
 * Output is pretty-printed and includes a trailing newline so it pipes cleanly
 * into `jq` or files. Suitable for CI / bug-report templates.
 *
 * @internal
 *
 * @psalm-import-type Report from Diagnostics
 */
final class JsonRenderer
{
    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    public static function render(array $report): string
    {
        return \json_encode(
            $report,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
