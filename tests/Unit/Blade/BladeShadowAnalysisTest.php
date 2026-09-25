<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\PsalmShadowRegistrar;
use Psalm\LaravelPlugin\Plugin;

/**
 * End-to-end proof that a real `vendor/bin/psalm` run compiles the fixture's Blade template and
 * actually analyzes the shadow. It needs a real run because the two load-bearing facts (the write
 * into `ProjectAnalyzer`'s private project-file list, and a shadow reaching the analyzer from
 * inside `Config::initializePlugins()`) only exist against live Psalm internals.
 *
 * What it cannot pin: shadow issues are invisible by design until the issue-remap handler exists,
 * so this asserts on Psalm's own `--debug` accounting of analyzed files, not on reported issues.
 */
#[CoversClass(Plugin::class)]
#[CoversClass(BladeBootstrapper::class)]
#[CoversClass(PsalmShadowRegistrar::class)]
final class BladeShadowAnalysisTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeShadowAnalysis';

    /** Where the run itself wrote, which is the path `--debug` names. */
    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

    /**
     * The command a case's `$config` resolves to. Cases naming the same config share one run.
     *
     * @return list<string>
     */
    private function arguments(string $config): array
    {
        return ['-c', $config, '--no-cache', '--threads=1', '--debug'];
    }

    private function runPsalm(string $config): string
    {
        $run = $this->analyzeFixture(self::FIXTURE, $this->arguments($config));

        return $run['output'] . $run['errorOutput'];
    }

    /** @return list<string> */
    private function shadowFiles(string $config): array
    {
        return \array_values(\array_filter(
            \glob($this->fixtureShadows(self::FIXTURE, $this->arguments($config)) . '/*.php') ?: [],
            static fn(string $path): bool => \basename($path) !== 'manifest.php',
        ));
    }

    #[Test]
    public function an_enabled_run_compiles_the_template_and_analyzes_its_shadow(): void
    {
        $output = $this->runPsalm('psalm.xml');

        $this->assertStringNotContainsString('Blade template analysis is disabled', $output, $output);
        $this->assertFileExists($this->fixtureShadows(self::FIXTURE, $this->arguments('psalm.xml')) . '/manifest.php');

        $shadows = $this->shadowFiles('psalm.xml');
        $this->assertCount(1, $shadows, "Expected exactly one shadow file.\n{$output}");
        $this->assertStringContainsString('echo e($name)', (string) \file_get_contents($shadows[0]));

        // Psalm's --debug names every file it analyzes; the shadow appearing there is the proof
        // that addFilesToAnalyze() took effect from inside initializePlugins(). The debug line
        // names where the run wrote, not the snapshot the assertions above read.
        $this->assertStringContainsString(
            'Analyzing ' . self::SHADOW_DIR . '/' . \basename($shadows[0]),
            $output,
            "The shadow was written but never analyzed.\n{$output}",
        );
    }

    #[Test]
    public function the_blade_template_becomes_reportable_while_the_shadow_does_not(): void
    {
        $this->runPsalm('psalm.xml');

        $manifest = $this->fixtureShadows(self::FIXTURE, $this->arguments('psalm.xml')) . '/manifest.php';
        $this->assertFileExists($manifest);

        /** @var array<string, array{0: string, 1: array<int, int>, 2: ?int, 3: string}> $entries */
        $entries = include $manifest;

        $this->assertCount(1, $entries);
        $entry = \reset($entries);
        $this->assertIsArray($entry);
        $this->assertSame(
            (string) \realpath(self::FIXTURE . '/resources/views/profile.blade.php'),
            $entry[0],
            'the manifest keys the shadow to the real template path, which is what gets reported on',
        );
        $this->assertNotSame([], $entry[1], 'a line map back to the template is recorded');
    }

    #[Test]
    public function a_disabled_run_writes_nothing_and_analyzes_no_shadow(): void
    {
        $output = $this->runPsalm('psalm-blade-disabled.xml');

        $this->assertDirectoryDoesNotExist(
            $this->fixtureShadows(self::FIXTURE, $this->arguments('psalm-blade-disabled.xml')),
            $output,
        );
        $this->assertStringNotContainsString('.blade.php', $output, $output);
    }
}
