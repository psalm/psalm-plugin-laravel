<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Util\Diagnostics\BufferedProgress;
use Psalm\LaravelPlugin\Util\InternalErrorReporter;
use Psalm\Progress\Progress;

#[CoversClass(InternalErrorReporter::class)]
final class InternalErrorReporterTest extends TestCase
{
    /** @var list<array{string, string}> recorded (channel, message) pairs in emission order */
    private array $events = [];

    #[Test]
    public function it_flushes_collected_diagnostics_before_the_error_report(): void
    {
        $buffered = new BufferedProgress($this->recordingInner());
        $buffered->stage('boot');
        $buffered->warning('Laravel plugin: collected boot notice');

        InternalErrorReporter::report(
            new \RuntimeException('kaboom'),
            $buffered,
            PluginConfig::fromXml(null),
        );

        // The collected notice is flushed as a single grouped block via write(), and
        // it comes first — before the terminal error lines.
        $this->assertSame('write', $this->events[0][0]);
        $this->assertStringContainsString('[warning/boot] Laravel plugin: collected boot notice', $this->events[0][1]);

        $errorLine = $this->firstWarningContaining('Laravel plugin error on initialisation: kaboom');
        $this->assertNotNull($errorLine, 'the original error must be surfaced as a terminal warning');

        $blockIndex = 0;
        $errorIndex = $this->indexOfWarningContaining('Laravel plugin error on initialisation: kaboom');
        $this->assertGreaterThan($blockIndex, $errorIndex, 'diagnostics block must precede the error report');
    }

    #[Test]
    public function terminal_messages_reach_the_real_progress_and_are_not_re_buffered(): void
    {
        $buffered = new BufferedProgress($this->recordingInner());
        $buffered->stage('boot');
        $buffered->warning('Laravel plugin: collected boot notice');

        InternalErrorReporter::report(
            new \RuntimeException('kaboom'),
            $buffered,
            PluginConfig::fromXml(null),
        );

        // Every terminal line is delivered as a warning on the wrapped progress.
        $this->assertNotNull($this->firstWarningContaining('has been disabled for this run'));

        // The collected notice must appear exactly once (the flushed block), never
        // re-collected and re-emitted as a terminal warning after the buffer cleared.
        $notices = \array_filter(
            $this->events,
            static fn(array $event): bool => \str_contains($event[1], 'collected boot notice'),
        );
        $this->assertCount(1, $notices);
        $this->assertSame('write', \array_values($notices)[0][0]);
    }

    #[Test]
    public function it_rethrows_when_fail_on_internal_error_is_enabled(): void
    {
        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginConfig><failOnInternalError value="true"/></pluginConfig>'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kaboom');

        InternalErrorReporter::report(new \RuntimeException('kaboom'), new BufferedProgress($this->recordingInner()), $config);
    }

    private function firstWarningContaining(string $needle): ?string
    {
        foreach ($this->events as [$channel, $message]) {
            if ($channel === 'warning' && \str_contains($message, $needle)) {
                return $message;
            }
        }

        return null;
    }

    private function indexOfWarningContaining(string $needle): int
    {
        foreach ($this->events as $index => [$channel, $message]) {
            if ($channel === 'warning' && \str_contains($message, $needle)) {
                return $index;
            }
        }

        return -1;
    }

    /** Records write() and warning() on the wrapped progress, preserving order. */
    private function recordingInner(): Progress
    {
        $inner = $this->createStub(Progress::class);
        $inner->method('write')->willReturnCallback(
            function (string $message): void {
                $this->events[] = ['write', $message];
            },
        );
        $inner->method('warning')->willReturnCallback(
            function (string $message): void {
                $this->events[] = ['warning', $message];
            },
        );

        return $inner;
    }
}
