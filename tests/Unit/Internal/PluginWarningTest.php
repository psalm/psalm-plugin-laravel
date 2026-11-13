<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\PluginWarning;
use Symfony\Component\Process\Process;

/** Subprocesses: only they can observe, or close, the real STDERR. */
#[CoversClass(PluginWarning::class)]
#[Group('subprocess')]
final class PluginWarningTest extends TestCase
{
    #[Test]
    public function a_regular_progress_prints_the_warning_exactly_once(): void
    {
        $process = $this->run_php("PluginWarning::emit(new Psalm\\Progress\\DefaultProgress(), 'only once');");

        $this->assertSame(1, \substr_count($process->getErrorOutput(), 'Warning: only once'), $process->getErrorOutput());
    }

    #[Test]
    public function a_closed_stderr_does_not_abort_the_caller(): void
    {
        $process = $this->run_php("fclose(STDERR); PluginWarning::emit(new Psalm\\Progress\\VoidProgress(), 'lost'); echo 'survived';");

        $this->assertSame('survived', $process->getOutput(), $process->getErrorOutput());
    }

    private function run_php(string $body): Process
    {
        $process = new Process([
            \PHP_BINARY,
            '-r',
            'require $argv[1]; use Psalm\LaravelPlugin\Internal\PluginWarning; ' . $body,
            \dirname(__DIR__, 3) . '/vendor/autoload.php',
        ]);
        $process->mustRun();

        return $process;
    }
}
