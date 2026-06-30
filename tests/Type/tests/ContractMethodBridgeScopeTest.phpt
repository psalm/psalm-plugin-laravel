--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Container\Container;

/**
 * Guard: allow-list scoped, not a blind contract walk. The Container contract is
 * off the allow-list (and is a parent of the Application contract), so its
 * concrete-only wrap() must still raise UndefinedInterfaceMethod — proving methods
 * don't leak onto arbitrary contracts. #1108.
 */
function scope_guard(Container $container): void
{
    $container->wrap(static fn(): null => null);
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Illuminate\Contracts\Container\Container::wrap does not exist
