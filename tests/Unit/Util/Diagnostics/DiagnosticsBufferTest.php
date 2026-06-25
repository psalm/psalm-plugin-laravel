<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\Diagnostics\Diagnostic;
use Psalm\LaravelPlugin\Util\Diagnostics\DiagnosticsBuffer;

#[CoversClass(DiagnosticsBuffer::class)]
#[CoversClass(Diagnostic::class)]
final class DiagnosticsBufferTest extends TestCase
{
    #[Test]
    public function it_starts_empty(): void
    {
        $buffer = new DiagnosticsBuffer();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame([], $buffer->entries());
        $this->assertSame('', $buffer->format());
    }

    #[Test]
    public function it_records_entries_in_insertion_order_with_severity_and_stage(): void
    {
        $buffer = new DiagnosticsBuffer();
        $buffer->warning('boot', 'partial boot');
        $buffer->info('schema', 'cache written');
        $buffer->error('internal', 'broke');

        $this->assertFalse($buffer->isEmpty());

        $entries = $buffer->entries();
        $this->assertCount(3, $entries);

        $this->assertSame(['warning', 'boot', 'partial boot'], [
            $entries[0]->severity,
            $entries[0]->stage,
            $entries[0]->message,
        ]);
        $this->assertSame('info', $entries[1]->severity);
        $this->assertSame('error', $entries[2]->severity);
    }

    #[Test]
    public function format_groups_by_severity_then_keeps_lifecycle_order(): void
    {
        $buffer = new DiagnosticsBuffer();
        // Intentionally interleaved so the test proves the severity grouping, not
        // just echo order: warning, error, info, warning.
        $buffer->warning('boot', 'w-boot');
        $buffer->error('schema', 'e-schema');
        $buffer->info('views', 'i-views');
        $buffer->warning('handlers', 'w-handlers');

        $output = $buffer->format();

        // Errors first, then warnings (in insertion order), then info.
        $posError = \strpos($output, '[error/schema] e-schema');
        $posWarnBoot = \strpos($output, '[warning/boot] w-boot');
        $posWarnHandlers = \strpos($output, '[warning/handlers] w-handlers');
        $posInfo = \strpos($output, '[info/views] i-views');

        $this->assertNotFalse($posError);
        $this->assertNotFalse($posWarnBoot);
        $this->assertNotFalse($posWarnHandlers);
        $this->assertNotFalse($posInfo);

        $this->assertLessThan($posWarnBoot, $posError, 'errors must precede warnings');
        $this->assertLessThan($posWarnHandlers, $posWarnBoot, 'warnings keep lifecycle order');
        $this->assertLessThan($posInfo, $posWarnHandlers, 'warnings must precede info');
    }

    #[Test]
    public function format_header_counts_and_pluralises(): void
    {
        $one = new DiagnosticsBuffer();
        $one->warning('boot', 'only one');
        $this->assertStringContainsString('Laravel plugin: 1 initialization notice:', $one->format());

        $two = new DiagnosticsBuffer();
        $two->warning('boot', 'first');
        $two->warning('schema', 'second');
        $this->assertStringContainsString('Laravel plugin: 2 initialization notices:', $two->format());
    }

    #[Test]
    public function clear_empties_the_buffer(): void
    {
        $buffer = new DiagnosticsBuffer();
        $buffer->warning('boot', 'something');

        $buffer->clear();

        $this->assertTrue($buffer->isEmpty());
        $this->assertSame('', $buffer->format());
    }
}
