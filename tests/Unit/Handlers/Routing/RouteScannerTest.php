<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Routing;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Routing\RouteParameterRegistry;
use Psalm\LaravelPlugin\Handlers\Routing\RouteScanner;

/**
 * End-to-end coverage for the runtime scan used at plugin boot.
 *
 * Each test wires a real {@see Router} (with an empty container + dispatcher,
 * which is enough — we never dispatch anything), registers the routing fixture
 * for the case under test, runs the scanner, and asserts the resulting
 * {@see RouteParameterRegistry} agrees with the documented behaviour.
 */
#[CoversClass(RouteScanner::class)]
#[CoversClass(RouteParameterRegistry::class)]
final class RouteScannerTest extends TestCase
{
    private function makeRouter(): Router
    {
        return new Router(new Dispatcher(new Container()));
    }

    #[Test]
    public function empty_router_yields_empty_registry(): void
    {
        $registry = (new RouteScanner())->scan($this->makeRouter());

        $this->assertNull($registry->getBoundModel('anything'));
        $this->assertFalse($registry->hasSafeConstraint('anything'));
    }

    #[Test]
    public function bind_with_declared_return_type_registers_model(): void
    {
        $router = $this->makeRouter();
        $router->bind('genre', static fn(string $value): RouteScannerTestGenre
            => new RouteScannerTestGenre());

        $registry = (new RouteScanner())->scan($router);

        $this->assertSame(
            RouteScannerTestGenre::class,
            $registry->getBoundModel('genre'),
        );
    }

    #[Test]
    public function bind_with_nullable_return_type_registers_model(): void
    {
        $router = $this->makeRouter();
        $router->bind('genre', static fn(string $value): ?RouteScannerTestGenre
            => null);

        $registry = (new RouteScanner())->scan($router);

        $this->assertSame(RouteScannerTestGenre::class, $registry->getBoundModel('genre'));
    }

    #[Test]
    public function bind_without_return_type_is_skipped(): void
    {
        $router = $this->makeRouter();
        $router->bind('opaque', static fn(string $value): string => $value);

        $registry = (new RouteScanner())->scan($router);

        $this->assertNull($registry->getBoundModel('opaque'));
    }

    #[Test]
    public function bind_with_non_model_return_type_is_skipped(): void
    {
        $router = $this->makeRouter();
        $router->bind('weird', static fn(string $value): \stdClass => new \stdClass());

        $registry = (new RouteScanner())->scan($router);

        $this->assertNull($registry->getBoundModel('weird'));
    }

    #[Test]
    public function model_registers_via_static_variable(): void
    {
        $router = $this->makeRouter();
        // Route::model wraps the lookup in a closure that captures `$class`.
        // The scanner reads it via ReflectionFunction::getStaticVariables()
        // because the closure has no declared return type.
        $router->model('genre', RouteScannerTestGenre::class);

        $registry = (new RouteScanner())->scan($router);

        $this->assertSame(RouteScannerTestGenre::class, $registry->getBoundModel('genre'));
    }

    #[Test]
    public function model_with_non_model_class_is_skipped(): void
    {
        $router = $this->makeRouter();
        // `Route::model` accepts any class-string at registration time;
        // Laravel only validates at resolution. The scanner must reject
        // non-Model captures so we don't claim a binding that the
        // ImplicitRouteBinding resolver will fail to honour.
        $router->model('weird', \stdClass::class);

        $registry = (new RouteScanner())->scan($router);

        $this->assertNull($registry->getBoundModel('weird'));
    }

    #[Test]
    public function bind_returning_base_model_is_skipped(): void
    {
        $router = $this->makeRouter();
        // The bare base Model isn't useful as a binding target — every model
        // is a Model, so this would always over-narrow callers. Reject.
        $router->bind('thing', static fn(string $value): Model => new RouteScannerTestGenre());

        $registry = (new RouteScanner())->scan($router);

        $this->assertNull($registry->getBoundModel('thing'));
    }

    #[Test]
    public function isEmpty_short_circuit_works(): void
    {
        $router = $this->makeRouter();
        $registry = (new RouteScanner())->scan($router);

        $this->assertTrue(
            $registry->isEmpty(),
            'Empty router must produce an empty registry — taint handler relies on this for hot-path skip',
        );

        $router->bind('genre', static fn(string $value): RouteScannerTestGenre
            => new RouteScannerTestGenre());

        $populated = (new RouteScanner())->scan($router);
        $this->assertFalse($populated->isEmpty());
    }

    #[Test]
    public function safe_global_pattern_marks_constraint_safe(): void
    {
        $router = $this->makeRouter();
        $router->pattern('id', '\d+');
        // A route must use the parameter for the registry to surface it,
        // because the scanner scopes safe constraints to names that actually
        // appear in registered routes (see RouteScanner::collectSafeConstraints).
        $router->get('/foo/{id}', static fn(): null => null);

        $registry = (new RouteScanner())->scan($router);

        $this->assertTrue($registry->hasSafeConstraint('id'));
    }

    #[Test]
    public function unsafe_global_pattern_does_not_mark_safe(): void
    {
        $router = $this->makeRouter();
        $router->pattern('id', '.+');
        $router->get('/foo/{id}', static fn(): null => null);

        $registry = (new RouteScanner())->scan($router);

        $this->assertFalse($registry->hasSafeConstraint('id'));
    }

    #[Test]
    public function per_route_safe_where_marks_constraint_safe(): void
    {
        $router = $this->makeRouter();
        $router->get('/foo/{key}', static fn(): null => null)
            ->where('key', '[A-Za-z0-9]+');

        $registry = (new RouteScanner())->scan($router);

        $this->assertTrue(
            $registry->hasSafeConstraint('key'),
            'koel reproducer (issue #849) should be recognised',
        );
    }

    #[Test]
    public function unconstrained_route_disables_safety_for_name(): void
    {
        $router = $this->makeRouter();
        // Two routes share the name 'id'. One has a safe where, the other has
        // none. The scanner must conservatively reject — at the call site we
        // can't know which route resolves the request.
        $router->get('/safe/{id}', static fn(): null => null)->where('id', '\d+');
        $router->get('/unsafe/{id}', static fn(): null => null);

        $registry = (new RouteScanner())->scan($router);

        $this->assertFalse(
            $registry->hasSafeConstraint('id'),
            'mixed safe/unconstrained routes must be treated as unsafe to avoid false negatives',
        );
    }

    #[Test]
    public function disagreeing_safe_constraints_are_still_safe(): void
    {
        $router = $this->makeRouter();
        // Both regexes are individually safe — different shapes still produce
        // the same property (no metachar) so the conservative aggregation
        // stays safe.
        $router->get('/digits/{id}', static fn(): null => null)->where('id', '\d+');
        $router->get('/alpha/{id}', static fn(): null => null)->where('id', '[a-zA-Z0-9]+');

        $registry = (new RouteScanner())->scan($router);

        $this->assertTrue($registry->hasSafeConstraint('id'));
    }

    #[Test]
    public function one_unsafe_route_disables_safety_for_name(): void
    {
        $router = $this->makeRouter();
        $router->get('/safe/{key}', static fn(): null => null)->where('key', '\d+');
        $router->get('/unsafe/{key}', static fn(): null => null)->where('key', '.+');

        $registry = (new RouteScanner())->scan($router);

        $this->assertFalse($registry->hasSafeConstraint('key'));
    }
}

/**
 * @internal fixture model used only inside this test file.
 */
class RouteScannerTestGenre extends Model {}
