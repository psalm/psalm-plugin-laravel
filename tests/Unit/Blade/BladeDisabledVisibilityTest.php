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

    #[Test]
    public function the_disable_message_reaches_stderr_under_no_progress_and_fails_the_run_when_asked(): void
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', '-c', 'psalm-fail.xml', '--no-cache', '--threads=1', '--no-progress'],
            $this->fixtureCopy(),
        );
        $process->setTimeout(300);
        $process->run();

        $stderr = $process->getErrorOutput();

        $this->assertStringContainsString(self::MESSAGE, $stderr);
        // `<failOnInternalError>` is what makes a disabled Blade a failed run rather than a notice.
        $this->assertNotSame(0, $process->getExitCode(), $stderr);
    }
}
