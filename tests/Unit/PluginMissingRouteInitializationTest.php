<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Handlers\Rules\MissingRouteHandler;
use Psalm\LaravelPlugin\Plugin;

/**
 * Fast, in-process guard for `Plugin::initMissingRouteHandler()`'s empty-table branches — the
 * branches a phpt type test cannot exercise as a POSITIVE assertion (the psalm-tester harness
 * boots this exact Testbench fallback, so every phpt run already goes through the plain empty-
 * table path implicitly) and that a real Psalm subprocess would be needlessly slow to pin
 * directly. Boots the plugin's Testbench fallback (no bootstrap/app.php at the plugin root,
 * mirroring the psalm-tester harness) — the router is bound but no route file is ever loaded,
 * so the named-route table comes back empty. `MissingRouteHandler::init()` must not be called
 * in that case, or an app with zero known routes would report every route name as missing.
 *
 * A second scenario shares that same empty table for a different, more surprising reason.
 * A compiled route cache (`bootstrap/cache/routes-v7.php`) is read the same way a live
 * route-file boot is, so a genuine, current cache populates the table normally; this
 * branch is for the narrower case where `routesAreCached()` is true and the cache itself
 * carries zero named routes. Silently disabling in that case would read as "no findings"
 * (clean) rather than "not checked" (untracked), so that path must warn.
 *
 * The positive path (a real, non-empty route table) is guarded end-to-end by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\MissingRouteEmissionTest}.
 */
#[CoversClass(Plugin::class)]
final class PluginMissingRouteInitializationTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ApplicationProvider::reset();
        MissingRouteHandler::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ApplicationProvider::reset();
        MissingRouteHandler::reset();
    }

    #[Test]
    public function stays_disabled_when_the_booted_app_has_no_named_routes(): void
    {
        ApplicationProvider::bootApp();

        $progress = new RecordingProgress();
        $this->invokeInitMissingRouteHandler($progress);

        $this->assertFalse($this->isEnabled(), 'MissingRouteHandler must stay disabled when the named-route table is empty.');
        $this->assertSame([], $this->registeredNames());
        $this->assertSame(0, $progress->warningCount, 'A package/library boot with no route files is the expected shape and must not warn.');
    }

    #[Test]
    public function warns_and_stays_disabled_when_the_empty_table_is_caused_by_a_route_cache(): void
    {
        ApplicationProvider::bootApp();
        // routesAreCached() checks this binding before ever touching the filesystem
        // (Illuminate\Foundation\Application::routesAreCached()), so this is the cheapest
        // way to simulate "a compiled route cache is present" without shipping a real
        // bootstrap/cache/routes-v7.php fixture.
        ApplicationProvider::getApp()->instance('routes.cached', true);

        $progress = new RecordingProgress();
        $this->invokeInitMissingRouteHandler($progress);

        $this->assertFalse($this->isEnabled(), 'MissingRouteHandler must stay disabled when the route cache yields no named routes.');
        $this->assertSame(1, $progress->warningCount, 'A cached-routes empty table must warn exactly once.');
        $this->assertStringContainsString('route:cache', $progress->lastWarning);
        $this->assertStringContainsString('route:clear', $progress->lastWarning);
    }

    private function invokeInitMissingRouteHandler(\Psalm\Progress\Progress $progress): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'initMissingRouteHandler');
        $method->invoke(new Plugin(), $progress);
    }

    private function isEnabled(): bool
    {
        $property = new \ReflectionProperty(MissingRouteHandler::class, 'enabled');

        /** @var bool $value */
        $value = $property->getValue();

        return $value;
    }

    /** @return array<string, true> */
    private function registeredNames(): array
    {
        $property = new \ReflectionProperty(MissingRouteHandler::class, 'names');

        /** @var array<string, true> $value */
        $value = $property->getValue();

        return $value;
    }
}

/**
 * Test-only Progress that records warnings without writing to STDERR. Other Progress hooks
 * are no-ops because the code under test only uses warning(). Mirrors the RecordingProgress
 * double in NoEnvOutsideConfigHandlerTest — kept local (not shared) to avoid coupling two
 * unrelated test suites to one private test double.
 */
final class RecordingProgress extends \Psalm\Progress\Progress
{
    public int $warningCount = 0;

    public string $lastWarning = '';

    #[\Override]
    public function debug(string $message): void {}

    #[\Override]
    public function startPhase(\Psalm\Progress\Phase $phase, int $threads = 1): void {}

    #[\Override]
    public function expand(int $number_of_tasks): void {}

    #[\Override]
    public function taskDone(int $level): void {}

    #[\Override]
    public function finish(): void {}

    #[\Override]
    public function alterFileDone(string $file_name): void {}

    #[\Override]
    public function write(string $message): void {}

    #[\Override]
    public function warning(string $message): void
    {
        $this->warningCount++;
        $this->lastWarning = $message;
    }
}
