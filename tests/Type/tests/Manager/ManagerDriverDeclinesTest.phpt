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

class UHasFooDriver
{
}

class UHasFoo extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): UHasFooDriver
    {
        return new UHasFooDriver();
    }
}

// No createFooDriver() at all — the branch of the union below that must decline.
class UNoFoo extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
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

/**
 * Pins the method-name gate. `getDefaultDriver()` is unusable for this: it is
 * ABSTRACT on Manager, so every fixture here overrides it, which makes IT the
 * declaring class for that call — dispatch never reaches a handler registered
 * on `Manager::class` at all, gate or no gate. `extend()` is never overridden
 * here, so `Manager` stays the declaring class, and its first argument is a
 * driver-name-shaped literal string — exactly `driver()`'s own call shape.
 * Without the gate, `extend('foo', ...)` would run through the SAME
 * driver-resolution logic (creator lookup succeeds: DeclineManager DOES define
 * createFooDriver()) and incorrectly narrow to `DeclineFooDriver` instead of
 * `extend()`'s real `@return $this`.
 */
function other_method_untouched(DeclineManager $manager): void
{
    $_other = $manager->extend('foo', static fn () => new DeclineFooDriver());
    /** @psalm-check-type-exact $_other = DeclineManager&static */
}

/**
 * Non-vacuous union receiver: `UHasFoo` DOES define `createFooDriver()` and
 * would narrow on its own (see ManagerDriverLiteralTest); `UNoFoo` does not.
 * Dispatch resolves each atomic branch of the union independently, so a
 * genuine per-branch decline (not two branches that were already going to
 * decline regardless) still degrades the combined type to `mixed`.
 *
 * There is no dedicated "union receiver" or "abstract receiver" gate in the
 * handler — an abstract `Manager`-typed receiver falls through to this exact
 * same missing-creator lookup (the abstract class never declares any
 * `create*Driver()`), so it pins nothing beyond `missing_creator` /
 * `no_creator_at_all` above and was dropped rather than kept vacuous.
 */
function union_receiver(UHasFoo|UNoFoo $manager): void
{
    $_union = $manager->driver('foo');
    /** @psalm-check-type-exact $_union = mixed */
}
?>
--EXPECTF--
