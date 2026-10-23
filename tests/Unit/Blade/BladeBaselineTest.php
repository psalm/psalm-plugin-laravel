<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\TemplateLocation;
use Psalm\LaravelPlugin\Config\PluginOverrides;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * #1988: a template finding goes through Psalm's own baseline like a PHP finding. The baseline
 * stores the issue's selected text per file and type, and a relocated finding selects the whole
 * template line ({@see TemplateLocation}), so that line is the entry's `<code>`.
 *
 * An empty report under `--use-baseline` is not vacuous: `findUnusedBaselineEntry` is on by
 * default, so a run that analyzed no template would report the entry as `UnusedBaselineEntry`
 * (pinned by the Blade-off case).
 */
#[CoversClass(TemplateLocation::class)]
#[Group('subprocess')]
final class BladeBaselineTest extends TestCase
{
    use CopiesFixtureDirectories;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeBaseline';

    private const TEMPLATE = 'resources/views/profile.blade.php';

    private const FLAGGED_LINE = '<p class="name">{{ (new \BladeBaselineFixture\Greeter())->nmae }}</p>';

    private static ?string $baseline = null;

    private ?string $copy = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->copy !== null) {
            $this->removeDirectory($this->copy);
        }
    }

    /** A private copy: the runs write the baseline, the shadow cache and Psalm's cache into it. */
    private function fixtureCopy(): string
    {
        $this->copy = self::FIXTURE . '/.copy-' . (int) \getmypid() . '-' . \bin2hex(\random_bytes(4));
        $this->copyDirectory(self::FIXTURE, $this->copy);

        return $this->copy;
    }

    /**
     * The exit code is asserted, not just the report: Psalm prints the report before it writes its
     * caches and exits, so a crash after the output would still leave a passing `[]`.
     *
     * @param list<string> $arguments
     * @param 0|2 $exitCode 0 for a clean run, 2 for one that reports an error
     *
     * @return list<array{type: string, file: string, line: int}>
     */
    private function analyze(string $copy, array $arguments, int $exitCode, bool $blade = true): array
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', '-c', 'psalm.xml', '--threads=1', '--scan-threads=1', '--no-progress', '--output-format=json', ...$arguments],
            $copy,
            // false unsets an inherited value, so only this case decides the Blade mode.
            [PluginOverrides::ENV_VAR => $blade ? false : 'blade=false'],
        );
        $process->setTimeout(300);
        $process->run();

        $output = "\n{$process->getOutput()}\n{$process->getErrorOutput()}";
        $this->assertSame($exitCode, $process->getExitCode(), 'unexpected Psalm exit code.' . $output);

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, 'Psalm did not emit a JSON report.' . $output);

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = [
                'type' => (string) $issue['type'],
                'file' => (string) $issue['file_name'],
                'line' => (int) $issue['line_from'],
            ];
        }

        return $issues;
    }

    /**
     * A fresh copy holding a baseline written by a Blade-on run. The baseline is written once per
     * class: its paths are relative, so it applies to every copy unchanged.
     */
    private function baselinedCopy(): string
    {
        $copy = $this->fixtureCopy();

        if (self::$baseline !== null) {
            \file_put_contents($copy . '/baseline.xml', self::$baseline);

            return $copy;
        }

        // The run that writes a baseline still reports against the previous one, here none.
        $this->assertSame(
            [['type' => 'UndefinedPropertyFetch', 'file' => self::TEMPLATE, 'line' => 2]],
            $this->analyze($copy, ['--no-cache', '--set-baseline=baseline.xml'], 2),
        );

        $baseline = (string) \file_get_contents($copy . '/baseline.xml');
        $this->assertStringContainsString('<file src="' . self::TEMPLATE . '">', $baseline);
        $this->assertStringContainsString(
            '<UndefinedPropertyFetch>' . "\n" . '      <code><![CDATA[' . self::FLAGGED_LINE . ']]></code>',
            $baseline,
            'the entry stores the whole template line, its indentation trimmed',
        );
        self::$baseline = $baseline;

        // The cases below start cold, as a copy that never ran would.
        $this->removeDirectory($copy . '/.cache');

        return $copy;
    }

    private function editTemplate(string $copy, string $search, string $replace): void
    {
        $path = $copy . '/' . self::TEMPLATE;
        $source = (string) \file_get_contents($path);
        $this->assertStringContainsString($search, $source);
        \file_put_contents($path, \str_replace($search, $replace, $source));
    }

    #[Test]
    public function a_baseline_silences_template_findings_on_cold_and_warm_runs(): void
    {
        $copy = $this->baselinedCopy();
        $arguments = ['--use-baseline=baseline.xml'];

        $this->assertSame([], $this->analyze($copy, [...$arguments, '--no-cache'], 0), 'cold shadow cache, no Psalm cache');
        $this->assertFileExists($copy . '/.cache/blade-shadows/manifest.php');
        $this->assertSame([], $this->analyze($copy, $arguments, 0), 'warm shadow cache, cold Psalm cache');
        $this->assertSame([], $this->analyze($copy, $arguments, 0), 'warm shadow cache, warm Psalm cache');
    }

    /** Psalm trims both the stored entry and the selection it matches, so outer whitespace is free. */
    #[Test]
    public function moving_reindenting_or_trailing_space_on_the_flagged_line_keeps_the_entry(): void
    {
        $copy = $this->baselinedCopy();
        $this->editTemplate($copy, "<div>\n    <p", "<section>\n<div>\n        <p");
        $this->editTemplate($copy, "</p>\n", "</p>   \n");

        $this->assertSame([], $this->analyze($copy, ['--no-cache', '--use-baseline=baseline.xml'], 0));
    }

    #[Test]
    public function an_edit_elsewhere_on_the_flagged_line_invalidates_the_entry(): void
    {
        $copy = $this->baselinedCopy();
        $this->editTemplate($copy, 'class="name"', 'class="name bold"');

        $this->assertSame(
            [
                ['type' => 'UndefinedPropertyFetch', 'file' => self::TEMPLATE, 'line' => 2],
                ['type' => 'UnusedBaselineEntry', 'file' => self::TEMPLATE, 'line' => 0],
            ],
            $this->analyze($copy, ['--no-cache', '--use-baseline=baseline.xml'], 2),
        );
    }

    #[Test]
    public function a_blade_off_run_reports_the_template_entries_as_unused(): void
    {
        $copy = $this->baselinedCopy();

        $this->assertSame(
            [['type' => 'UnusedBaselineEntry', 'file' => self::TEMPLATE, 'line' => 0]],
            $this->analyze($copy, ['--no-cache', '--use-baseline=baseline.xml'], 2, blade: false),
        );
    }
}
