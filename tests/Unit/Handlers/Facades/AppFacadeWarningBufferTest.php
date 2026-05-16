<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Facades;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Facades\AppFacadeRegistrationHandler;
use Psalm\Progress\Phase;
use Psalm\Progress\Progress;

/**
 * Covers issue #941: warnings emitted from the scan-phase `afterClassLikeVisit` hook
 * must not splice onto Psalm's open `\rN / total...` progress counter. We verify the
 * scan-phase deferral, the lazy `PHP_EOL` separator that terminates the open counter
 * line, and that zero-warning runs leave output untouched.
 *
 * The handler stores its buffer + separator gate in static properties because the same
 * pipeline runs across thousands of `afterClassLikeVisit` calls per scan; reflection is
 * used here only to scope each test case to a clean state — production callers reach
 * the same fields through `afterClassLikeVisit` / `afterCodebasePopulated`.
 */
#[CoversClass(AppFacadeRegistrationHandler::class)]
final class AppFacadeWarningBufferTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        $this->resetHandlerState();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->resetHandlerState();
    }

    #[Test]
    public function deferred_warning_is_not_written_until_flush(): void
    {
        $progress = new CapturingProgress();

        // stdClass is loadable but not a Facade subclass — hits the "not a subclass of"
        // branch in tryGetFacadeRootClass, which emits a warning through emitWarning().
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\stdClass::class, $progress, true);

        $this->assertSame('', $progress->captured, 'Deferred warning must not write immediately.');
        $this->assertSame([\stdClass::class], \array_keys($this->getFailedFacades()));
        $this->assertCount(1, $this->getPendingScanWarnings());
    }

    #[Test]
    public function flush_emits_separator_then_warning(): void
    {
        $progress = new CapturingProgress();

        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\stdClass::class, $progress, true);

        $this->invokeFlush($progress);

        $this->assertStringStartsWith(\PHP_EOL, $progress->captured, "First emission must lead with PHP_EOL to terminate Psalm's open scan-counter line.");
        $this->assertStringContainsString('Warning: Laravel plugin', $progress->captured);
        $this->assertStringContainsString("not a subclass of Illuminate\\Support\\Facades\\Facade", $progress->captured);
        $this->assertSame([], $this->getPendingScanWarnings(), 'Buffer must drain on flush.');
    }

    #[Test]
    public function direct_emission_writes_separator_once_for_multiple_warnings(): void
    {
        $progress = new CapturingProgress();

        // Two distinct classes so failedFacades does not short-circuit the second call.
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\stdClass::class, $progress, false);
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\ArrayObject::class, $progress, false);

        $this->assertStringStartsWith(\PHP_EOL, $progress->captured);
        // Exactly one separator PHP_EOL at the start; the two `Warning:` lines each carry
        // their own trailing PHP_EOL from Progress::warning(). So there should be three
        // PHP_EOL bytes total: leading separator + two warning terminators.
        $eolCount = \substr_count($progress->captured, \PHP_EOL);
        $this->assertSame(3, $eolCount, 'Only one leading separator PHP_EOL should be written across the batch.');
    }

    #[Test]
    public function flush_with_empty_buffer_writes_nothing(): void
    {
        $progress = new CapturingProgress();
        $this->invokeFlush($progress);

        $this->assertSame('', $progress->captured, 'Zero-warning run must not leak a stray blank line.');
        $this->assertFalse($this->getWroteScanSeparator(), 'Separator gate must remain unset when no warning was flushed.');
    }

    #[Test]
    public function deferred_then_direct_share_the_same_separator(): void
    {
        $progress = new CapturingProgress();

        // Scan-phase: deferred warning queued, buffer holds the message.
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\stdClass::class, $progress, true);
        // Populate-phase: flush drains buffer (writes separator + first warning) then
        // a populate-phase emission (separate class) writes a second warning. Both must
        // share the same leading separator.
        $this->invokeFlush($progress);
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\ArrayObject::class, $progress, false);

        $eolCount = \substr_count($progress->captured, \PHP_EOL);
        $this->assertSame(3, $eolCount, 'Flush + subsequent direct emission must not duplicate the separator.');
    }

    /**
     * Regression guard for the cross-run gate leak surfaced by the psalm-expert agent
     * during review: `checkPaths()` re-entry (CLI re-analysis) and daemon/LSP loops run
     * the populate phase multiple times in one PHP process. Without re-arming the gate at
     * each flush, run #2 would skip the separator and splice into the new scan-counter
     * line — reintroducing #941.
     */
    #[Test]
    public function flush_rearms_separator_gate_for_next_run(): void
    {
        $progress1 = new CapturingProgress();
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\stdClass::class, $progress1, true);
        $this->invokeFlush($progress1);
        $this->assertTrue($this->getWroteScanSeparator(), 'Gate must be set after first flush so populate-phase warnings share the separator.');

        // Simulate a second analysis run on the same handler: fresh Progress, fresh
        // deferred warning. Buffer state was already drained by the first flush; rebuild
        // it so the second flush has something to emit (production reaches this state
        // via afterClassLikeVisit firing again in run 2). $failedFacades is intentionally
        // *not* reset — the prior failure was on stdClass, so use a different class.
        $progress2 = new CapturingProgress();
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\ArrayObject::class, $progress2, true);
        $this->invokeFlush($progress2);

        $this->assertStringStartsWith(\PHP_EOL, $progress2->captured, 'Second-run flush must write its own separator to terminate the new scan-counter line.');
    }

    #[Test]
    public function throwable_from_get_facade_root_emits_failure_warning(): void
    {
        $progress = new CapturingProgress();

        // Subclass of Facade whose getFacadeAccessor() throws. `Facade::getFacadeRoot()`
        // resolves the accessor through the container; the exception thrown by the
        // accessor lookup propagates out of `getFacadeRoot()`. This is the path that
        // wedged onto Psalm's progress counter in the original bug report — third-party
        // facades whose accessor binding lives in a service provider that does not run
        // under Testbench.
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(ThrowingFacadeFixture::class, $progress, true);
        $this->invokeFlush($progress);

        $this->assertStringContainsString("getFacadeRoot() failed for '", $progress->captured);
        $this->assertStringContainsString(ThrowingFacadeFixture::class, $progress->captured);
        $this->assertArrayHasKey(ThrowingFacadeFixture::class, $this->getFailedFacades());
    }

    /**
     * Cross-phase deduplication invariant: a facade that already failed once (e.g. during
     * scan) must not buffer a second message when probed again (e.g. during populate).
     * Without this guarantee the user sees the same warning twice per facade.
     */
    #[Test]
    public function repeated_probe_of_same_facade_buffers_only_once(): void
    {
        $progress = new CapturingProgress();

        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\stdClass::class, $progress, true);
        AppFacadeRegistrationHandler::tryGetFacadeRootClass(\stdClass::class, $progress, true);

        $this->assertCount(1, $this->getPendingScanWarnings(), 'failedFacades must short-circuit subsequent probes before they reach the buffer.');
    }

    /**
     * Direct probe of {@see AppFacadeRegistrationHandler::flushPendingOnShutdown()} — the
     * STDERR-fallback path used when a scan worker (`--threads N > 1`) exits with
     * buffered warnings the main process will never see. The method exposes an
     * `$stream` test seam so we can substitute an in-memory stream for STDERR and read
     * back what would have been written; `register_shutdown_function` invokes the method
     * with no arguments, so production behaviour is unchanged.
     */
    #[Test]
    public function shutdown_drain_writes_buffered_warnings_to_stream(): void
    {
        $reflection = new \ReflectionClass(AppFacadeRegistrationHandler::class);
        $reflection->setStaticPropertyValue('pendingScanWarnings', [
            "Laravel plugin: getFacadeRoot() failed for 'Acme\\Foo': bound to unresolved alias",
            "Laravel plugin: getFacadeRoot() failed for 'Acme\\Bar': bound to unresolved alias",
        ]);

        $stream = \fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);

        AppFacadeRegistrationHandler::flushPendingOnShutdown($stream);

        \rewind($stream);
        $captured = (string) \stream_get_contents($stream);
        \fclose($stream);

        $this->assertStringContainsString("Warning: Laravel plugin: getFacadeRoot() failed for 'Acme\\Foo'", $captured);
        $this->assertStringContainsString("Warning: Laravel plugin: getFacadeRoot() failed for 'Acme\\Bar'", $captured);
        // Each message is wrapped with a leading + trailing PHP_EOL, so two messages
        // produce four newlines total. Verifying the count guards against future drift in
        // the wedged-but-visible output shape.
        $this->assertSame(4, \substr_count($captured, \PHP_EOL));
        $this->assertSame([], $this->getPendingScanWarnings(), 'Shutdown drain must empty the buffer to avoid double-emission.');
    }

    private function resetHandlerState(): void
    {
        $reflection = new \ReflectionClass(AppFacadeRegistrationHandler::class);
        $reflection->setStaticPropertyValue('failedFacades', []);
        $reflection->setStaticPropertyValue('pendingScanWarnings', []);
        $reflection->setStaticPropertyValue('wroteScanSeparator', false);
        // Leave $shutdownHookRegistered alone — register_shutdown_function() cannot be
        // unregistered, and re-registering across tests would attach multiple callbacks
        // to PHP shutdown. The hook itself is a no-op when the buffer is empty.
    }

    private function invokeFlush(Progress $progress): void
    {
        $method = new \ReflectionMethod(AppFacadeRegistrationHandler::class, 'flushScanWarnings');
        $method->invoke(null, $progress);
    }

    /** @return array<string, true> */
    private function getFailedFacades(): array
    {
        /** @var array<string, true> $value */
        $value = (new \ReflectionClass(AppFacadeRegistrationHandler::class))->getStaticPropertyValue('failedFacades');
        return $value;
    }

    /** @return list<string> */
    private function getPendingScanWarnings(): array
    {
        /** @var list<string> $value */
        $value = (new \ReflectionClass(AppFacadeRegistrationHandler::class))->getStaticPropertyValue('pendingScanWarnings');
        return $value;
    }

    private function getWroteScanSeparator(): bool
    {
        /** @var bool $value */
        $value = (new \ReflectionClass(AppFacadeRegistrationHandler::class))->getStaticPropertyValue('wroteScanSeparator');
        return $value;
    }
}

/**
 * Captures every Progress::write() call into a single buffer so tests can assert on the
 * raw stderr output the handler would produce. Inherits Progress directly because
 * Psalm's VoidProgress is final.
 *
 * @internal
 */
final class CapturingProgress extends Progress
{
    public string $captured = '';

    #[\Override]
    public function write(string $message): void
    {
        $this->captured .= $message;
    }

    #[\Override]
    public function debug(string $message): void {}

    #[\Override]
    public function startPhase(Phase $phase, int $threads = 1): void {}

    #[\Override]
    public function expand(int $number_of_tasks): void {}

    #[\Override]
    public function taskDone(int $level): void {}

    #[\Override]
    public function finish(): void {}

    #[\Override]
    public function alterFileDone(string $file_name): void {}
}

/**
 * Minimal Facade subclass whose accessor throws — mirrors the third-party facades from
 * the original bug report whose accessor binding only exists in a service provider that
 * does not run under the plugin's Testbench application. The handler should catch the
 * Throwable and emit a `"getFacadeRoot() failed for ..."` warning through `emitWarning`.
 *
 * @internal
 */
final class ThrowingFacadeFixture extends \Illuminate\Support\Facades\Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        throw new \RuntimeException('unbound accessor for ThrowingFacadeFixture');
    }
}
