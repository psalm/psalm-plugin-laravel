<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

/**
 * Plain-text renderer for the {@see Diagnostics} report.
 *
 * Designed for terminal use. Output is grouped into sections separated by
 * blank lines; no ANSI colour codes are emitted so the output is safe to
 * redirect into a file.
 *
 * @internal
 *
 * @psalm-import-type Report from Diagnostics
 */
final class TextRenderer
{
    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    public function render(array $report): string
    {
        $sections = [];

        $sections[] = $this->renderVersions($report);
        $sections[] = $this->renderBoot($report);
        $sections[] = $this->renderStubs($report);
        $sections[] = $this->renderIntegrations($report);
        $sections[] = $this->renderHandlers($report);
        $sections[] = $this->renderSchema($report);

        if ($report['hard_failures'] !== []) {
            $sections[] = $this->renderHardFailures($report);
        }

        return \implode("\n\n", $sections) . "\n";
    }

    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    private function renderVersions(array $report): string
    {
        return \implode("\n", [
            '[Versions]',
            '  Plugin   : ' . ($report['versions']['plugin'] ?? '(unknown)'),
            '  Laravel  : ' . ($report['versions']['laravel'] ?? '(unknown)'),
            '  Psalm    : ' . ($report['versions']['psalm'] ?? '(unknown)'),
            '  PHP      : ' . $report['versions']['php'],
        ]);
    }

    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    private function renderBoot(array $report): string
    {
        if ($report['boot']['error'] !== null) {
            return "[Boot mode (#766)]\n  Status   : FAILED\n  Error    : " . $report['boot']['error'];
        }

        return \implode("\n", [
            '[Boot mode (#766)]',
            '  Mode     : ' . ($report['boot']['description'] ?? '(unknown)'),
            '  Path     : ' . ($report['boot']['path'] ?? '(unknown)'),
        ]);
    }

    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    private function renderStubs(array $report): string
    {
        $lines = ['[Stub loading]'];

        if ($report['stubs'] === []) {
            $lines[] = '  (no stub directories resolved)';
            return \implode("\n", $lines);
        }

        foreach ($report['stubs'] as $dir) {
            $lines[] = "  - {$dir['dir']}";
            $lines[] = "      reason     : {$dir['reason']}";
            $lines[] = "      file_count : {$dir['file_count']}";
        }

        return \implode("\n", $lines);
    }

    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    private function renderIntegrations(array $report): string
    {
        $lines = ['[Integration stubs]'];

        foreach ($report['integrations'] as $entry) {
            $status = $entry['installed'] ? 'installed' : 'not installed';
            $version = $entry['version'] !== null ? " ({$entry['version']})" : '';
            $lines[] = "  - {$entry['package']}: {$status}{$version}";
            $lines[] = "      note: {$entry['note']}";
        }

        return \implode("\n", $lines);
    }

    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    private function renderHandlers(array $report): string
    {
        $lines = ['[Handlers]', "  Total handler files: {$report['handlers']['total']}"];

        foreach ($report['handlers']['categories'] as $category => $count) {
            $lines[] = \sprintf('  %-20s %d', $category, $count);
        }

        return \implode("\n", $lines);
    }

    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    private function renderSchema(array $report): string
    {
        $lines = [
            '[Schema parsing]',
            "  Registry state       : {$report['schema']['state']} "
                . '(diagnose does not run MigrationSchemaBuilder; warm only after a real Psalm run with migrations enabled)',
            "  Migration files seen : {$report['schema']['migration_file_count']}",
        ];

        foreach ($report['schema']['migration_dirs'] as $dir) {
            $lines[] = "    - {$dir}";
        }

        return \implode("\n", $lines);
    }

    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    private function renderHardFailures(array $report): string
    {
        $lines = ['[Hard failures]'];

        foreach ($report['hard_failures'] as $failure) {
            $lines[] = "  ! {$failure}";
        }

        return \implode("\n", $lines);
    }
}
