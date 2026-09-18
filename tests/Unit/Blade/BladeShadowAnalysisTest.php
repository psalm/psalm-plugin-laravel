<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Psalm\LaravelPlugin\Blade\PsalmShadowRegistrar;
use Psalm\LaravelPlugin\Plugin;
use Symfony\Component\Process\Process;

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
    private const FIXTURE = __DIR__ . '/Fixtures/BladeShadowAnalysis';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

    protected function setUp(): void
    {
        $this->deleteShadowDir();
    }

    protected function tearDown(): void
    {
        $this->deleteShadowDir();
    }

    private function deleteShadowDir(): void
    {
        if (!\is_dir(self::SHADOW_DIR)) {
            return;
        }

        foreach (\array_diff(\scandir(self::SHADOW_DIR) ?: [], ['.', '..']) as $entry) {
            \unlink(self::SHADOW_DIR . '/' . $entry);
        }

        \rmdir(self::SHADOW_DIR);
    }

    private function runPsalm(string $config): string
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=1', '--debug'],
            self::FIXTURE,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues, and the shadow adds more.
        $process->run();

        return $process->getOutput() . $process->getErrorOutput();
    }

    /** @return list<string> */
    private function shadowFiles(): array
    {
        return \array_values(\array_filter(
            \glob(self::SHADOW_DIR . '/*.php') ?: [],
            static fn(string $path): bool => \basename($path) !== 'manifest.php',
        ));
    }

    #[Test]
    public function an_enabled_run_compiles_the_template_and_analyzes_its_shadow(): void
    {
        $output = $this->runPsalm('psalm.xml');

        $this->assertStringNotContainsString('Blade template analysis is disabled', $output, $output);
        $this->assertFileExists(self::SHADOW_DIR . '/manifest.php');

        $shadows = $this->shadowFiles();
        $this->assertCount(1, $shadows, "Expected exactly one shadow file.\n{$output}");
        $this->assertStringContainsString('echo e($name)', (string) \file_get_contents($shadows[0]));

        // Psalm's --debug names every file it analyzes; the shadow appearing there is the proof
        // that addFilesToAnalyze() took effect from inside initializePlugins().
        $this->assertStringContainsString(
            'Analyzing ' . $shadows[0],
            $output,
            "The shadow was written but never analyzed.\n{$output}",
        );
    }

    #[Test]
    public function the_blade_template_becomes_reportable_while_the_shadow_does_not(): void
    {
        $this->runPsalm('psalm.xml');

        $manifest = self::SHADOW_DIR . '/manifest.php';
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

        $this->assertDirectoryDoesNotExist(self::SHADOW_DIR, $output);
        $this->assertStringNotContainsString('.blade.php', $output, $output);
    }
}
