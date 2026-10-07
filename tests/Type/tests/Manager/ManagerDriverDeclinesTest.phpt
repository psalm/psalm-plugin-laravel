--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Manager;

/**
 * Every case the handler must decline on (return null, never a guessed type),
 * leaving Laravel's own declared `mixed` in place (#1392).
 */
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

class VoidCreatorManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    protected function createFooDriver(): void
    {
    }
}

// Overrides createDriver() itself (Laravel's own ChannelManager/ImageManager
// do this) — create{X}Driver() lookup no longer proves what runs.
class OverriddenCreateDriverManager extends Manager
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

    #[\Override]
    protected function createDriver($driver)
    {
        return new DeclineFooDriver();
    }
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

function void_creator(VoidCreatorManager $manager): void
{
    $_void = $manager->driver('foo');
    /** @psalm-check-type-exact $_void = mixed */
}

function create_driver_overridden(OverriddenCreateDriverManager $manager, string $name): void
{
    $_overridden = $manager->driver('foo');
    /** @psalm-check-type-exact $_overridden = mixed */

    // The override guard runs before the creator-union fallback too.
    $_overriddenDynamic = $manager->driver($name);
    /** @psalm-check-type-exact $_overriddenDynamic = mixed */
}

// Non-literal default: the creator-union fallback runs, but one `: void` creator
// among typed ones makes the whole union unprovable.
class VoidAmongTypedManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return (string) $this->config->get('x');
    }

    protected function createFooDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }

    protected function createBarDriver(): void
    {
    }
}

class UntypedCreatorManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return (string) $this->config->get('x');
    }

    protected function createFooDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }

    protected function createBarDriver()
    {
        return new DeclineFooDriver();
    }
}

function fallback_void_creator(VoidAmongTypedManager $manager): void
{
    $_fallbackVoid = $manager->driver();
    /** @psalm-check-type-exact $_fallbackVoid = mixed */
}

function fallback_untyped_creator(UntypedCreatorManager $manager): void
{
    $_fallbackUntyped = $manager->driver();
    /** @psalm-check-type-exact $_fallbackUntyped = mixed */
}

// A private creator is invoked from Manager's scope: PHP routes the call to
// Manager::__call(), so its declared return type proves nothing about the result.
class PrivateCreatorManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return (string) $this->config->get('x');
    }

    protected function createFooDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }

    private function createSecretDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }
}

class NoCreatorDynamicManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return (string) $this->config->get('x');
    }
}

class NeverCreatorManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return (string) $this->config->get('x');
    }

    protected function createFooDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }

    protected function createBarDriver(): never
    {
        throw new \LogicException();
    }
}

function private_creator(PrivateCreatorManager $manager): void
{
    $_privateFallback = $manager->driver();
    /** @psalm-check-type-exact $_privateFallback = mixed */

    $_privateLiteral = $manager->driver('secret');
    /** @psalm-check-type-exact $_privateLiteral = mixed */
}

function fallback_no_creator(NoCreatorDynamicManager $manager): void
{
    $_fallbackNone = $manager->driver();
    /** @psalm-check-type-exact $_fallbackNone = mixed */
}

function fallback_never_creator(NeverCreatorManager $manager): void
{
    $_fallbackNever = $manager->driver();
    /** @psalm-check-type-exact $_fallbackNever = mixed */
}

// PHP's method_exists() still finds a PRIVATE creator declared on an ancestor (the
// child's own method table omits it), and Manager forwards the call via __call().
abstract class PrivateCreatorParentManager extends Manager
{
    private function createSecretDriver(): string
    {
        return 'secret';
    }
}

final class AncestorPrivateCreatorManager extends PrivateCreatorParentManager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return (string) $this->config->get('x');
    }

    protected function createFooDriver(): DeclineFooDriver
    {
        return new DeclineFooDriver();
    }
}

// Templates nested inside the return type (`list<U>`) are still unbound.
class NestedTemplateCreatorManager extends Manager
{
    #[\Override]
    public function getDefaultDriver()
    {
        return 'foo';
    }

    /**
     * @template U of DeclineFooDriver
     * @return list<U>
     */
    protected function createFooDriver(): array
    {
        return [];
    }
}

function ancestor_private_creator(AncestorPrivateCreatorManager $manager, string $name): void
{
    $_ancestorPrivate = $manager->driver($name);
    /** @psalm-check-type-exact $_ancestorPrivate = mixed */
}

function nested_template_creator(NestedTemplateCreatorManager $manager, string $name): void
{
    $_nestedLiteral = $manager->driver('foo');
    /** @psalm-check-type-exact $_nestedLiteral = mixed */

    $_nestedFallback = $manager->driver($name);
    /** @psalm-check-type-exact $_nestedFallback = mixed */
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
MissingReturnType on line %d: Method UntypedCreatorManager::createBarDriver does not have a return type, expecting DeclineFooDriver
