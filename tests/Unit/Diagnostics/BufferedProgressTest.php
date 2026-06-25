<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Diagnostics\BufferedProgress;
use Psalm\LaravelPlugin\Diagnostics\Diagnostic;
use Psalm\LaravelPlugin\Diagnostics\DiagnosticsBuffer;
use Psalm\Progress\Progress;

#[CoversClass(BufferedProgress::class)]
#[CoversClass(DiagnosticsBuffer::class)]
#[CoversClass(Diagnostic::class)]
final class BufferedProgressTest extends TestCase
{
    /** @var list<string> */
    private array $innerWarnings = [];

    /** @var list<string> */
    private array $innerDebug = [];

    /** @var list<string> */
    private array $flushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->innerWarnings = [];
        $this->innerDebug = [];
        $this->flushed = [];
    }

    #[Test]
    public function a_warning_is_captured_into_the_buffer_not_the_inner_progress(): void
    {
        $buffer = new DiagnosticsBuffer();
        $progress = new BufferedProgress($this->recordingInner(), $buffer);

        $progress->setStage('schema');
        $progress->warning("the 'migrator' service is unavailable");

        $this->assertSame([], $this->innerWarnings, 'Buffered warnings must not reach the inner progress.');

        $buffer->flushTo($this->recordingSink());
        $this->assertSame(["[schema] the 'migrator' service is unavailable"], $this->flushed);
    }

    #[Test]
    public function each_warning_is_tagged_with_the_stage_active_when_it_was_raised(): void
    {
        $buffer = new DiagnosticsBuffer();
        $progress = new BufferedProgress($this->recordingInner(), $buffer);

        $progress->setStage('boot');
        $progress->warning('partial boot');
        $progress->setStage('schema');
        $progress->warning('migrator unavailable');

        $buffer->flushTo($this->recordingSink());

        $this->assertSame(['[boot] partial boot', '[schema] migrator unavailable'], $this->flushed);
    }

    #[Test]
    public function non_warning_calls_pass_through_to_the_inner_progress_immediately(): void
    {
        $buffer = new DiagnosticsBuffer();
        $progress = new BufferedProgress($this->recordingInner(), $buffer);

        $progress->debug('scanning migrations');

        $this->assertSame(['scanning migrations'], $this->innerDebug);

        // The debug call must not have been captured as a diagnostic.
        $buffer->flushTo($this->recordingSink());
        $this->assertSame([], $this->flushed);
    }

    #[Test]
    public function stop_buffering_routes_later_warnings_straight_to_the_inner_progress(): void
    {
        $buffer = new DiagnosticsBuffer();
        $progress = new BufferedProgress($this->recordingInner(), $buffer);

        $progress->stopBuffering();
        $progress->warning('raised after the flush point');

        $this->assertSame(['raised after the flush point'], $this->innerWarnings);

        $buffer->flushTo($this->recordingSink());
        $this->assertSame([], $this->flushed, 'Nothing should have been buffered after stopBuffering().');
    }

    /** Inner Progress double that records warning() and debug() passthrough. */
    private function recordingInner(): Progress
    {
        $progress = $this->createStub(Progress::class);
        $progress->method('warning')->willReturnCallback(
            function (string $message): void {
                $this->innerWarnings[] = $message;
            },
        );
        $progress->method('debug')->willReturnCallback(
            function (string $message): void {
                $this->innerDebug[] = $message;
            },
        );

        return $progress;
    }

    /** Separate sink that records what a flush replays. */
    private function recordingSink(): Progress
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
