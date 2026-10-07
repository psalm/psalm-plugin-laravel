--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION — the creator visibility check reads the trait method's ORIGINAL
 * visibility, not the composing class's `trait_visibility_map` (see the accepted
 * imprecision paragraph in src/Handlers/Support/ManagerDriverHandler.php):
 * - `use T { createFooDriver as private; }` keeps the declared type, although at
 *   runtime Manager reaches the now-private creator through __call() (FP).
 * - `use T { createBarDriver as public; }` declines a creator that is reachable (FN).
 * Pins the CURRENT behavior. #1738
 */

use Illuminate\Support\Manager;

class AdaptedFooDriver
{
}

class AdaptedBarDriver
{
}

trait ProvidesAdaptedCreators
{
    protected function createFooDriver(): AdaptedFooDriver
    {
        return new AdaptedFooDriver();
    }

    private function createBarDriver(): AdaptedBarDriver
    {
        return new AdaptedBarDriver();
    }
}

class AdaptedPrivateManager extends Manager
{
    use ProvidesAdaptedCreators {
        createFooDriver as private;
    }

    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }
}

class AdaptedPublicManager extends Manager
{
    use ProvidesAdaptedCreators {
        createBarDriver as public;
    }

    #[\Override]
    public function getDefaultDriver()
    {
        return 'bar';
    }
}

function adapted_to_private(AdaptedPrivateManager $manager): void
{
    $_private = $manager->driver();
    /** @psalm-check-type-exact $_private = AdaptedFooDriver */
}

function adapted_to_public(AdaptedPublicManager $manager): void
{
    $_public = $manager->driver();
    /** @psalm-check-type-exact $_public = mixed */
}
?>
--EXPECTF--
