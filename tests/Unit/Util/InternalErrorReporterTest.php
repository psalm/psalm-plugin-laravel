<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Diagnostics\DiagnosticsBuffer;
use Psalm\LaravelPlugin\Util\InternalErrorReporter;
use Psalm\Progress\Progress;

#[CoversClass(InternalErrorReporter::class)]
final class InternalErrorReporterTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->warnings = [];
    }

    #[Test]
    public function it_replays_collected_diagnostics_ahead_of_the_error_report(): void
    {
        $diagnostics = new DiagnosticsBuffer();
        $diagnostics->warning('boot', 'Laravel boot completed only partially');

        InternalErrorReporter::report(
            new \RuntimeException('something broke during init'),
            $this->recordingProgress(),
            PluginConfig::fromXml(null),
            $diagnostics,
        );

        // The buffered diagnostic must precede the "error on initialisation" line, so the
        // warning that explains the failure travels with the report rather than being lost.
        $diagnosticIndex = $this->indexOfWarningContaining('[boot] Laravel boot completed only partially');
        $reportIndex = $this->indexOfWarningContaining('Laravel plugin error on initialisation');

        $this->assertGreaterThanOrEqual(0, $diagnosticIndex, 'the buffered diagnostic was not replayed');
        $this->assertGreaterThanOrEqual(0, $reportIndex, 'the error report line is missing');
        $this->assertLessThan($reportIndex, $diagnosticIndex, 'diagnostics must precede the error report');
    }

    #[Test]
    public function it_does_not_rethrow_when_fail_on_internal_error_is_off(): void
    {
        // fromXml(null) leaves failOnInternalError at its default (false), so report() must
        // surface the warnings and return rather than rethrow.
        $this->expectNotToPerformAssertions();

        InternalErrorReporter::report(
            new \RuntimeException('boom'),
            $this->recordingProgress(),
            PluginConfig::fromXml(null),
            new DiagnosticsBuffer(),
        );
    }

    #[Test]
    public function it_still_replays_diagnostics_when_it_rethrows_on_fail_on_internal_error(): void
    {
        $diagnostics = new DiagnosticsBuffer();
        $diagnostics->warning('boot', 'Laravel boot completed only partially');

        $config = PluginConfig::fromXml(
            new \SimpleXMLElement('<pluginClass><failOnInternalError value="true" /></pluginClass>'),
        );

        $thrown = null;

        try {
            InternalErrorReporter::report(
                new \RuntimeException('something broke during init'),
                $this->recordingProgress(),
                $config,
                $diagnostics,
            );
        } catch (\Throwable $caught) {
            $thrown = $caught;
        }

        // The rethrow must not skip the flush: failOnInternalError is the CI path, exactly
        // where the buffered warning explaining the failure matters most.
        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'report() must rethrow when the flag is on');
        $this->assertSame('something broke during init', $thrown->getMessage());
        $this->assertGreaterThanOrEqual(
            0,
            $this->indexOfWarningContaining('[boot] Laravel boot completed only partially'),
            'diagnostics must still be flushed before the rethrow',
        );
    }

    private function indexOfWarningContaining(string $needle): int
    {
        foreach ($this->warnings as $index => $message) {
            if (\str_contains($message, $needle)) {
                return $index;
            }
        }

        return -1;
    }

    /** Stubbed Progress that records emitted warnings, in order, into {@see self::$warnings}. */
    private function recordingProgress(): Progress
    {
        $progress = $this->createStub(Progress::class);
        $progress->method('warning')->willReturnCallback(
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );

        return $progress;
    }
}
