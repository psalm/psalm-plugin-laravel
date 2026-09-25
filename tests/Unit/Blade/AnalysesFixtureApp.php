<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Symfony\Component\Process\Process;

/**
 * One real `vendor/bin/psalm` run per distinct (fixture, arguments) pair, shared by every case in
 * the class that asks for it.
 *
 * A fixture run costs about four seconds of boot and scan whatever the fixture's size, so a class
 * asserting a dozen facts about one report paid that cost a dozen times. Reading the memoized
 * output is the same evidence: the command and the working directory are byte-identical, so the
 * report is too.
 *
 * Shadow artifacts are snapshotted per run rather than read back from the fixture. Two runs of one
 * fixture share a shadow directory and each wipes it, and `executionOrder="defects"` in
 * `phpunit.xml.dist` can reorder the cases, so a case reading the live directory would otherwise
 * assert against whichever run happened to go last.
 *
 * A using class must not declare its own `tearDownAfterClass()`: PHP silently prefers the class
 * method over the trait's, which would skip the scratch-directory cleanup below. Override
 * `resetFixtureAnalyses()` instead for extra per-class teardown.
 *
 * @psalm-require-extends \PHPUnit\Framework\TestCase
 */
trait AnalysesFixtureApp
{
    /** @var array<string, array{output: string, errorOutput: string, shadows: string}> */
    private static array $fixtureRuns = [];

    /** @var array<string, true> directories to remove once the class is done */
    private static array $fixtureScratchDirs = [];

    public static function tearDownAfterClass(): void
    {
        static::resetFixtureAnalyses();

        self::$fixtureRuns = [];

        foreach (\array_keys(self::$fixtureScratchDirs) as $directory) {
            self::deleteDirectory($directory);
        }

        self::$fixtureScratchDirs = [];
    }

    /** Overridden by a class that memoizes a report derived from a run it builds itself. */
    protected static function resetFixtureAnalyses(): void {}

    /**
     * @param list<string> $arguments the Psalm arguments, binary excluded
     *
     * @return array{output: string, errorOutput: string, shadows: string} `shadows` is the snapshot
     *                                                                     of the shadow directory
     *                                                                     this run left behind, and
     *                                                                     does not exist when the
     *                                                                     run wrote no shadows
     */
    private function analyzeFixture(string $fixture, array $arguments, int $timeout = 300): array
    {
        $key = $fixture . "\0" . \implode("\0", $arguments);

        if (isset(self::$fixtureRuns[$key])) {
            return self::$fixtureRuns[$key];
        }

        $shadowDir = $this->startFixtureRun($fixture);

        $process = new Process([\PHP_BINARY, $this->psalmBinary(), ...$arguments], $fixture);
        $process->setTimeout($timeout);
        // Not mustRun(): these fixtures report issues on purpose.
        $process->run();

        return self::$fixtureRuns[$key] = [
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
            'shadows' => $this->snapshotShadows($shadowDir, $key),
        ];
    }

    /**
     * Consecutive real runs against ONE fixture, sharing the shadow directory the way repeated runs
     * of a real project do: it is cleared once before the first step and never between steps.
     *
     * Deliberately outside the memo. A case pinning warm-manifest behaviour needs the later
     * subprocess to actually happen against the state its predecessor left behind, which is exactly
     * what serving a memoized report, or wiping the directory for a second config, would destroy.
     *
     * @param non-empty-list<list<string>> $argumentSets the Psalm arguments of each step, in order
     *
     * @return array{output: string, errorOutput: string, shadows: string} the final step's run
     */
    private function analyzeFixtureSequence(string $fixture, array $argumentSets, int $timeout = 300): array
    {
        $shadowDir = $this->startFixtureRun($fixture);
        $process = null;

        foreach ($argumentSets as $arguments) {
            $process = new Process([\PHP_BINARY, $this->psalmBinary(), ...$arguments], $fixture);
            $process->setTimeout($timeout);
            // Not mustRun(): these fixtures report issues on purpose.
            $process->run();
        }

        $this->assertInstanceOf(Process::class, $process, 'a run sequence needs at least one step.');

        $key = $fixture . "\0sequence\0" . \implode("\1", \array_map(
            static fn(array $arguments): string => \implode("\0", $arguments),
            $argumentSets,
        ));

        return [
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
            'shadows' => $this->snapshotShadows($shadowDir, $key),
        ];
    }

    /**
     * Every issue of a `--output-format=json` run, undecoded beyond the outer list so each caller
     * can project the fields it asserts on.
     *
     * @param list<string> $arguments
     *
     * @return list<array<string, mixed>>
     */
    private function fixtureIssues(string $fixture, array $arguments, int $timeout = 300): array
    {
        return $this->decodeIssues($this->analyzeFixture($fixture, $arguments, $timeout));
    }

    /**
     * @param array{output: string, errorOutput: string, shadows: string} $run
     *
     * @return list<array<string, mixed>>
     */
    private function decodeIssues(array $run): array
    {
        $decoded = \json_decode($run['output'], true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$run['output']}\n{$run['errorOutput']}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * The shadow directory a run left behind. It is a snapshot, so a later run of the same fixture
     * cannot empty it under a case that has not asserted yet.
     *
     * @param list<string> $arguments
     */
    private function fixtureShadows(string $fixture, array $arguments, int $timeout = 300): string
    {
        return $this->analyzeFixture($fixture, $arguments, $timeout)['shadows'];
    }

    /**
     * Clears the fixture's shadow directory, which is what a per-test `setUp()` wipe used to do,
     * and hands it to the class teardown.
     *
     * @return string the directory the run is about to write to
     */
    private function startFixtureRun(string $fixture): string
    {
        $shadowDir = $fixture . '/.cache/blade-shadows';

        self::deleteDirectory($shadowDir);
        self::$fixtureScratchDirs[$shadowDir] = true;

        return $shadowDir;
    }

    /** Copies a run's shadow directory aside before any later run can wipe it. */
    private function snapshotShadows(string $shadowDir, string $key): string
    {
        $snapshot = \sys_get_temp_dir() . '/psalm-blade-shadows-' . \getmypid() . '-' . \hash('xxh128', $key);

        self::deleteDirectory($snapshot);
        self::$fixtureScratchDirs[$snapshot] = true;

        if (!\is_dir($shadowDir)) {
            return $snapshot;
        }

        \mkdir($snapshot, 0o777, true);

        foreach (\array_diff(\scandir($shadowDir) ?: [], ['.', '..']) as $entry) {
            \copy($shadowDir . '/' . $entry, $snapshot . '/' . $entry);
        }

        return $snapshot;
    }

    private function psalmBinary(): string
    {
        $binary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($binary, 'Psalm binary not found — run composer install.');

        return $binary;
    }

    private static function deleteDirectory(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        foreach (\array_diff(\scandir($directory) ?: [], ['.', '..']) as $entry) {
            \unlink($directory . '/' . $entry);
        }

        \rmdir($directory);
    }
}
