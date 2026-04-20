--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;

/**
 * Container::tag() is variadic on the SECOND argument — the source uses
 * array_slice(func_get_args(), 1) to collect tag names. Both the concrete
 * Illuminate\Container\Container and the Illuminate\Contracts\Container\Container
 * interface are stubbed.
 */
function container_tag_variadic_concrete(Container $container): void
{
    // tag() returns void. Exercise all accepted call shapes.
    $container->tag('Foo', 'reports');
    $container->tag('Foo', 'reports', 'analytics', 'exports');
    $container->tag('Foo', ['reports', 'analytics']);

    // $abstracts also accepts an array (documented Laravel behaviour).
    $container->tag(['Foo', 'Bar'], 'reports');
    $container->tag(['Foo', 'Bar'], 'reports', 'analytics');
    $container->tag(['Foo', 'Bar'], ['reports', 'analytics']);
}

function container_tag_variadic_contract(ContainerContract $container): void
{
    $container->tag('Foo', 'reports');
    $container->tag('Foo', 'reports', 'analytics');
    $container->tag('Foo', ['reports', 'analytics']);
    $container->tag(['Foo', 'Bar'], 'reports', 'analytics');
}
?>
--EXPECTF--
