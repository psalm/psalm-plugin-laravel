<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Providers;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider;

#[CoversClass(ContainerBindingMapProvider::class)]
final class ContainerBindingMapProviderTest extends TestCase
{
    /**
     * Issue #942 — class-string concrete: `bind('accessor', ConcreteClass::class)`
     * routes through {@see \Illuminate\Container\Container::getClosure()}, which
     * captures `$concrete` as a static variable on the wrapper closure. The map
     * extracts that class-string without resolving (avoids constructor crashes).
     */
    #[Test]
    public function init_maps_accessor_to_class_string_concrete(): void
    {
        $app = ApplicationProvider::getApp();
        $app->bind('binding-map-test/class-string', BindingMapTestFakeService::class);

        try {
            ContainerBindingMapProvider::init();

            $this->assertSame(BindingMapTestFakeService::class, ContainerBindingMapProvider::getClass('binding-map-test/class-string'));
        } finally {
            $this->resetBinding($app, 'binding-map-test/class-string');
        }
    }

    /**
     * Issue #942 — factory closure: `bind('accessor', fn () => new X())` stores
     * the user-authored closure as-is (no static vars). The map falls back to
     * PhpParser, re-reads the host file, runs `NameResolver`, and extracts the
     * first `new X(...)` in the closure's line range. Mirrors the imdhemy/laravel-
     * in-app-purchases shape.
     */
    #[Test]
    public function init_maps_accessor_to_class_returned_by_factory_closure(): void
    {
        $app = ApplicationProvider::getApp();
        $app->bind('binding-map-test/factory', static fn(): BindingMapTestFakeService => new BindingMapTestFakeService());

        try {
            ContainerBindingMapProvider::init();

            $this->assertSame(BindingMapTestFakeService::class, ContainerBindingMapProvider::getClass('binding-map-test/factory'));
        } finally {
            $this->resetBinding($app, 'binding-map-test/factory');
        }
    }

    /**
     * Aliases share a map entry with the abstract they point to so a facade
     * looking up the alias name (`Container::alias($abstract, $alias)` stores
     * `aliases[$alias] = $abstract`) resolves to the same class as the abstract.
     */
    #[Test]
    public function init_forwards_alias_to_bound_abstract(): void
    {
        $app = ApplicationProvider::getApp();
        $app->bind('binding-map-test/aliased-abstract', BindingMapTestFakeService::class);
        $app->alias('binding-map-test/aliased-abstract', 'binding-map-test/alias-name');

        try {
            ContainerBindingMapProvider::init();

            $this->assertSame(BindingMapTestFakeService::class, ContainerBindingMapProvider::getClass('binding-map-test/alias-name'), 'Alias must resolve to the same class as its abstract');
        } finally {
            $this->resetBinding($app, 'binding-map-test/aliased-abstract');
            $this->resetAlias($app, 'binding-map-test/alias-name');
        }
    }

    /**
     * A factory closure that doesn't `return new X()` (e.g. resolves from config,
     * builds conditionally) is unparseable. The map omits the entry rather than
     * guessing wrong — the runtime probe in `AppFacadeRegistrationHandler` still
     * gets a chance, falling through to the warning path on its own failure.
     */
    #[Test]
    public function init_skips_factory_closure_with_no_new_expression(): void
    {
        $app = ApplicationProvider::getApp();
        // Pass-through closure: returns an unrelated value, contains no `new` expression
        $app->bind('binding-map-test/no-new', static fn(): string => 'noop');

        try {
            ContainerBindingMapProvider::init();

            $this->assertNull(ContainerBindingMapProvider::getClass('binding-map-test/no-new'));
        } finally {
            $this->resetBinding($app, 'binding-map-test/no-new');
        }
    }

    /**
     * Lookups for accessors that were never bound (and have no alias entry) return
     * null. The map MUST NOT throw on misses — the facade handler treats null as
     * "fall through to runtime probe".
     */
    #[Test]
    public function get_class_returns_null_for_unknown_accessor(): void
    {
        ContainerBindingMapProvider::init();

        $this->assertNull(ContainerBindingMapProvider::getClass('binding-map-test/never-bound'));
    }

    /**
     * Container has no public binding-removal API; reach into the protected
     * `$bindings` array to keep the test isolated from other cases. Mirrors
     * {@see self::resetAlias()} (which the original review pointed out as the
     * idiomatic pattern for this codebase, vs. the `offsetUnset()` path that
     * crosses an `@internal` boundary and would need a `@psalm-suppress`).
     */
    private function resetBinding(\Illuminate\Foundation\Application $app, string $abstract): void
    {
        $bindings = new \ReflectionProperty(\Illuminate\Container\Container::class, 'bindings');
        /** @var array<string, array{concrete: \Closure, shared: bool}> $current */
        $current = $bindings->getValue($app);
        unset($current[$abstract]);
        $bindings->setValue($app, $current);
    }

    private function resetAlias(\Illuminate\Foundation\Application $app, string $alias): void
    {
        $aliases = new \ReflectionProperty(\Illuminate\Container\Container::class, 'aliases');
        /** @var array<string, string> $current */
        $current = $aliases->getValue($app);
        unset($current[$alias]);
        $aliases->setValue($app, $current);
    }
}

/** Test fixture — never resolved at runtime, only referenced by name in bindings. */
final class BindingMapTestFakeService
{
    public function ping(): string
    {
        return 'pong';
    }
}

/** Provider variant used to exercise harvester-style provider registration paths. */
final class BindingMapTestServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('binding-map-test/from-provider', BindingMapTestFakeService::class);
    }
}
