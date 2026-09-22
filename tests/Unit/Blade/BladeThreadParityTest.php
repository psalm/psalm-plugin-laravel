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
 * a second channel: its journey is rebuilt in the parent from the graphs the workers ship back.
 * Only comparing a forked run against a single-process run proves both channels lossless, so the
 * assertion is run-to-run equality rather than a golden list (#1530).
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
     * mode from silently dropping to a single process.
     *
     * @return array{output: string, raw: string, findings: list<string>}
     */
    private function analyze(string $fixture, int $threads, bool $taint): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

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
        // Not mustRun(): both fixtures report issues by design.
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        try {
            $this->assertFileExists($report, "Psalm wrote no report.\n{$output}");
            $raw = (string) \file_get_contents($report);
        } finally {
            if (\is_file($report)) {
                \unlink($report);
            }
        }

        $decoded = \json_decode($raw, true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$output}");

        $findings = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);

            $file = (string) $issue['file_name'];

            if (!\str_ends_with($file, '.blade.php')) {
                continue;
            }

            $findings[] = \sprintf(
                '%s:%d:%d %s %s%s',
                $file,
                (int) $issue['line_from'],
                (int) $issue['column_from'],
                (string) $issue['type'],
                (string) $issue['message'],
                $this->journey($issue),
            );
        }

        \sort($findings);

        return ['output' => $output, 'raw' => $raw, 'findings' => $findings];
    }

    /**
     * A taint finding is only equal if every step of its journey is, so the steps join the tuple.
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
            $steps[] = ($step['file_name'] ?? '?') . ':' . ((int) ($step['line_from'] ?? 0));
        }

        return ' | ' . \implode(' > ', $steps);
    }

    /** @param array{output: string, raw: string, findings: list<string>} $forked */
    private function assertForked(array $forked): void
    {
        $this->assertStringContainsString(
            'Forking analysis',
            $forked['output'],
            'The forked run never forked, so it proves nothing about workers.',
        );
    }

    #[Test]
    public function template_issues_are_identical_with_and_without_forked_workers(): void
    {
        $forked = $this->analyze(self::REMAP_FIXTURE, self::THREADS, false);
        $single = $this->analyze(self::REMAP_FIXTURE, 1, false);

        $this->assertForked($forked);
        $this->assertNotSame([], $single['findings'], 'The fixture reported no template issue at all.');
        $this->assertSame($single['findings'], $forked['findings']);
        $this->assertStringNotContainsString('blade-shadows-threads', $forked['raw'], $forked['raw']);
    }

    #[Test]
    public function template_taint_findings_and_journeys_are_identical_with_and_without_forked_workers(): void
    {
        $forked = $this->analyze(self::TAINT_FIXTURE, self::THREADS, true);
        $single = $this->analyze(self::TAINT_FIXTURE, 1, true);

        $this->assertForked($forked);
        $this->assertNotSame([], $single['findings'], 'The fixture reported no template taint finding at all.');
        $this->assertSame($single['findings'], $forked['findings']);
        $this->assertStringNotContainsString('blade-shadows-threads', $forked['raw'], $forked['raw']);
    }
}
