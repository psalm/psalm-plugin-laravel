--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * Both the default-driver body and creator live on the trait. The shared AST
 * resolver must find getDefaultDriver() there, while creator return expansion
 * must still anchor `self` to the composing manager rather than leak the trait's
 * own name as the inferred type (#1392, #1411).
 */
trait CreatesUpsDriver
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'ups';
    }

    protected function createUpsDriver(): self
    {
        return $this;
    }
}

class TraitDriverManager extends Manager
{
    use CreatesUpsDriver;
}

function trait_creator_resolves_to_the_composing_class(TraitDriverManager $manager): void
{
    $_ups = $manager->driver();
    /** @psalm-check-type-exact $_ups = TraitDriverManager */
}
?>
--EXPECTF--
