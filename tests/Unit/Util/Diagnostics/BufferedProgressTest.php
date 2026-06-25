<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\Diagnostics\BufferedProgress;
use Psalm\LaravelPlugin\Util\Diagnostics\DiagnosticsBuffer;
use Psalm\Progress\Progress;

#[CoversClass(BufferedProgress::class)]
#[CoversClass(DiagnosticsBuffer::class)]
final class BufferedProgressTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    /** @var list<string> */
    private array $debugged = [];

    #[Test]
    public function warning_is_buffered_and_not_written_until_flush(): void
    {
        $progress = new BufferedProgress($this->recordingInner());
        $progress->stage('schema');
        $progress->warning("the 'migrator' service is unavailable");

        // Nothing reaches the wrapped progress while init is still running.
        $this->assertSame([], $this->written);

        $progress->flush();

        $this->assertCount(1, $this->written);
        $this->assertStringContainsString("[warning/schema] the 'migrator' service is unavailable", $this->written[0]);
        $this->assertStringContainsString('Laravel plugin: 1 initialization notice:', $this->written[0]);
    }

    #[Test]
    public function each_warning_records_the_stage_active_when_it_was_emitted(): void
    {
        $progress = new BufferedProgress($this->recordingInner());

        $progress->stage('boot');
        $progress->warning('boot warning');
        $progress->stage('stubs');
        $progress->warning('stubs warning');

        $progress->flush();

        $this->assertStringContainsString('[warning/boot] boot warning', $this->written[0]);
        $this->assertStringContainsString('[warning/stubs] stubs warning', $this->written[0]);
    }

    #[Test]
    public function flush_clears_the_buffer_so_a_second_flush_is_a_no_op(): void
    {
        $progress = new BufferedProgress($this->recordingInner());
        $progress->warning('once');

        $progress->flush();
        $progress->flush();

        $this->assertCount(1, $this->written, 'the block must not be written twice');
    }

    #[Test]
    public function flush_writes_nothing_when_no_diagnostics_were_collected(): void
    {
        $progress = new BufferedProgress($this->recordingInner());

        $progress->flush();

        $this->assertSame([], $this->written);
    }

    #[Test]
    public function debug_and_write_forward_to_the_wrapped_progress_immediately(): void
    {
        $progress = new BufferedProgress($this->recordingInner());

        $progress->debug('scanning');
        $progress->write('raw');

        $this->assertSame(['scanning'], $this->debugged);
        $this->assertSame(['raw'], $this->written);
    }

    #[Test]
    public function inner_exposes_the_wrapped_progress(): void
    {
        $inner = $this->recordingInner();
        $progress = new BufferedProgress($inner);

        $this->assertSame($inner, $progress->inner());
    }

    /**
     * Defaulting the stage to `internal` means a warning emitted before any
     * stage() call is still captured (never dropped) and rendered with a stage tag.
     */
    #[Test]
    public function warning_before_any_stage_call_defaults_to_internal(): void
    {
        $progress = new BufferedProgress($this->recordingInner());
        $progress->warning('very early');

        $progress->flush();

        $this->assertStringContainsString('[warning/internal] very early', $this->written[0]);
    }

    /** Records the wrapped progress's write()/debug() output into the test buffers. */
    private function recordingInner(): Progress
    {
        $inner = $this->createStub(Progress::class);
        $inner->method('write')->willReturnCallback(
            function (string $message): void {
                $this->written[] = $message;
            },
        );
        $inner->method('debug')->willReturnCallback(
            function (string $message): void {
                $this->debugged[] = $message;
            },
        );

        return $inner;
    }
}
