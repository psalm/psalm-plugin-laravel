<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Application;

use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Application\ContractMethodBridgeHandler;

/**
 * Unit guard for the resolution seam in {@see ContractMethodBridgeHandler}.
 * A throwing / unbound / non-object binding must resolve to null — that's what
 * stops the handler bridging methods onto a contract the app can't back with a
 * real concrete. A successful resolution must return the exact resolved
 * class-string, since that string drives the subsequent storage lookup.
 */
#[CoversClass(ContractMethodBridgeHandler::class)]
final class ContractMethodBridgeHandlerTest extends TestCase
{
    #[Test]
    public function it_treats_a_throwing_binding_as_unresolved(): void
    {
        $container = new Container();
        $container->bind('service', static function (): never {
            throw new \RuntimeException('cannot build');
        });

        $this->assertNull(ContractMethodBridgeHandler::resolveConcreteClass($container, 'service'));
    }

    #[Test]
    public function it_treats_an_unbound_abstract_as_unresolved(): void
    {
        $container = new Container();

        // make() on an unbound, non-instantiable abstract throws inside the handler
        // and is swallowed — nothing gets widened.
        $this->assertNull(ContractMethodBridgeHandler::resolveConcreteClass($container, 'not.bound'));
    }

    #[Test]
    public function it_rejects_a_non_object_resolution(): void
    {
        $container = new Container();
        $container->instance('scalar', 'a string, not an object');

        $this->assertNull(ContractMethodBridgeHandler::resolveConcreteClass($container, 'scalar'));
    }

    #[Test]
    public function it_returns_the_exact_class_string_of_the_resolved_object(): void
    {
        $container = new Container();
        $container->instance('contract', new \stdClass());

        $this->assertSame(\stdClass::class, ContractMethodBridgeHandler::resolveConcreteClass($container, 'contract'));
    }
}
