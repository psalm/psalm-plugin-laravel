<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use Illuminate\Routing\UrlGenerator;
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

    /**
     * Regression: routesAreCached() resolves the 'files' container binding
     * (Illuminate\Foundation\Application::routesAreCached()). The dedicated partial-boot
     * fixture this test chdir()s into never completes BootProviders (no bootstrap/cache
     * directory — see that fixture's own bootstrap/app.php docblock), so 'files' is never
     * bound there at all. Before this was caught, the resulting BindingResolutionException
     * escaped initMissingRouteHandler() uncaught, propagated through __invoke()'s try block,
     * and disabled the WHOLE plugin for the run via the outer catch — not just the
     * MissingRoute feature. This is exactly how UnknownModelAttributeEmissionTest's
     * experimental fixture broke once findMissingRoutes started auto-enabling under
     * <experimental> (that fixture's own findings dropped to zero with this bug present).
     *
     * A plain `unset($app['files'])` does not reproduce this: the Testbench-fallback app
     * this test suite otherwise boots has 'files' registered as a deferred service, so the
     * container silently reloads FilesystemServiceProvider on next access. Only a genuinely
     * partial boot (this fixture) leaves it truly unbound.
     */
    #[Test]
    public function a_throwing_routes_are_cached_check_degrades_only_this_feature(): void
    {
        $fixtureDir = __DIR__ . '/Handlers/Fixtures/MissingRoute/partial-boot';
        $originalCwd = \getcwd();
        \assert(\is_string($originalCwd));

        \chdir($fixtureDir);

        try {
            ApplicationProvider::bootApp();
            $app = ApplicationProvider::getApp();

            // Confirm the fixture is still in the partial-boot shape this regression needs,
            // so a future fixture change that completes the boot fails loudly here instead
            // of this test silently passing for the wrong reason.
            $this->assertFalse($app->bound('files'), "Fixture assumption broken: 'files' is bound, so this no longer reproduces the regression.");

            $progress = new RecordingProgress();
            $this->invokeInitMissingRouteHandler($progress);
        } finally {
            \chdir($originalCwd);
        }

        $this->assertFalse($this->isEnabled(), 'MissingRouteHandler must stay disabled, not crash the whole invocation.');
        $this->assertSame(0, $progress->warningCount, 'Cannot determine cache state, so this must fall through to the silent path, same as a genuinely empty table.');
    }

    /**
     * Positive control for the two resolver tests below: the same fixture, same in-process boot,
     * no resolver registered. Without this, a resolver test could pass for the wrong reason (a
     * fixture that stopped resolving routes at all would also leave the handler disabled).
     */
    #[Test]
    public function enables_the_handler_with_the_fixture_route_table_when_no_resolver_is_registered(): void
    {
        $this->bootRouteFixture(static function (): void {});

        $this->assertTrue($this->isEnabled(), 'A booted app with named routes and no resolver must enable the handler.');

        // Not an exact-array assertion: the framework registers named routes of its own
        // (storage.local*), and which ones varies by Laravel version.
        $names = $this->registeredNames();
        $this->assertArrayHasKey('dashboard', $names);
        $this->assertArrayHasKey('posts.show', $names);
    }

    /**
     * Illuminate\Routing\UrlGenerator::route() consults the missing-named-route resolver before
     * throwing RouteNotFoundException, so in an app that registers one an unregistered name can
     * still resolve at runtime and every finding would be a false positive. The whole rule must
     * decline, exactly like the empty-table bail, rather than report names it cannot judge.
     *
     * Silent by design (debug, not warning): registering the resolver is an explicit opt-in to
     * dynamic route resolution, so the plugin standing down is the correct outcome, not a
     * degradation worth interrupting the run for.
     */
    #[Test]
    public function stays_disabled_when_the_app_registers_a_missing_named_route_resolver(): void
    {
        $progress = $this->bootRouteFixture(static function (UrlGenerator $url): void {
            $url->resolveMissingNamedRoutesUsing(static fn(string $name): string => "/legacy/{$name}");
        });

        $this->assertFalse($this->isEnabled(), 'A registered missing-named-route resolver must disable the rule entirely.');
        $this->assertSame([], $this->registeredNames());
        $this->assertSame(0, $progress->warningCount, 'Opting into dynamic route resolution is not a degradation: stay silent.');
    }

    /**
     * A project-specific `url` service cannot be probed for the resolver, so the rule's core
     * assumption (absent from the table = fails at runtime) is unverifiable. Inconclusive is
     * treated the same as "resolver present": decline, never guess.
     */
    #[Test]
    public function stays_disabled_when_the_url_service_is_not_a_laravel_url_generator(): void
    {
        $progress = $this->bootRouteFixture(static function (UrlGenerator $url): void {
            ApplicationProvider::getApp()->instance('url', new \stdClass());
        });

        $this->assertFalse($this->isEnabled(), 'An unprobeable url service must disable the rule rather than assume no resolver.');
        $this->assertSame(0, $progress->warningCount);
    }

    /**
     * Boot the complete-boot MissingRoute fixture in-process (a real bootstrap/app.php with
     * withRouting(), so the named-route table is genuinely populated: 'dashboard', 'posts.show'),
     * run $configure against its url service, then invoke the initializer.
     *
     * @param \Closure(UrlGenerator):void $configure
     */
    private function bootRouteFixture(\Closure $configure): RecordingProgress
    {
        $fixtureDir = __DIR__ . '/Handlers/Fixtures/MissingRoute';
        $originalCwd = \getcwd();
        \assert(\is_string($originalCwd));

        \chdir($fixtureDir);

        try {
            ApplicationProvider::bootApp();
            $url = ApplicationProvider::getApp()->make('url');
            \assert($url instanceof UrlGenerator);

            $configure($url);

            $progress = new RecordingProgress();
            $this->invokeInitMissingRouteHandler($progress);
        } finally {
            \chdir($originalCwd);
        }

        return $progress;
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
