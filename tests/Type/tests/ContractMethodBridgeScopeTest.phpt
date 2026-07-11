--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Container\Container;

/**
 * Policy flip, pinned intentionally (#1230): Container is an
 * `Illuminate\Contracts\*` interface, so the dynamic walk resolves it via the
 * booted container and bridges its concrete-only wrap() — no
 * UndefinedInterfaceMethod. Under the old allow-list this raised (Container
 * wasn't listed); the dynamic walk supersedes that decision.
 */
function container_contract_bridges(Container $container): void
{
    $container->wrap(static fn(): null => null);
}

/**
 * Guard: namespace-scoped, not a blind interface walk. A user-land interface
 * outside `Illuminate\Contracts\*` is never offered to the container's make() —
 * MyRepository declares no methods, so a call on it must still raise
 * UndefinedInterfaceMethod, proving the namespace gate holds.
 */
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
