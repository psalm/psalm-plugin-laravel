<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

/**
 * Plain-text renderer for the {@see Diagnostics} report.
 *
 * Designed for terminal use. No ANSI colour codes so the output is safe to
 * redirect into a file or pipe.
 *
 * @internal
 *
 * @psalm-import-type Report from Diagnostics
 *
 * @psalm-immutable
 */
final class TextRenderer
{
    /**
     * @param Report $report
     *
     * @psalm-pure
     */
    public function render(array $report): string
    {
        $lines = [
            '[Versions]',
            '  Plugin   : ' . ($report['versions']['plugin'] ?? '(unknown)'),
            '  Laravel  : ' . ($report['versions']['laravel'] ?? '(unknown)'),
            '  Psalm    : ' . ($report['versions']['psalm'] ?? '(unknown)'),
            '  PHP      : ' . $report['versions']['php'],
            '',
            '[Boot mode (#766)]',
        ];

        if ($report['boot']['error'] !== null) {
            $lines[] = '  Status   : FAILED';
            $lines[] = '  Error    : ' . $report['boot']['error'];
        } else {
            $lines[] = '  Mode     : ' . ($report['boot']['description'] ?? '(unknown)');
            $lines[] = '  Path     : ' . ($report['boot']['path'] ?? '(unknown)');
        }

        if ($report['hard_failures'] !== []) {
            $lines[] = '';
            $lines[] = '[Hard failures]';
            foreach ($report['hard_failures'] as $failure) {
                $lines[] = '  ! ' . $failure;
            }
        }

        return \implode("\n", $lines) . "\n";
    }
}
