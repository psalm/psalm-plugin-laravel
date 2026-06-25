<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Diagnostics\Diagnostic;
use Psalm\LaravelPlugin\Diagnostics\DiagnosticsBuffer;
use Psalm\Progress\Progress;

#[CoversClass(DiagnosticsBuffer::class)]
#[CoversClass(Diagnostic::class)]
final class DiagnosticsBufferTest extends TestCase
{
    /** @var list<string> */
    private array $flushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushed = [];
    }

    #[Test]
    public function flush_emits_nothing_when_empty(): void
    {
        (new DiagnosticsBuffer())->flushTo($this->recordingProgress());

        $this->assertSame([], $this->flushed);
    }

    #[Test]
    public function a_warning_is_collected_and_flushed_with_its_stage_tag(): void
    {
        $buffer = new DiagnosticsBuffer();
        $buffer->warning('schema', "the 'migrator' service is unavailable");

        $buffer->flushTo($this->recordingProgress());

        $this->assertSame(["[schema] the 'migrator' service is unavailable"], $this->flushed);
    }

    #[Test]
    public function entries_are_grouped_by_severity_most_severe_first(): void
    {
        $buffer = new DiagnosticsBuffer();
        // Add out of severity order on purpose: flush must reorder to error > warning > info.
        $buffer->warning('boot', 'partial boot');
        $buffer->add('info', 'facades', 'facade map ready');
        $buffer->add('error', 'internal', 'init failed');

        $buffer->flushTo($this->recordingProgress());

        $this->assertSame(
            ['[internal] init failed', '[boot] partial boot', '[facades] facade map ready'],
            $this->flushed,
        );
    }

    #[Test]
    public function insertion_order_is_preserved_within_a_severity(): void
    {
        $buffer = new DiagnosticsBuffer();
        $buffer->warning('schema', 'first');
        $buffer->warning('views', 'second');

        $buffer->flushTo($this->recordingProgress());

        $this->assertSame(['[schema] first', '[views] second'], $this->flushed);
    }

    /** Stubbed Progress that records flushed warnings into {@see self::$flushed}. */
    private function recordingProgress(): Progress
    {
        $progress = $this->createStub(Progress::class);
        $progress->method('warning')->willReturnCallback(
            function (string $message): void {
                $this->flushed[] = $message;
            },
        );

        return $progress;
    }
}
