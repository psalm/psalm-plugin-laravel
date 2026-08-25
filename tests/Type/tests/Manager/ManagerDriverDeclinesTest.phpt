--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * Every case the handler must decline on (return null, never a guessed type),
 * leaving Laravel's own declared `mixed` in place (#1392).
 */
enum DriverEnum
{
    case Foo;
}

class DeclineFooDriver
{
}

class DeclineManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }
}

class NoCreatorManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'bar';
    }
}

class ComputedDefaultManager extends Manager
{
    protected string $fallback = 'foo';

    #[\Override]
    public function getDefaultDriver()
    {
        return $this->fallback;
    }

    protected function createFooDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }
}

function dynamic_name(DeclineManager $manager, string $name): void
{
    $_dynamic = $manager->driver($name);
    /** @psalm-check-type-exact $_dynamic = mixed */
}

function missing_creator(DeclineManager $manager): void
{
    $_missing = $manager->driver('bar');
    /** @psalm-check-type-exact $_missing = mixed */
}

function no_creator_at_all(NoCreatorManager $manager): void
{
    $_none = $manager->driver();
    /** @psalm-check-type-exact $_none = mixed */
}

function computed_default(ComputedDefaultManager $manager): void
{
    $_computed = $manager->driver();
    /** @psalm-check-type-exact $_computed = mixed */
}

function enum_argument(DeclineManager $manager, DriverEnum $driver): void
{
    $_enum = $manager->driver($driver);
    /** @psalm-check-type-exact $_enum = mixed */
}

function abstract_receiver(Manager $manager): void
{
    $_abstract = $manager->driver('foo');
    /** @psalm-check-type-exact $_abstract = mixed */
}

function union_receiver(DeclineManager|NoCreatorManager $manager): void
{
    // 'baz' matches no create*Driver() on either branch of the union.
    $_union = $manager->driver('baz');
    /** @psalm-check-type-exact $_union = mixed */
}
?>
--EXPECTF--
