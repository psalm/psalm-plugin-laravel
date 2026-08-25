--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * A creator's `: static` return is anchored to the DECLARING class but must
 * resolve against the CALLED class — a raw pass-through of the declared type
 * leaves `static` unexpanded, and a caller checking the result against the
 * concrete subclass sees a bogus `StBase&static` instead of `StChild` (#1392).
 */
class StBase extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): static
    {
        return $this;
    }
}

class StChild extends StBase
{
}

function static_return_narrows_to_the_called_class(StChild $manager): void
{
    $_foo = $manager->driver('foo');
    /** @psalm-check-type-exact $_foo = StChild */
}
?>
--EXPECTF--
