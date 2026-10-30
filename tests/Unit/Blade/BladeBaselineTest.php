<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\TemplateLocation;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * #1988: a template finding goes through Psalm's own baseline like a PHP finding. The baseline
 * stores the issue's selected text per file and type, and a relocated finding selects the
 * template line's non-blank span ({@see TemplateLocation}), so that is the entry's `<code>`.
 *
 * A clean report under `--use-baseline` is not vacuous: `findUnusedBaselineEntry` is on by
 * default, so once a flagged line changes the same run reports its entry as unused, next to the
 * finding itself.
 */
#[CoversClass(TemplateLocation::class)]
#[Group('subprocess')]
final class BladeBaselineTest extends TestCase
{
    use CopiesFixtureDirectories;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeBaseline';

    private const TEMPLATE = 'resources/views/profile.blade.php';

    private const CHANGED_TEMPLATE = 'resources/views/account.blade.php';

    private const FLAGGED_LINE = '<p class="name">{{ (new \BladeBaselineFixture\Greeter())->nmae }}</p>';

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
    private function analyze(string $copy, array $arguments, int $exitCode): array
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--scan-threads=1', '--no-progress', '--output-format=json', ...$arguments],
            $copy,
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
     * One copy, two runs over two templates with the same kind of finding: write the baseline, then
     * change the flagged line of one template only and apply it against the shadow cache the first
     * run left behind. The changed template reports its finding again and a stale entry, while the
     * untouched one stays silent: that silence is the baseline matching, with the changed template
     * as the positive control that a finding would have shown.
     */
    #[Test]
    public function a_baseline_silences_a_template_finding_until_the_flagged_line_changes(): void
    {
        $copy = $this->fixtureCopy();

        // The run that writes a baseline still reports against the previous one, here none.
        $this->assertEqualsCanonicalizing(
            [
                ['type' => 'UndefinedPropertyFetch', 'file' => self::TEMPLATE, 'line' => 2],
                ['type' => 'UndefinedPropertyFetch', 'file' => self::CHANGED_TEMPLATE, 'line' => 2],
            ],
            $this->analyze($copy, ['--set-baseline=baseline.xml'], 2),
        );

        $baseline = (string) \file_get_contents($copy . '/baseline.xml');
        $this->assertStringContainsString('<file src="' . self::TEMPLATE . '">', $baseline);
        $this->assertStringContainsString(
            '<UndefinedPropertyFetch>' . "\n" . '      <code><![CDATA[' . self::FLAGGED_LINE . ']]></code>',
            $baseline,
            'the entry stores the whole template line, its indentation trimmed',
        );

        $path = $copy . '/' . self::CHANGED_TEMPLATE;
        $source = (string) \file_get_contents($path);
        $this->assertStringContainsString('class="role"', $source);
        \file_put_contents($path, \str_replace('class="role"', 'class="role bold"', $source));

        $this->assertEqualsCanonicalizing(
            [
                ['type' => 'UndefinedPropertyFetch', 'file' => self::CHANGED_TEMPLATE, 'line' => 2],
                ['type' => 'UnusedBaselineEntry', 'file' => self::CHANGED_TEMPLATE, 'line' => 0],
            ],
            $this->analyze($copy, ['--use-baseline=baseline.xml'], 2),
        );
    }
}
