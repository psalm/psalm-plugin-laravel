--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * When the driver name cannot be resolved statically (config-driven default,
 * non-literal argument), `driver()` narrows to the union of every
 * `create{X}Driver()` declared return type on the receiver (#1738).
 */
interface SmsDriver
{
}

class LogSmsDriver implements SmsDriver
{
}

class VonageSmsDriver implements SmsDriver
{
}

// The reported shape: a config-driven default, creators sharing one interface.
final class SmsManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return (string) $this->config->get('sms.default');
    }

    protected function createLogDriver(): SmsDriver
    {
        return new LogSmsDriver();
    }

    protected function createVonageDriver(): SmsDriver
    {
        return new VonageSmsDriver();
    }
}

class UnionAlphaDriver
{
}

class UnionBetaDriver
{
}

class DistinctCreatorsManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'alpha';
    }

    protected function createAlphaDriver(): UnionAlphaDriver
    {
        return new UnionAlphaDriver();
    }

    protected function createBetaDriver(): UnionBetaDriver
    {
        return new UnionBetaDriver();
    }
}

class UnionParentDriver
{
}

class UnionChildDriver
{
}

abstract class ParentCreatorManager extends Manager
{
    protected function createParentDriver(): UnionParentDriver
    {
        return new UnionParentDriver();
    }
}

class InheritingCreatorManager extends ParentCreatorManager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'parent';
    }

    protected function createChildDriver(): UnionChildDriver
    {
        return new UnionChildDriver();
    }
}

function config_driven_default(SmsManager $manager): void
{
    $_default = $manager->driver();
    /** @psalm-check-type-exact $_default = SmsDriver */
}

// A conditional body reaches either literal; only a single `return '<literal>';` is knowable.
class ConditionalDefaultManager extends Manager
{
    protected bool $useBeta = false;

    #[\Override]
    public function getDefaultDriver()
    {
        if ($this->useBeta) {
            return 'beta';
        }

        return 'alpha';
    }

    protected function createAlphaDriver(): UnionAlphaDriver
    {
        return new UnionAlphaDriver();
    }

    protected function createBetaDriver(): UnionBetaDriver
    {
        return new UnionBetaDriver();
    }
}

function conditional_default(ConditionalDefaultManager $manager): void
{
    $_conditional = $manager->driver();
    /** @psalm-check-type-exact $_conditional = UnionAlphaDriver|UnionBetaDriver */
}

function distinct_creators_non_literal_argument(DistinctCreatorsManager $manager, string $name): void
{
    $_dynamic = $manager->driver($name);
    /** @psalm-check-type-exact $_dynamic = UnionAlphaDriver|UnionBetaDriver */
}

function inherited_creator_joins_own(InheritingCreatorManager $manager, string $name): void
{
    $_inherited = $manager->driver($name);
    /** @psalm-check-type-exact $_inherited = UnionChildDriver|UnionParentDriver */
}

/** @template T of SmsDriver */
abstract class GenericCreatorManager extends Manager
{
    /** @return T */
    protected function createFooDriver(): SmsDriver
    {
        throw new \LogicException();
    }
}

/** @extends GenericCreatorManager<LogSmsDriver> */
final class ConcreteGenericCreatorManager extends GenericCreatorManager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }
}

/** The creator's template resolves through the receiver's `@extends`, as a direct call would. */
function inherited_template_return(ConcreteGenericCreatorManager $manager, string $name): void
{
    $_literal = $manager->driver('foo');
    /** @psalm-check-type-exact $_literal = LogSmsDriver */

    $_fallback = $manager->driver($name);
    /** @psalm-check-type-exact $_fallback = LogSmsDriver */
}

/** A receiver without `@extends` arguments resolves `T` to its bound, never leaking `T`. */
function unbound_template_uses_bound(GenericCreatorManager $manager): void
{
    $_unbound = $manager->driver('foo');
    /** @psalm-check-type-exact $_unbound = SmsDriver */
}
?>
--EXPECTF--
