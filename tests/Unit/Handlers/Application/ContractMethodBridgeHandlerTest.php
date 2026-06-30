<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Application;

use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Application\ContractMethodBridgeHandler;

/**
 * Unit guard for the resolution gate in {@see ContractMethodBridgeHandler}.
 * A throwing / unbound / non-object / wrong-concrete binding must NOT count as
 * fulfilled — that's what stops the handler bridging methods onto a contract the
 * app doesn't back with the mapped concrete.
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

        $this->assertFalse(ContractMethodBridgeHandler::containerResolvesConcrete($container, 'service', \stdClass::class));
    }

    #[Test]
    public function it_treats_an_unbound_abstract_as_unresolved(): void
    {
        $container = new Container();

        // make() on an unbound, non-instantiable abstract throws inside the handler
        // and is swallowed — nothing gets widened.
        $this->assertFalse(ContractMethodBridgeHandler::containerResolvesConcrete($container, 'not.bound', \stdClass::class));
    }

    #[Test]
    public function it_rejects_a_non_object_resolution(): void
    {
        $container = new Container();
        $container->instance('scalar', 'a string, not an object');

        $this->assertFalse(ContractMethodBridgeHandler::containerResolvesConcrete($container, 'scalar', \stdClass::class));
    }

    #[Test]
    public function it_rejects_an_object_of_the_wrong_concrete(): void
    {
        $container = new Container();
        $container->instance('contract', new \stdClass());

        $this->assertFalse(ContractMethodBridgeHandler::containerResolvesConcrete($container, 'contract', Container::class));
    }

    #[Test]
    public function it_accepts_an_object_of_the_expected_concrete(): void
    {
        $container = new Container();
        $container->instance('contract', new \stdClass());

        $this->assertTrue(ContractMethodBridgeHandler::containerResolvesConcrete($container, 'contract', \stdClass::class));
    }
}
