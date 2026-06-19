<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Plugin;
use Psalm\LaravelPlugin\Util\BootstrapDegradationReporter;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for #1096: a Laravel `bootstrap()` failure that {@see \Psalm\LaravelPlugin\Providers\ApplicationProvider}
 * swallows to stay crash-resistant must not leave the plugin silently inert.
 *
 * Why a real Psalm subprocess instead of a phpt or an in-process unit test: the degraded
 * state lives in {@see \Psalm\LaravelPlugin\Providers\ApplicationProvider}'s process-global
 * boot, and the signal is a `Progress::warning()` written to STDERR — neither is observable
 * from psalm-tester (which captures issue output) nor from an in-process call without a boot
 * seam. This points a real `vendor/bin/psalm` at a fixture whose `config/bad.php` fatals
 * during `LoadConfiguration`, the way the UNIT3D repro in the issue does.
 *
 * Progress note: the run deliberately omits `--no-progress`. Psalm swaps in `VoidProgress`
 * for `--no-progress`, and `VoidProgress::write()` is a no-op that would swallow the warning;
 * the default progress renderer writes warnings to STDERR, which is the "normal run" the
 * acceptance criterion targets.
 *
 * Placement note: like {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\SuppressScopeUnusedCodeTest},
 * this forks a real psalm subprocess (it boots Laravel via the plugin, a few seconds) because
 * the behaviour cannot be observed in-process.
 */
#[CoversClass(Plugin::class)]
#[CoversClass(BootstrapDegradationReporter::class)]
final class PluginBootstrapDegradationTest extends TestCase
{
    #[Test]
    public function it_surfaces_a_degraded_boot_warning_from_a_normal_run(): void
    {
        $process = $this->runPsalmOnFixture('psalm.xml');

        $stderr = $process->getErrorOutput();
        $combined = $process->getOutput() . "\n" . $stderr;

        // Acceptance #1: the degraded state is visible without running `diagnose`.
        $this->assertStringContainsString('DEGRADED', $stderr, "Degraded-boot warning missing from STDERR.\n{$combined}");
        $this->assertStringContainsString('Division by zero', $stderr, 'The originating cause should be named.');

        // Acceptance #3: crash-resistance preserved. A tolerated boot failure does NOT abort
        // the run via the plugin-invoke boundary (that is the failOnInternalError path, below).
        $this->assertStringNotContainsString('Failed to invoke plugin', $combined, "A degraded boot must not hard-fail plugin invocation when failOnInternalError is off.\n{$combined}");

        // The degraded path must stay DEGRADED, not collapse into "disabled". This guards the
        // buildSchema() skip in Plugin::__invoke: without it, building the migration schema on the
        // partial app resolves the absent `migrator` binding, throws, and routes through
        // InternalErrorReporter ("has been disabled for this run") instead, burying the signal.
        $this->assertStringNotContainsString('has been disabled for this run', $combined, "Degraded boot must not collapse into the disabled path.\n{$combined}");
    }

    #[Test]
    public function it_stops_the_run_when_fail_on_internal_error_is_enabled(): void
    {
        $process = $this->runPsalmOnFixture('psalm-fail.xml');

        $combined = $process->getOutput() . "\n" . $process->getErrorOutput();

        // Acceptance #2: with failOnInternalError, the swallowed bootstrap failure is reportable —
        // it propagates out of the plugin and Psalm stops the run (non-zero exit) instead of
        // analysing on a half-booted app.
        $this->assertNotSame(0, $process->getExitCode(), "Psalm should have stopped with a non-zero exit.\n{$combined}");
        $this->assertStringContainsString('Failed to invoke plugin', $combined, "The run should stop at plugin invocation with the bootstrap failure surfaced.\n{$combined}");

        // "Reportable" means the originating cause is preserved, not a generic wrapper. Psalm
        // prints "Failed to invoke plugin" for any plugin throwable, so assert the real cause too,
        // guarding that __invoke rethrows the actual bootstrap error rather than something generic.
        $this->assertStringContainsString('Division by zero', $combined, "The originating bootstrap cause should be surfaced.\n{$combined}");
    }

    private function runPsalmOnFixture(string $configFile): Process
    {
        $projectRoot = \dirname(__DIR__, 2);
        $fixtureDir = __DIR__ . '/Fixtures/DegradedBootstrap';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        // No --no-progress on purpose: it would select VoidProgress and swallow the warning.
        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $configFile, '--no-cache', '--threads=1'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues or stops on a config error; both are
        // expected across these cases, so do not mustRun().
        $process->run();

        return $process;
    }
}
