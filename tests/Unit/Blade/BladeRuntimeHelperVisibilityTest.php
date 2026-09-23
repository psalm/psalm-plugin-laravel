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
     * @return list<array{type: string, file: string, message: string}>
     */
    private function analyze(string $threads = '1'): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', "--threads={$threads}", '--no-progress', '--output-format=json'],
            self::FIXTURE,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $issues[] = [
                'type' => (string) $issue['type'],
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
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
        $issues = $this->analyze();
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
        $issues = $this->analyze();
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
        $issues = $this->analyze();
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
        $issues = $this->analyze();
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
        $issues = $this->analyze();

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
        $issues = $this->analyze('4');
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
}
