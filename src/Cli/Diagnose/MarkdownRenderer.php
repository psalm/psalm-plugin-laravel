<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Diagnose;

/**
 * Markdown renderer for the {@see Diagnostics} report.
 *
 * Tuned for pasting directly into a GitHub issue or pull-request comment:
 * sections become `##` headings and tabular data becomes pipe-tables so
 * GitHub renders them legibly without manual editing.
 *
 * @internal
 *
 * @psalm-import-type Report from Diagnostics
 */
final class MarkdownRenderer
{
    /**
     * @param Report $report
     *
     * @psalm-mutation-free
     */
    public function render(array $report): string
    {
        $lines = [];
        $lines[] = '## psalm-plugin-laravel diagnose';
        $lines[] = '';

        $lines[] = '### Versions';
        $lines[] = '';
        $lines[] = '| Component | Version |';
        $lines[] = '| --- | --- |';
        $lines[] = '| Plugin | ' . $this->cell($report['versions']['plugin']) . ' |';
        $lines[] = '| Laravel | ' . $this->cell($report['versions']['laravel']) . ' |';
        $lines[] = '| Psalm | ' . $this->cell($report['versions']['psalm']) . ' |';
        $lines[] = '| PHP | ' . $this->cell($report['versions']['php']) . ' |';
        $lines[] = '';

        $lines[] = '### Boot mode';
        $lines[] = '';
        if ($report['boot']['error'] !== null) {
            $lines[] = '**Boot FAILED**: ' . $report['boot']['error'];
        } else {
            $lines[] = '- Mode: `' . ($report['boot']['mode'] ?? 'unknown') . '` — ' . ($report['boot']['description'] ?? '');
            $lines[] = '- Path: `' . ($report['boot']['path'] ?? 'unknown') . '`';
        }
        $lines[] = '';

        $lines[] = '### Stub loading';
        $lines[] = '';
        if ($report['stubs'] === []) {
            $lines[] = '_No stub directories resolved._';
        } else {
            $lines[] = '| Directory | Reason | Files |';
            $lines[] = '| --- | --- | --- |';
            foreach ($report['stubs'] as $dir) {
                $lines[] = '| `' . $dir['dir'] . '` | ' . $dir['reason'] . ' | ' . $dir['file_count'] . ' |';
            }
        }
        $lines[] = '';

        $lines[] = '### Integration stubs';
        $lines[] = '';
        $lines[] = '| Package | Installed | Version | Note |';
        $lines[] = '| --- | --- | --- | --- |';
        foreach ($report['integrations'] as $entry) {
            $lines[] = '| `' . $entry['package'] . '` | '
                . ($entry['installed'] ? 'yes' : 'no') . ' | '
                . $this->cell($entry['version']) . ' | '
                . $entry['note'] . ' |';
        }
        $lines[] = '';

        $lines[] = '### Handlers';
        $lines[] = '';
        $lines[] = '_Total handler files: ' . $report['handlers']['total'] . '_';
        $lines[] = '';
        if ($report['handlers']['categories'] !== []) {
            $lines[] = '| Category | Files |';
            $lines[] = '| --- | --- |';
            foreach ($report['handlers']['categories'] as $category => $count) {
                $lines[] = '| ' . $category . ' | ' . $count . ' |';
            }
        }
        $lines[] = '';

        $lines[] = '### Schema parsing';
        $lines[] = '';
        $lines[] = '- Registry state: `' . $report['schema']['state'] . '` '
            . '(diagnose does not run MigrationSchemaBuilder; warm only after a real Psalm run with migrations enabled)';
        $lines[] = '- Migration files seen: ' . $report['schema']['migration_file_count'];
        foreach ($report['schema']['migration_dirs'] as $dir) {
            $lines[] = '  - `' . $dir . '`';
        }
        $lines[] = '';

        if ($report['hard_failures'] !== []) {
            $lines[] = '### Hard failures';
            $lines[] = '';
            foreach ($report['hard_failures'] as $failure) {
                $lines[] = '- ' . $failure;
            }
            $lines[] = '';
        }

        return \implode("\n", $lines);
    }

    /** @psalm-mutation-free */
    private function cell(?string $value): string
    {
        return $value !== null && $value !== '' ? '`' . $value . '`' : '_unknown_';
    }
}
