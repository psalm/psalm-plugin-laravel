<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\RuntimeHelperVisibility;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Symfony\Component\Process\Process;

/**
 * Pins issue #1551: a helper function a service provider `include`s at boot is invisible to Blade
 * shadows, because Psalm resolves a bare function call through the ROOT file's
 * `FileStorage::$declaring_function_ids` and a shadow references nothing that reaches the helper
 * file. Every affected symbol reported the same `UndefinedFunction … consider enabling
 * allFunctionsGlobal`, and `define()`d constants in the same files reported `UndefinedConstant`.
 *
 * Needs a real `vendor/bin/psalm` subprocess: the fix runs off a booted Laravel app and a populated
 * codebase, neither of which a unit test can assemble.
 */
#[CoversClass(RuntimeHelperVisibility::class)]
final class BladeRuntimeHelperVisibilityTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/BladeRuntimeHelpers';

    private const SHADOW_DIR = self::FIXTURE . '/.cache/blade-shadows';

    /** Forking only happens when there are more files to analyze than workers. */
    private const THREADS = 4;

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

    /**
     * One real Psalm run. The report goes to a file rather than stdout so `--debug` can occupy
     * stdout: its "Forking analysis" line is the only proof a multi-threaded cell actually forked
     * (see {@see assertForked()}), and an explicit `--threads` keeps debug mode from silently
     * dropping to a single process.
     *
     * @return array{output: string, issues: list<array{type: string, file: string, message: string}>}
     */
    private function analyze(int $threads = 1): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $report = \sys_get_temp_dir() . '/blade-runtime-helpers-' . \bin2hex(\random_bytes(8)) . '.json';

        $process = new Process(
            [
                \PHP_BINARY,
                $psalmBinary,
                '-c',
                'psalm.xml',
                '--no-cache',
                '--threads=' . $threads,
                '--scan-threads=' . $threads,
                '--debug',
                '--report=' . $report,
            ],
            self::FIXTURE,
        );
        $process->setTimeout(600);

        try {
            // Not mustRun(): the fixture reports issues on purpose.
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

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = [
                'type' => (string) $issue['type'],
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return ['output' => $output, 'issues' => $issues];
    }

    /**
     * Psalm forks only when there are MORE files to analyze than workers
     * (`Internal/Codebase/Analyzer::doAnalysis()`), so a small fixture silently runs single-process
     * and a thread-parity assertion over it proves nothing. The fixture carries padding files for
     * exactly this reason; this guard is what stops a later trim from re-vacuuming the test.
     */
    private function assertForked(string $output): void
    {
        $this->assertStringContainsString(
            'Forking analysis',
            $output,
            'The multi-threaded run never forked, so it proves nothing about workers.',
        );
    }

    /**
     * Silence on a template proves nothing if the template never compiled, so pin that the shadow
     * pipeline actually reached the analyzer: the manifest records every template `compileAll()`
     * produced a shadow for, and the negative control below reports through the remap.
     */
    private function assertTemplateAnalyzed(): void
    {
        $manifest = self::SHADOW_DIR . '/manifest.php';
        $this->assertFileExists($manifest);
        $templatePath = \realpath(self::FIXTURE . '/resources/views/page.blade.php');
        $this->assertIsString($templatePath, 'page.blade.php does not exist on disk.');
        $this->assertStringContainsString($templatePath, (string) \file_get_contents($manifest));
    }

    /**
     * @param list<array{type: string, file: string, message: string}> $issues
     *
     * @return list<array{type: string, file: string, message: string}>
     */
    private function matching(array $issues, string $file, string $type, string $needle = ''): array
    {
        return \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === $file
                && $issue['type'] === $type
                && ($needle === '' || \str_contains($issue['message'], $needle)),
        ));
    }

    #[Test]
    public function a_helper_the_booted_app_defined_resolves_inside_a_template(): void
    {
        ['issues' => $issues] = $this->analyze();
        $this->assertTemplateAnalyzed();

        $this->assertSame(
            [],
            $this->matching($issues, 'page.blade.php', 'UndefinedFunction', 'demo_helper'),
            \var_export($issues, true),
        );
    }

    #[Test]
    public function a_constant_the_booted_app_defined_resolves_inside_a_template(): void
    {
        ['issues' => $issues] = $this->analyze();
        $this->assertTemplateAnalyzed();

        $this->assertSame(
            [],
            $this->matching($issues, 'page.blade.php', 'UndefinedConstant', 'DEMO_CONST'),
            \var_export($issues, true),
        );
    }

    /**
     * Existence alone would be cheap to fake. `TooManyArguments` can only come from
     * `Functions::getStorage()` walking the injected map to the helper's real `FunctionStorage`,
     * so it proves params and return types resolve too, and that the injected map never trips the
     * `UnexpectedValueException` that method throws on a map it cannot follow.
     */
    #[Test]
    public function the_helpers_real_signature_is_enforced_inside_a_template(): void
    {
        ['issues' => $issues] = $this->analyze();
        $this->assertTemplateAnalyzed();

        $this->assertCount(
            1,
            $this->matching($issues, 'page.blade.php', 'TooManyArguments', 'demo_helper'),
            \var_export($issues, true),
        );
    }

    #[Test]
    public function a_function_nothing_ever_defined_is_still_reported_in_a_template(): void
    {
        ['issues' => $issues] = $this->analyze();
        $this->assertTemplateAnalyzed();

        $this->assertCount(
            1,
            $this->matching($issues, 'page.blade.php', 'UndefinedFunction', 'definitely_not_defined'),
            \var_export($issues, true),
        );
    }

    /**
     * The blast radius is shadows only. An ordinary project file calling the same helper keeps
     * Psalm's own answer; widening the injection to project files would suppress genuine
     * `UndefinedFunction` across the whole codebase.
     */
    #[Test]
    public function an_ordinary_project_file_calling_the_helper_still_reports(): void
    {
        ['issues' => $issues] = $this->analyze();

        $this->assertCount(
            1,
            $this->matching($issues, 'PlainCaller.php', 'UndefinedFunction', 'demo_helper'),
            \var_export($issues, true),
        );
    }

    /**
     * The injection reaches forked workers by copy-on-write, since the hook runs in the parent
     * before `analyzeFiles()` forks. Re-runs the same fixture multi-threaded to pin that.
     */
    #[Test]
    public function the_injection_survives_forked_analysis_workers(): void
    {
        // Psalm silently clamps --threads to 1 on Windows or without pcntl, so the fork proof
        // would fail there instead of proving anything.
        if (\defined('PHP_WINDOWS_VERSION_MAJOR') || !\extension_loaded('pcntl')) {
            self::markTestSkipped('Psalm cannot fork analysis workers in this environment.');
        }

        ['issues' => $issues, 'output' => $output] = $this->analyze(self::THREADS);

        $this->assertForked($output);
        $this->assertTemplateAnalyzed();

        $this->assertSame(
            [],
            $this->matching($issues, 'page.blade.php', 'UndefinedFunction', 'demo_helper'),
            \var_export($issues, true),
        );
    }

    /** Static state carrying per-run facts must not survive into the next plugin invocation. */
    #[Test]
    public function the_captured_helper_files_are_cleared_on_reset(): void
    {
        ApplicationProvider::reset();

        $this->assertSame([], ApplicationProvider::runtimeDeclaredFunctionFiles());
    }

    /**
     * The handler's own static carries the same per-run facts and needs the same clearing. Read
     * reflectively because the only public reader is the hook itself, and driving that would mean
     * assembling a populated `Codebase`; asserting the filled state first is what keeps the
     * cleared assertion from passing vacuously.
     */
    #[Test]
    public function the_handlers_helper_files_are_cleared_on_reset(): void
    {
        $helperFiles = new \ReflectionProperty(RuntimeHelperVisibility::class, 'helperFiles');

        RuntimeHelperVisibility::init(['/tmp/does-not-need-to-exist.php']);
        $this->assertSame(['/tmp/does-not-need-to-exist.php'], $helperFiles->getValue());

        RuntimeHelperVisibility::reset();
        $this->assertSame([], $helperFiles->getValue());
    }
}
