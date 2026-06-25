<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Diagnostics\BufferedProgress;
use Psalm\LaravelPlugin\Diagnostics\DiagnosticsBuffer;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Util\ApplicationBootReporter;
use Psalm\Progress\Progress;

#[CoversClass(ApplicationBootReporter::class)]
final class ApplicationBootReporterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalState = [];

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalState = $this->snapshotApplicationProviderState();
        $this->warnings = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->originalState as $property => $value) {
            $this->reflectProperty($property)->setValue(null, $value);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_does_not_warn_when_bootstrap_succeeded(): void
    {
        $this->reflectProperty('bootstrapError')->setValue(null, null);

        ApplicationBootReporter::reportPartialBoot($this->recordingProgress());

        $this->assertSame([], $this->warnings);
    }

    #[Test]
    public function it_reports_partial_boot_with_context_and_next_steps(): void
    {
        $this->reflectProperty('bootMode')->setValue(null, 'bootstrap');
        $this->reflectProperty('bootPath')->setValue(null, '/app/bootstrap/app.php');
        $this->reflectProperty('bootstrapError')->setValue(
            null,
            new \RuntimeException('parse_url(): Argument #1 must be string'),
        );

        ApplicationBootReporter::reportPartialBoot($this->recordingProgress());

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('Laravel boot completed only partially', $this->warnings[0]);
        $this->assertStringContainsString('mode: bootstrap', $this->warnings[0]);
        $this->assertStringContainsString('/app/bootstrap/app.php', $this->warnings[0]);
        $this->assertStringContainsString('parse_url(): Argument #1 must be string', $this->warnings[0]);
        $this->assertStringContainsString('vendor/bin/psalm-laravel diagnose --tips --providers', $this->warnings[0]);
    }

    #[Test]
    public function the_partial_boot_warning_is_buffered_not_printed_mid_progress(): void
    {
        $this->reflectProperty('bootMode')->setValue(null, 'bootstrap');
        $this->reflectProperty('bootPath')->setValue(null, '/app/bootstrap/app.php');
        $this->reflectProperty('bootstrapError')->setValue(null, new \RuntimeException('boom in config/app.php'));

        $buffer = new DiagnosticsBuffer();
        $output = new BufferedProgress($this->recordingProgress(), $buffer);
        $output->setStage('boot');

        ApplicationBootReporter::reportPartialBoot($output);

        // Captured into the buffer, so nothing reached the (inner) progress yet.
        $this->assertSame([], $this->warnings);

        // Draining the buffer surfaces the warning under the boot stage.
        $buffer->flushTo($this->recordingProgress());
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('[boot]', (string) $this->warnings[0]);
        $this->assertStringContainsString('Laravel boot completed only partially', (string) $this->warnings[0]);
        $this->assertStringContainsString('boom in config/app.php', (string) $this->warnings[0]);
    }

    /** Stubbed Progress that records emitted warnings into the test buffer. */
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

    /**
     * @return array<string, mixed>
     */
    private function snapshotApplicationProviderState(): array
    {
        return [
            'app' => $this->reflectProperty('app')->getValue(),
            'bootMode' => $this->reflectProperty('bootMode')->getValue(),
            'bootPath' => $this->reflectProperty('bootPath')->getValue(),
            'bootFailure' => $this->reflectProperty('bootFailure')->getValue(),
            'bootstrapError' => $this->reflectProperty('bootstrapError')->getValue(),
            'booted' => $this->reflectProperty('booted')->getValue(),
        ];
    }

    private function reflectProperty(string $name): \ReflectionProperty
    {
        return new \ReflectionProperty(ApplicationProvider::class, $name);
    }
}
