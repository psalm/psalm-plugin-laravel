<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Internal\InternalErrorReporter;
use Psalm\Progress\Progress;

#[CoversClass(InternalErrorReporter::class)]
final class InternalErrorReporterTest extends TestCase
{
    #[Test]
    public function degraded_boot_warns_with_error_message_and_diagnose_pointer(): void
    {
        $progress = $this->collectingProgress();

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
    public function degraded_boot_includes_classifier_hint_when_available(): void
    {
        $progress = $this->collectingProgress();

        // Thrown from this test file — classified as user application code
        // (not vendor/, not the plugin's src/), so a hint line is emitted
        // between the error message and the degraded-mode notice.
        InternalErrorReporter::reportDegradedBoot(
            new \RuntimeException('boom'),
            $progress,
            PluginConfig::fromXml(null),
        );

        $this->assertCount(3, $progress->warnings);
        $this->assertStringContainsString('originated inside your application code', $progress->warnings[1]);
    }

    #[Test]
    public function degraded_boot_rethrows_and_stays_silent_when_fail_on_internal_error_is_on(): void
    {
        $progress = $this->collectingProgress();
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
     * @return Progress&object{warnings: list<string>}
     */
    private function collectingProgress(): Progress
    {
        // Psalm 6's Progress is fully concrete (no abstract members), so only the one
        // method under test needs overriding. Psalm 7 makes six of them abstract.
        return new class extends Progress {
            /** @var list<string> */
            public array $warnings = [];

            #[\Override]
            public function warning(string $message): void
            {
                $this->warnings[] = $message;
            }
        };
    }
}
