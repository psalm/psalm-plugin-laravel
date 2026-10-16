<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeBootstrapper;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * #1930: Blade is opt-in, so a run that cannot start it has lost something the user asked for.
 * `--no-progress` installs `VoidProgress`, which drops every warning, so the disable message goes
 * to STDERR directly; only a real subprocess can observe that write.
 */
#[CoversClass(BladeBootstrapper::class)]
#[Group('subprocess')]
final class BladeDisabledVisibilityTest extends TestCase
{
    use CopiesFixtureDirectories;

    private const FIXTURE = __DIR__ . '/Fixtures/BladeDisabled';

    private const MESSAGE = "Blade template analysis is disabled for this run, because the 'blade.compiler' service is not bound";

    private ?string $copy = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->copy !== null) {
            $this->removeDirectory($this->copy);
        }
    }

    /** A private copy: the booted app writes `storage/` and `bootstrap/cache/` into its base path. */
    private function fixtureCopy(): string
    {
        $this->copy = self::FIXTURE . '/.copy-' . (int) \getmypid() . '-' . \bin2hex(\random_bytes(4));
        $this->copyDirectory(self::FIXTURE, $this->copy);

        return $this->copy;
    }

    /**
     * @param list<string> $flags
     *
     * @return array{0: int, 1: string} exit code, stderr
     */
    private function analyze(string $directory, string $config, array $flags = ['--no-progress']): array
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', '-c', $config, '--no-cache', '--threads=1', ...$flags],
            $directory,
        );
        $process->setTimeout(300);
        $process->run();

        return [(int) $process->getExitCode(), $process->getErrorOutput()];
    }

    #[Test]
    public function the_disable_message_reaches_stderr_under_no_progress(): void
    {
        [$exitCode, $stderr] = $this->analyze($this->fixtureCopy(), 'psalm.xml');

        $this->assertStringContainsString(self::MESSAGE, $stderr);
        $this->assertSame(0, $exitCode, $stderr);
    }

    /** Pins the VoidProgress guard: a real progress already writes the warning to STDERR. */
    #[Test]
    public function the_disable_message_appears_once_under_normal_progress(): void
    {
        // --long-progress: without it, Psalm picks VoidProgress for a non-interactive or agent-driven run.
        [, $stderr] = $this->analyze($this->fixtureCopy(), 'psalm.xml', ['--long-progress']);

        $this->assertSame(1, \substr_count($stderr, self::MESSAGE), $stderr);
    }

    /**
     * A write to a closed STDERR raises a notice Psalm turns into an exception; unguarded, it skipped
     * registering the whole plugin, so `app('config')` lost its type and reported `MixedMethodCall`.
     */
    #[Test]
    public function a_closed_stderr_keeps_the_plugin_registered(): void
    {
        $copy = $this->fixtureCopy();
        \file_put_contents(
            $copy . '/app/ConfigReader.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace BladeDisabledFixture;\n\nfunction configValue(): mixed\n{\n    return app('config')->get('app.name');\n}\n",
        );

        $process = new Process(
            ['bash', '-c', 'exec 2>&-; exec "$0" "$@"', \PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            $copy,
        );
        $process->setTimeout(300);
        $process->run();

        $this->assertSame([], \json_decode($process->getOutput(), true), $process->getOutput());
    }

    #[Test]
    public function fail_on_internal_error_turns_the_disable_into_a_failed_run(): void
    {
        [$exitCode, $stderr] = $this->analyze($this->fixtureCopy(), 'psalm-fail.xml');

        $this->assertNotSame(0, $exitCode, $stderr);
        $this->assertStringContainsString(self::MESSAGE, $stderr);
    }

    /** Finding no templates is not a disable, so failOnInternalError must not fire on it. */
    #[Test]
    public function an_empty_view_tree_does_not_fail_the_run_under_fail_on_internal_error(): void
    {
        $copy = $this->fixtureCopy();
        \unlink($copy . '/resources/views/profile.blade.php');
        // Keeps blade.compiler bound.
        \file_put_contents(
            $copy . '/bootstrap/app.php',
            "<?php\n\nreturn Illuminate\\Foundation\\Application::configure(basePath: \\dirname(__DIR__))->create();\n",
        );

        [$exitCode, $stderr] = $this->analyze($copy, 'psalm-fail.xml');

        $this->assertSame(0, $exitCode, $stderr);
    }
}
