<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeIssueRemapHandler;
use Psalm\LaravelPlugin\Blade\JourneyRemapper;
use Psalm\LaravelPlugin\Blade\ShadowIssueRelocator;
use Symfony\Component\Process\Process;

/**
 * Template findings must survive Psalm's forked analysis pool unchanged.
 *
 * Shadows are compiled and the registries the remap reads are filled in `Plugin::__invoke()`,
 * before Psalm forks, but the remap itself re-emits from inside whichever worker analyzed the
 * shadow, and that issue travels home through the pool's serialized payload. A taint finding adds
 * a second channel: its journey is rebuilt in the parent from the graphs the workers ship back, and
 * the finding it belongs to can sit on ordinary application code that a template merely fed (#1519).
 * Only comparing a forked run against a single-process run proves both channels lossless, so the
 * assertion is run-to-run equality rather than a golden list (#1530).
 *
 * The whole report is compared, not the template-path subset: the fixtures are controlled, so full
 * equality is strictly stronger, and the #1519 finding lives on a `.php` path that a template-path
 * filter would drop precisely where it matters most.
 */
#[CoversClass(BladeIssueRemapHandler::class)]
#[CoversClass(JourneyRemapper::class)]
#[CoversClass(ShadowIssueRelocator::class)]
final class BladeThreadParityTest extends TestCase
{
    private const REMAP_FIXTURE = __DIR__ . '/Fixtures/BladeIssueRemap';

    private const TAINT_FIXTURE = __DIR__ . '/Fixtures/BladeTaintRemap';

    /** Own shadow dir, so a parallel-safe run never shares state with the other Blade suites. */
    private const SHADOW_DIR = '/.cache/blade-shadows-threads';

    /** Forking only happens when there are more files to analyze than workers. */
    private const THREADS = 4;

    /** The #1519 channel: the sink is application code, only the journey names the template. */
    private const JOURNEY_SINK = 'app/Sink.php';

    private const JOURNEY_TEMPLATE = 'resources/views/external.blade.php';

    protected function setUp(): void
    {
        // Psalm silently clamps --threads to 1 on Windows or without pcntl, so the fork proof
        // would fail there instead of proving anything.
        if (\defined('PHP_WINDOWS_VERSION_MAJOR') || !\extension_loaded('pcntl')) {
            self::markTestSkipped('Psalm cannot fork analysis workers in this environment.');
        }

        $this->deleteShadowDirs();
    }

    protected function tearDown(): void
    {
        $this->deleteShadowDirs();
    }

    private function deleteShadowDirs(): void
    {
        foreach ([self::REMAP_FIXTURE, self::TAINT_FIXTURE] as $fixture) {
            $dir = $fixture . self::SHADOW_DIR;

            if (!\is_dir($dir)) {
                continue;
            }

            foreach (\array_diff(\scandir($dir) ?: [], ['.', '..']) as $entry) {
                \unlink($dir . '/' . $entry);
            }

            \rmdir($dir);
        }
    }

    /**
     * One real Psalm run. `--debug` is what makes the forked cell provable rather than assumed:
     * it prints the pool's own "Forking analysis" line, and an explicit `--threads` keeps debug
     * mode from silently dropping to a single process. The shadow cache is cleared first, so the
     * two cells differ in thread count alone rather than also in cache warmth.
     *
     * @return array{output: string, raw: string, findings: list<string>, issues: list<array<string, mixed>>}
     */
    private function analyze(string $fixture, int $threads, bool $taint): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $this->deleteShadowDirs();

        $report = \sys_get_temp_dir() . '/blade-thread-parity-' . \bin2hex(\random_bytes(8)) . '.json';

        $arguments = [
            \PHP_BINARY,
            $psalmBinary,
            '-c',
            'psalm-threads.xml',
            '--no-cache',
            '--threads=' . $threads,
            '--scan-threads=' . $threads,
            '--debug',
            '--report=' . $report,
        ];

        if ($taint) {
            $arguments[] = '--taint-analysis';
        }

        $process = new Process($arguments, $fixture);
        $process->setTimeout(600);

        try {
            // Not mustRun(): both fixtures report issues by design.
            $process->run();

            $output = $process->getOutput() . $process->getErrorOutput();

            $this->assertFileExists($report, "Psalm wrote no report.\n{$output}");
            $raw = (string) \file_get_contents($report);
        } finally {
            if (\is_file($report)) {
                \unlink($report);
            }
        }

        $decoded = \json_decode($raw, true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$output}");

        $issues = [];
        $findings = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);

            $issues[] = $issue;
            $findings[] = \sprintf(
                '%s:%d:%d %s %s%s',
                (string) $issue['file_name'],
                (int) $issue['line_from'],
                (int) $issue['column_from'],
                (string) $issue['type'],
                (string) $issue['message'],
                $this->journey($issue),
            );
        }

        \sort($findings);

        return ['output' => $output, 'raw' => $raw, 'findings' => $findings, 'issues' => $issues];
    }

    /**
     * A taint finding is only equal if every step of its journey is, so the steps join the tuple.
     * Label included: the entry step carries no location, and label is the only thing identifying
     * it, so dropping it would let two different sources compare equal.
     *
     * @param array<string, mixed> $issue
     */
    private function journey(array $issue): string
    {
        $trace = $issue['taint_trace'] ?? null;

        if (!\is_array($trace)) {
            return '';
        }

        $steps = [];

        foreach ($trace as $step) {
            $this->assertIsArray($step);
            $steps[] = $this->field($step, 'label')
                . '@' . $this->field($step, 'file_name')
                . ':' . $this->field($step, 'line_from')
                . '-' . $this->field($step, 'line_to')
                . ':' . $this->field($step, 'column_from')
                . '-' . $this->field($step, 'column_to');
        }

        return ' | ' . \implode(' > ', $steps);
    }

    /** @param array<array-key, mixed> $step */
    private function field(array $step, string $key): string
    {
        $value = $step[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '?';
    }

    /** @param array{output: string, raw: string, findings: list<string>, issues: list<array<string, mixed>>} $forked */
    private function assertForked(array $forked): void
    {
        $this->assertStringContainsString(
            'Forking analysis',
            $forked['output'],
            'The forked run never forked, so it proves nothing about workers.',
        );
    }

    /**
     * An issue with no journey compares equal to any other journeyless issue, so a taint run whose
     * findings lost their trails would pass equality by vacuity.
     *
     * @param list<array<string, mixed>> $issues
     */
    private function assertEveryFindingCarriesAJourney(array $issues, string $label): void
    {
        foreach ($issues as $issue) {
            $trace = $issue['taint_trace'] ?? null;

            $this->assertIsArray($trace, "{$label}: a taint finding carries no journey.");
            $this->assertNotSame([], $trace, "{$label}: a taint finding carries an empty journey.");
        }
    }

    #[Test]
    public function issues_are_identical_with_and_without_forked_workers(): void
    {
        $forked = $this->analyze(self::REMAP_FIXTURE, self::THREADS, false);
        $single = $this->analyze(self::REMAP_FIXTURE, 1, false);

        $this->assertForked($forked);
        $this->assertSame($single['findings'], $forked['findings']);
        $this->assertNotSame(
            [],
            \array_filter($single['findings'], static fn(string $finding): bool => \str_contains($finding, '.blade.php:')),
            'The fixture reported no template issue at all, so equality proves nothing.',
        );
        $this->assertStringNotContainsString('blade-shadows-threads', $forked['raw'], $forked['raw']);
    }

    #[Test]
    public function taint_findings_and_journeys_are_identical_with_and_without_forked_workers(): void
    {
        $forked = $this->analyze(self::TAINT_FIXTURE, self::THREADS, true);
        $single = $this->analyze(self::TAINT_FIXTURE, 1, true);

        $this->assertForked($forked);
        $this->assertSame($single['findings'], $forked['findings']);
        $this->assertEveryFindingCarriesAJourney($single['issues'], 'single-process run');
        $this->assertEveryFindingCarriesAJourney($forked['issues'], 'forked run');

        // #1519 specifically: the finding sits on application code and only its journey names the
        // template, which is the one shape a template-path comparison cannot see.
        $journeys = [];

        foreach ($single['issues'] as $issue) {
            if ($issue['file_name'] === self::JOURNEY_SINK && $issue['type'] === 'TaintedHtml') {
                $journeys[] = $this->journey($issue);
            }
        }

        $this->assertCount(1, $journeys, 'The fixture stopped reporting the journey-only finding.');
        $this->assertStringContainsString(self::JOURNEY_TEMPLATE, $journeys[0], $journeys[0]);
        $this->assertStringNotContainsString('blade-shadows-threads', $forked['raw'], $forked['raw']);
    }
}
