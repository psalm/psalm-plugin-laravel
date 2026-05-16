<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Util;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider;
use Psalm\LaravelPlugin\Util\ProviderBindingHarvester;

/**
 * Exercises {@see ProviderBindingHarvester} against synthesised
 * `ServiceProvider::register()` bodies. We don't construct a `Codebase` here —
 * the harvester is deliberately decoupled from Psalm so it can be tested with
 * just PhpParser + a manual `NameResolver` pass (the same pass Psalm runs at
 * scan time, which attaches the `resolvedName` attribute used by the resolver).
 */
#[CoversClass(ProviderBindingHarvester::class)]
final class ProviderBindingHarvesterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ContainerBindingMapProvider::reset();
    }

    protected function tearDown(): void
    {
        ContainerBindingMapProvider::reset();
        parent::tearDown();
    }

    #[Test]
    public function harvests_bind_with_class_string_second_arg(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->bind('subscription', SubscriptionClient::class);
                }
            }
            class SubscriptionClient {}
        PHP);

        $this->assertSame('Acme\\SubscriptionClient', ContainerBindingMapProvider::lookup('subscription'));
    }

    #[Test]
    public function harvests_singleton_with_class_string_second_arg(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->singleton('config.driver', DriverConfig::class);
                }
            }
            class DriverConfig {}
        PHP);

        $this->assertSame('Acme\\DriverConfig', ContainerBindingMapProvider::lookup('config.driver'));
    }

    #[Test]
    public function harvests_instance_binding(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->instance('cfg', Cfg::class);
                }
            }
            class Cfg {}
        PHP);

        $this->assertSame('Acme\\Cfg', ContainerBindingMapProvider::lookup('cfg'));
    }

    #[Test]
    public function harvests_alias_with_swapped_argument_order(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->alias(\Acme\Real::class, 'real.alias');
                }
            }
            class Real {}
        PHP);

        $this->assertSame('Acme\\Real', ContainerBindingMapProvider::lookup('real.alias'));
    }

    #[Test]
    public function harvests_arrow_function_factory(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->singleton('datatables.request', fn () => new Request());
                }
            }
            class Request {}
        PHP);

        $this->assertSame('Acme\\Request', ContainerBindingMapProvider::lookup('datatables.request'));
    }

    #[Test]
    public function harvests_closure_factory_with_setup_then_return(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->singleton('builder', function ($app) {
                        $opts = ['k' => 'v'];
                        return new Builder($opts);
                    });
                }
            }
            class Builder { public function __construct(array $opts) {} }
        PHP);

        $this->assertSame('Acme\\Builder', ContainerBindingMapProvider::lookup('builder'));
    }

    #[Test]
    public function harvests_bindings_nested_in_if_block(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    if (true) {
                        $this->app->bind('alpha', Alpha::class);
                    }
                }
            }
            class Alpha {}
        PHP);

        $this->assertSame('Acme\\Alpha', ContainerBindingMapProvider::lookup('alpha'));
    }

    #[Test]
    public function harvests_scoped_binding(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->scoped('per-request', PerRequest::class);
                }
            }
            class PerRequest {}
        PHP);

        $this->assertSame('Acme\\PerRequest', ContainerBindingMapProvider::lookup('per-request'));
    }

    #[Test]
    public function harvests_bindif_binding(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->bindIf('maybe', MaybeImpl::class);
                }
            }
            class MaybeImpl {}
        PHP);

        $this->assertSame('Acme\\MaybeImpl', ContainerBindingMapProvider::lookup('maybe'));
    }

    #[Test]
    public function harvests_singletonif_binding(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->singletonIf('once', OnceImpl::class);
                }
            }
            class OnceImpl {}
        PHP);

        $this->assertSame('Acme\\OnceImpl', ContainerBindingMapProvider::lookup('once'));
    }

    #[Test]
    public function harvests_scopedif_binding(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->app->scopedIf('per-request-once', ScopedOnceImpl::class);
                }
            }
            class ScopedOnceImpl {}
        PHP);

        $this->assertSame('Acme\\ScopedOnceImpl', ContainerBindingMapProvider::lookup('per-request-once'));
    }

    #[Test]
    public function harvests_app_facade_static_call(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\Facades\App;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    App::singleton('via-facade', FacadeImpl::class);
                }
            }
            class FacadeImpl {}
        PHP);

        $this->assertSame('Acme\\FacadeImpl', ContainerBindingMapProvider::lookup('via-facade'));
    }

    #[Test]
    public function harvests_delegated_bindings_via_class_method_walk(): void
    {
        // imdhemy/laravel-in-app-purchases pattern: register() is a dispatcher that
        // calls private bind*() helpers where the real container::bind() calls live.
        // The harvester's per-method `harvest()` walker would miss these — we use
        // `harvestClassMethods()` here to walk every method body.
        $this->harvestClassMethods(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $this->bindFacades();
                    $this->bindConcretes();
                }
                private function bindFacades() {
                    $this->app->bind('subscription', SubscriptionClient::class);
                    $this->app->bind('product', ProductClient::class);
                }
                private function bindConcretes() {
                    $this->app->singleton(SomeContract::class, SomeImpl::class);
                }
            }
            class SubscriptionClient {}
            class ProductClient {}
            class SomeContract {}
            class SomeImpl {}
        PHP);

        $this->assertSame('Acme\\SubscriptionClient', ContainerBindingMapProvider::lookup('subscription'));
        $this->assertSame('Acme\\ProductClient', ContainerBindingMapProvider::lookup('product'));
    }

    #[Test]
    public function harvests_app_helper_receiver(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    app()->singleton('helper.alias', HelperImpl::class);
                }
            }
            class HelperImpl {}
        PHP);

        $this->assertSame('Acme\\HelperImpl', ContainerBindingMapProvider::lookup('helper.alias'));
    }

    #[Test]
    public function ignores_dynamic_accessor(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $name = 'dynamic';
                    $this->app->bind($name, Impl::class);
                }
            }
            class Impl {}
        PHP);

        $this->assertNull(ContainerBindingMapProvider::lookup('dynamic'));
    }

    #[Test]
    public function ignores_unrecognised_concrete_shape(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $maker = $this->resolveMaker();
                    $this->app->bind('foo', $maker);
                }
                private function resolveMaker(): string { return 'never'; }
            }
        PHP);

        $this->assertNull(ContainerBindingMapProvider::lookup('foo'));
    }

    #[Test]
    public function ignores_calls_on_non_container_receiver(): void
    {
        $this->harvestRegister(<<<'PHP'
            namespace Acme;
            use Illuminate\Support\ServiceProvider;
            class Provider extends ServiceProvider {
                public function register() {
                    $other = new SomeOther();
                    $other->bind('not.container', Impl::class);
                }
            }
            class SomeOther { public function bind($a, $b) {} }
            class Impl {}
        PHP);

        $this->assertNull(ContainerBindingMapProvider::lookup('not.container'));
    }

    /**
     * Parses a PHP source snippet, runs the NameResolver pass (so `Foo::class`
     * expressions get a `resolvedName` attribute exactly like under Psalm's
     * scan phase), locates the `register()` method body, and feeds it to the
     * harvester. Each test asserts the resulting map state.
     */
    private function harvestRegister(string $source): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse("<?php\n" . $source);
        $this->assertIsArray($stmts, 'fixture failed to parse');

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        /** @var list<Node> $resolved */
        $resolved = $traverser->traverse($stmts);

        $registerStmts = $this->findRegisterStmts($resolved);
        $this->assertNotNull($registerStmts, 'fixture must declare a register() method');

        ProviderBindingHarvester::harvest($registerStmts);
    }

    /**
     * Variant of {@see harvestRegister} that hands the whole `Class_` to
     * `harvestClassMethods()`, exercising the per-class entry point used by
     * `BootTimeProviderHarvester` for real provider files. Use this when the
     * fixture's bindings live in methods other than `register()` (the common
     * delegated-binding pattern in third-party providers).
     */
    private function harvestClassMethods(string $source): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse("<?php\n" . $source);
        $this->assertIsArray($stmts, 'fixture failed to parse');

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        /** @var list<Node> $resolved */
        $resolved = $traverser->traverse($stmts);

        $class = $this->findClassNode($resolved);
        $this->assertNotNull($class, 'fixture must declare at least one class');

        ProviderBindingHarvester::harvestClassMethods($class);
    }

    /**
     * @param list<Node> $stmts
     */
    private function findClassNode(array $stmts): ?Class_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $found = $this->findClassNode($stmt->stmts);
                if ($found instanceof \PhpParser\Node\Stmt\Class_) {
                    return $found;
                }

                continue;
            }

            if ($stmt instanceof Class_) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * @param list<Node> $stmts
     * @return list<Node\Stmt>|null
     */
    private function findRegisterStmts(array $stmts): ?array
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                $found = $this->findRegisterStmts($stmt->stmts);
                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if ($stmt instanceof Class_) {
                $method = $stmt->getMethod('register');
                if ($method instanceof ClassMethod && $method->stmts !== null) {
                    return $method->stmts;
                }
            }
        }

        return null;
    }
}
