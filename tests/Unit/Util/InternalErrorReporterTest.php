<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Internal\InternalErrorReporter;
use Psalm\LaravelPlugin\Internal\PluginWarning;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\CollectingProgress;

#[CoversClass(InternalErrorReporter::class)]
#[CoversClass(PluginWarning::class)]
final class InternalErrorReporterTest extends TestCase
{
    #[Test]
    public function degraded_boot_warns_with_error_message_and_diagnose_pointer(): void
    {
        $progress = new CollectingProgress();

        InternalErrorReporter::reportDegradedBoot(
            new \RuntimeException('parse_url(): Argument #1 ($url) must be of type string, null given'),
            $progress,
            PluginConfig::fromXml(null),
        );

        $this->assertNotEmpty($progress->warnings);
        $this->assertStringContainsString(
            'application bootstrap failed partway: parse_url()',
            $progress->warnings[0],
        );

        $last = $progress->warnings[\count($progress->warnings) - 1];
        $this->assertStringContainsString('degraded mode', $last);
        $this->assertStringContainsString('psalm-laravel diagnose', $last);
    }

    #[Test]
    public function degraded_boot_rethrows_and_stays_silent_when_fail_on_internal_error_is_on(): void
    {
        $progress = new CollectingProgress();
        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><failOnInternalError value="true" /></pluginClass>'),
        );
        $bootstrapError = new \RuntimeException('boom');

        try {
            InternalErrorReporter::reportDegradedBoot($bootstrapError, $progress, $config);
            $this->fail('Expected the swallowed bootstrap error to be rethrown');
        } catch (\RuntimeException $runtimeException) {
            // Same instance escalates so Plugin::__invoke()'s catch reports it once,
            // with the original trace intact.
            $this->assertSame($bootstrapError, $runtimeException);
        }

        $this->assertSame([], $progress->warnings, 'Escalation path must not double-report warnings');
    }

    /**
     * #1636: `--no-progress` installs VoidProgress, which drops every warning, so the disabled and
     * degraded notices must also reach STDERR; only a subprocess can observe that write.
     */
    #[Test]
    #[Group('subprocess')]
    public function disabled_and_degraded_warnings_reach_stderr_under_void_progress(): void
    {
        $script = <<<'PHP'
            require $argv[1];
            $output = new Psalm\Progress\VoidProgress();
            $config = Psalm\LaravelPlugin\Config\PluginConfig::fromXml(null);
            Psalm\LaravelPlugin\Internal\InternalErrorReporter::report(new RuntimeException('init boom'), $output, $config);
            Psalm\LaravelPlugin\Internal\InternalErrorReporter::reportDegradedBoot(new RuntimeException('boot boom'), $output, $config);
            PHP;

        $process = new Process([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 3) . '/vendor/autoload.php']);
        $process->mustRun();

        $stderr = $process->getErrorOutput();
        // stdout carries machine-readable reports (--output-format=json), so it must stay clean.
        $this->assertSame('', $process->getOutput());
        $this->assertSame(1, \substr_count($stderr, 'Warning: Laravel plugin error on initialisation: init boom'), $stderr);
        $this->assertStringContainsString('Warning: Laravel plugin has been disabled for this run', $stderr);
        $this->assertStringContainsString('Warning: Laravel plugin: application bootstrap failed partway: boot boom', $stderr);
        $this->assertStringContainsString('Warning: Laravel plugin is running in degraded mode', $stderr);
    }
}
