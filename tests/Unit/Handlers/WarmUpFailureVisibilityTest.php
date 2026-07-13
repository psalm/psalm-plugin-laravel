<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard that a {@see ModelMetadataRegistryBuilder::warmUp()} failure stays visible under
 * `--no-progress`, a common quiet-CI flag. `Psalm\Progress\VoidProgress` (selected by
 * `--no-progress`) makes `Progress::write()`/`warning()` a total no-op, so before this fix the ONLY
 * diagnostic (`$codebase->progress->warning(...)`) was silently swallowed and a warm-up failure
 * looked identical to a clean run.
 *
 * `Symfony\Component\Process` is used (not an in-process call) because the fix writes directly to
 * the `STDERR` resource constant, which bypasses PHP's output-buffering functions (`ob_start()`
 * does not capture it — verified manually) and so cannot be observed from within the same process;
 * a real subprocess's stderr pipe, unlike an in-process buffer, is unaffected by how the child wrote
 * to it. Like {@see UnresolvableAppendedModelAttributeEmissionTest}, this forks a real `vendor/bin/psalm`
 * (~4s) and lives in tests/Unit for proximity to the code it guards.
 *
 * The fixture also incidentally guards a second, more severe bug this test uncovered: before
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler::resolveTableName()} widened its
 * catch from `\ReflectionException` to `\Throwable`, the same crashing `getTable()` override
 * propagated UNCAUGHT out of `ModelRegistrationHandler::registerWriteTypesForColumns()` (a separate
 * call path from `warmUp()`, outside its try/catch) and crashed the entire Psalm run rather than
 * just dropping the one model — asserted here via the process exit code.
 */
#[CoversClass(ModelMetadataRegistryBuilder::class)]
final class WarmUpFailureVisibilityTest extends TestCase
{
    #[Test]
    public function a_warm_up_failure_reaches_stderr_under_no_progress_and_does_not_crash_the_run(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UnknownModelAttribute';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues (expected: the fixture has several unrelated
        // ones), so do not mustRun() — only an UNCAUGHT crash (exit 255, or the "crashed due to an
        // uncaught Throwable" banner) would indicate the regression this test guards against.
        $process->run();

        $stderr = $process->getErrorOutput();

        $this->assertStringContainsString(
            "Laravel plugin: ModelMetadataRegistry schema failed for 'KnownAttributeFixture\\Models\\WarmUpCrashModel'",
            $stderr,
            "The warm-up failure warning must reach stderr even under --no-progress.\nFull stderr:\n{$stderr}",
        );
        $this->assertStringNotContainsString(
            'crashed due to an uncaught Throwable',
            $stderr,
            "A single model's warm-up failure must not crash the entire Psalm run.\nFull stderr:\n{$stderr}",
        );
    }
}
