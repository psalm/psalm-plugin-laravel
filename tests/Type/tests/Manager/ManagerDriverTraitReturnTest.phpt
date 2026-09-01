--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * A trait-provided creator's declaring class is the TRAIT, not the manager
 * that composes it. Expanding `self` against the trait would leak the trait's
 * own name as the inferred type; it must resolve against the composing class
 * instead (#1392).
 */
trait CreatesUpsDriver
{
    protected function createUpsDriver(): self
    {
        return $this;
    }
}

class TraitDriverManager extends Manager
{
    use CreatesUpsDriver;

    #[\Override]
    public function getDefaultDriver()
    {
        return 'ups';
    }
}

function trait_creator_resolves_to_the_composing_class(TraitDriverManager $manager): void
{
    $_ups = $manager->driver('ups');
    /** @psalm-check-type-exact $_ups = TraitDriverManager */
}
?>
--EXPECTF--
