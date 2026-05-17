<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

/**
 * Plain-text renderer for the {@see Report} produced by {@see Diagnostics}.
 *
 * Designed for terminal use. No ANSI colour codes so the output is safe to
 * redirect into a file or pipe.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class TextRenderer
{
    /** Presentation labels for boot modes — kept here (not in ApplicationProvider) since this is purely UI text. */
    private const BOOT_MODE_LABELS = [
        'bootstrap' => 'real bootstrap/app.php discovered',
        'testbench_fallback' => 'Testbench fallback',
    ];

    /** @psalm-pure */
    public function render(Report $report): string
    {
        $lines = [
            '[Versions]',
            '  Plugin   : ' . ($report->pluginVersion ?? '(unknown)'),
            '  Laravel  : ' . ($report->laravelVersion ?? '(unknown)'),
            '  Psalm    : ' . ($report->psalmVersion ?? '(unknown)'),
            '  PHP      : ' . $report->phpVersion,
            '',
            '[Boot mode]',
        ];

        if ($report->bootError !== null) {
            $lines[] = '  Status   : FAILED';
            $lines[] = '  Error    : ' . $report->bootError;
        } else {
            $label = $report->bootMode !== null ? self::BOOT_MODE_LABELS[$report->bootMode] ?? null : null;
            $lines[] = '  Mode     : ' . ($label ?? '(unknown)');
            $lines[] = '  Path     : ' . ($report->bootPath ?? '(unknown)');
        }

        if ($report->hardFailures !== []) {
            $lines[] = '';
            $lines[] = '[Hard failures]';
            foreach ($report->hardFailures as $failure) {
                $lines[] = '  ! ' . $failure;
            }
        }

        return \implode("\n", $lines) . "\n";
    }
}
