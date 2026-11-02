--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountOverridable;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

// A kept count is an answer about the method the receiver's class declares. A subtype that is still `F<null>`
// to the caller may dispatch to an override, so the answer needs every known implementation to keep the count.

final class User extends Model
{
}

/** @extends Factory<User> */
class F extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function nativeSelf(): self
    {
        return $this->state([]);
    }

    /** @return self */
    public function docSelf()
    {
        return $this->state([]);
    }

    public function bare(): Factory
    {
        return $this->state([]);
    }

    public function own(): F
    {
        return $this->state([]);
    }

    /** @return static */
    public function staticState()
    {
        return $this->state([]);
    }

    /** @return $this */
    public function thisState()
    {
        return $this;
    }

    public function viaState(): self
    {
        return $this->set('k', 1);
    }

    public function finalSelf(): self
    {
        return $this->state([]);
    }
}

class OverrideChild extends F
{
    #[\Override]
    public function nativeSelf(): F
    {
        return $this->count(2);
    }

    /** @return self */
    #[\Override]
    public function docSelf()
    {
        return $this->count(2);
    }

    #[\Override]
    public function bare(): Factory
    {
        return $this->count(2);
    }

    #[\Override]
    public function own(): F
    {
        return $this->count(2);
    }

    /** @return static */
    #[\Override]
    public function staticState()
    {
        return $this->count(2);
    }

    /**
     * @return $this
     * @psalm-suppress InvalidReturnStatement
     */
    #[\Override]
    public function thisState()
    {
        return $this->count(2);
    }
}

// A descendant that keeps the count in its own override leaves the answer alone; one that takes over `state()` does not.
class KeepingChild extends F
{
    #[\Override]
    public function viaState(): self
    {
        return $this->state(['kept' => true]);
    }
}

/** @param F<null> $f */
function parentReceiver(F $f): void
{
    $_parentSelf = $f->nativeSelf()->make();
    /** @psalm-check-type-exact $_parentSelf = User|Collection<int, User> */;

    $_parentDoc = $f->docSelf()->make();
    /** @psalm-check-type-exact $_parentDoc = User|Collection<int, User> */;

    $_parentBare = $f->bare()->make();
    /** @psalm-check-type-exact $_parentBare = Model|Collection<int, Model> */;

    $_parentOwn = $f->own()->make();
    /** @psalm-check-type-exact $_parentOwn = User|Collection<int, User> */;

    $_parentStatic = $f->staticState()->make();
    /** @psalm-check-type-exact $_parentStatic = User|Collection<int, User> */;

    $_parentThis = $f->thisState()->make();
    /** @psalm-check-type-exact $_parentThis = User|Collection<int, User> */;

    $_parentViaState = $f->viaState()->make();
    /** @psalm-check-type-exact $_parentViaState = User */;
}

$_direct = OverrideChild::new()->nativeSelf()->make();
/** @psalm-check-type-exact $_direct = User|Collection<int, User> */;

// ----- A descendant overriding a Laravel method the body calls is another dispatch target too -----
/** @extends Factory<User> */
class Base extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    final public function sealed(): self
    {
        return $this->state([]);
    }
}

class StateOverride extends Base
{
    /**
     * @param (callable(array<string, mixed>, Model|null): array<string, mixed>)|array<string, mixed> $state
     * @return static
     * @psalm-suppress MoreSpecificReturnType
     */
    #[\Override]
    public function state($state)
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return $this->count(2);
    }
}

/** @param Base<null> $f */
function sealedReceiver(Base $f): void
{
    $_sealed = $f->sealed()->make();
    /** @psalm-check-type-exact $_sealed = User|Collection<int, User> */;
}

// ----- Controls: no known descendant overrides it, or the receiver is final -----
class Lonely extends F
{
    public function lonelyOnly(): string
    {
        return 'lonely';
    }
}

final class Sealed extends F
{
    public function sealedOnly(): string
    {
        return 'sealed';
    }
}

/** @param Lonely<null> $f */
function lonely(Lonely $f): void
{
    $_lonelyChild = $f->finalSelf()->lonelyOnly();
    /** @psalm-check-type-exact $_lonelyChild = string */;

    $_lonely = $f->finalSelf()->make();
    /** @psalm-check-type-exact $_lonely = User */;
}

/** @param Sealed<null> $f */
function sealedFinal(Sealed $f): void
{
    $_final = $f->nativeSelf()->make();
    /** @psalm-check-type-exact $_final = User */;

    $_finalChildOnly = $f->bare()->sealedOnly();
    /** @psalm-check-type-exact $_finalChildOnly = string */;
}

$_finalCounted = Sealed::times(2)->nativeSelf()->make();
/** @psalm-check-type-exact $_finalCounted = Collection<int, User> */;

// ----- Inert arguments: only syntactically inert leaves -----
final class Other
{
    public const KIND = 'other';
}

/** @extends Factory<User> */
final class Args extends Factory
{
    private string $label = 'x';

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function touch(): self
    {
        $this->count = 2;

        return $this;
    }

    public function okClass(): self
    {
        return $this->set('k', Other::class);
    }

    public function okConst(): self
    {
        return $this->set('k', Other::KIND);
    }

    public function okConstFetch(): self
    {
        return $this->set('k', \PHP_INT_MAX);
    }

    public function okNegative(): self
    {
        return $this->set('k', -1);
    }

    public function okArray(): self
    {
        return $this->state(['k' => Other::class, 'n' => [1, 2.5, 'x', true, null], 'c' => fn (): int => 1]);
    }

    public function badThisProperty(): self
    {
        return $this->set('k', $this->label);
    }

    public function badDynamicClass(): self
    {
        return $this->set('k', ($this->touch())::class);
    }

    /** @return static */
    public function badDynamicStatic()
    {
        return $this->set('k', ($this->touch())::class);
    }

    public function badDynamicConst(): self
    {
        return $this->set('k', ($this->touch())::KIND);
    }

    public function badNew(): self
    {
        return $this->set('k', new \stdClass());
    }

    public function badCall(): self
    {
        return $this->set('k', \strlen('x'));
    }

    public function badPropertyBase(): self
    {
        return $this->set('k', $this->touch()->label);
    }

    public function badArrayItem(): self
    {
        return $this->state(['k' => $this->touch()]);
    }

    public function badNegativeCall(): self
    {
        return $this->set('k', -\strlen('x'));
    }
}

$_okClass = Args::times(3)->okClass()->make();
/** @psalm-check-type-exact $_okClass = Collection<int, User> */;

$_okConst = Args::times(3)->okConst()->make();
/** @psalm-check-type-exact $_okConst = Collection<int, User> */;

$_okConstFetch = Args::times(3)->okConstFetch()->make();
/** @psalm-check-type-exact $_okConstFetch = Collection<int, User> */;

$_okNegative = Args::times(3)->okNegative()->make();
/** @psalm-check-type-exact $_okNegative = Collection<int, User> */;

$_okArray = Args::times(3)->okArray()->make();
/** @psalm-check-type-exact $_okArray = Collection<int, User> */;

$_badThisProperty = Args::times(3)->badThisProperty()->make();
/** @psalm-check-type-exact $_badThisProperty = User|Collection<int, User> */;

$_badDynamicClass = Args::new()->badDynamicClass()->make();
/** @psalm-check-type-exact $_badDynamicClass = User|Collection<int, User> */;

$_badDynamicStatic = Args::new()->badDynamicStatic()->make();
/** @psalm-check-type-exact $_badDynamicStatic = User|Collection<int, User> */;

$_badDynamicConst = Args::new()->badDynamicConst()->make();
/** @psalm-check-type-exact $_badDynamicConst = User|Collection<int, User> */;

$_badNew = Args::times(3)->badNew()->make();
/** @psalm-check-type-exact $_badNew = User|Collection<int, User> */;

$_badCall = Args::times(3)->badCall()->make();
/** @psalm-check-type-exact $_badCall = User|Collection<int, User> */;

$_badPropertyBase = Args::new()->badPropertyBase()->make();
/** @psalm-check-type-exact $_badPropertyBase = User|Collection<int, User> */;

$_badArrayItem = Args::new()->badArrayItem()->make();
/** @psalm-check-type-exact $_badArrayItem = User|Collection<int, User> */;

$_badNegativeCall = Args::times(3)->badNegativeCall()->make();
/** @psalm-check-type-exact $_badNegativeCall = User|Collection<int, User> */;
?>
--EXPECTF--
