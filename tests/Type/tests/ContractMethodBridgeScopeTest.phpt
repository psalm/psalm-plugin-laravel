--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Container\Container;

/**
 * Policy flip, pinned intentionally (#1230): the dynamic walk bridges Container's
 * concrete-only wrap(). Under the old allow-list this raised UndefinedInterfaceMethod.
 */
function container_contract_bridges(Container $container): void
{
    $container->wrap(static fn(): null => null);
}

// Namespace gate: a user-land interface outside Illuminate\Contracts never bridges.
interface MyRepository
{
}

function scope_guard(MyRepository $repository): void
{
    $repository->find(1);
}
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method App\MyRepository::find does not exist
