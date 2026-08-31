--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION — Psalm's MethodReturnTypeProviderEvent dispatches by exact
 * called-class FQCN first, falling back to the DECLARING class only when the
 * called class does not itself declare the method (see
 * src/Handlers/Support/ManagerDriverHandler.php docblock). A subclass that
 * overrides driver() therefore never reaches this handler's registration on
 * Illuminate\Support\Manager — an accepted false negative, not unsound output:
 * the override's own declared return type (here, untyped `mixed`) stays in
 * place rather than the plugin guessing at a narrower type. #1392
 */

use Illuminate\Support\Manager;

class OverriddenFoo
{
}

class OverridingManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    // Overrides driver() itself, so the base Manager::driver() dispatch path
    // never fires — Psalm resolves the call against THIS declaration.
    #[\Override]
    public function driver($driver = null)
    {
        return parent::driver($driver);
    }

    protected function createFooDriver(): OverriddenFoo
    {
        return new OverriddenFoo();
    }
}

function overridden_injected(OverridingManager $manager): void
{
    $_driver = $manager->driver('foo');
    /** @psalm-check-type-exact $_driver = mixed */
}
?>
--EXPECTF--
