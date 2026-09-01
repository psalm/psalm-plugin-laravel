--FILE--
<?php declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Manager;

/**
 * Every other fixture in this suite lives in the global namespace, so
 * findMethod()'s `Stmt\Namespace_` branch (needed to read getDefaultDriver()'s
 * body for the no-arg call) is never exercised there. A real Laravel app always
 * namespaces its classes, so this pins that branch specifically (#1392).
 */
class NamespacedFooDriver
{
}

class NamespacedManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): NamespacedFooDriver
    {
        return new NamespacedFooDriver();
    }
}

function namespaced_default_driver_resolves(NamespacedManager $manager): void
{
    $_default = $manager->driver();
    /** @psalm-check-type-exact $_default = \App\Services\NamespacedFooDriver */
}
?>
--EXPECTF--
