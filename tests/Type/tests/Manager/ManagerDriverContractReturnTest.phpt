--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * A creator method's DECLARED return type may be a contract, wider than the
 * concrete instance actually returned in its body. The narrowing must honour
 * the declared type, not the runtime concrete class (#1392).
 */
interface FooContract
{
}

class ConcreteFoo implements FooContract
{
}

class ContractManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): FooContract
    {
        return new ConcreteFoo();
    }
}

function contract_injected(ContractManager $manager): void
{
    $_foo = $manager->driver('foo');
    /** @psalm-check-type-exact $_foo = FooContract */
}
?>
--EXPECTF--
