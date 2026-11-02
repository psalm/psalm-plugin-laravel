--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountUserMethod;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// A user method declared `static` / `$this` keeps the count only when its body provably does.

final class User extends Model
{
    use HasFactory;

    /** @var class-string<UserFactory> */
    protected static $factory = UserFactory::class;
}

/**
 * @extends Factory<User>
 * @method $this forAdmin()
 * @method $this hasPosts()
 */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    // ----- Positive rule: only `$this` or a chain of count-keeping Laravel methods -----
    /** @return static */
    public function admin()
    {
        return $this->state(['admin' => true]);
    }

    /** @return $this */
    public function sameInstance()
    {
        return $this;
    }

    public function nativeChain(): static
    {
        return $this->state(['a' => 1])->set('b', 2)->sequence(['c' => 3])->connection(null);
    }

    /** @return static */
    public function branches(bool $flag)
    {
        if ($flag) {
            return $this->state(['flag' => true]);
        }

        return $this;
    }

    /** @return static */
    public function magic()
    {
        return $this->forAdmin()->hasPosts();
    }

    // ----- Anything else makes the count unknown -----
    /** @return static */
    public function batch()
    {
        return $this->count(3);
    }

    /** @return static */
    public function sequenced()
    {
        return $this->forEachSequence(['x' => 1]);
    }

    /** @return static */
    public function resetCount()
    {
        return $this->count(null);
    }

    /**
     * @return static
     * @psalm-suppress UnsafeInstantiation
     */
    public function fresh()
    {
        return new static();
    }

    /** @return static */
    public function viaHelper()
    {
        return $this->helper();
    }

    /** @return static */
    private function helper()
    {
        return $this->count(3);
    }

    /** @return static */
    public function viaParent()
    {
        return parent::state([]);
    }

    /** @return static */
    public function viaClosure()
    {
        return (fn () => $this->count(3))();
    }

    /** @return static */
    public function mixedBranches(bool $flag)
    {
        if ($flag) {
            return $this->state([]);
        }

        return $this->count(3);
    }

    /**
     * @return static
     * @psalm-suppress MoreSpecificReturnType
     */
    public function viaWhen()
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return $this->when(true, static fn (self $f) => $f->count(3));
    }

    /** @return static */
    public function viaTimes()
    {
        return static::times(3);
    }

    // ----- The rule covers the WHOLE body: any statement besides `return <chain>;` makes the count unknown -----
    /** @return static */
    public function propertyWrite()
    {
        $this->count = 2;

        return $this;
    }

    /** @return static */
    public function assignsLocal()
    {
        $local = 1;

        return $this->set('a', $local);
    }

    /** @return static */
    public function statementCall()
    {
        $this->mutate();

        return $this;
    }

    /** @return static */
    public function unsets()
    {
        unset($this->count);

        return $this;
    }

    /** @return static */
    public function closureCall()
    {
        (function (): void {
            $this->count = 2;
        })->call($this);

        return $this;
    }

    /** @return static */
    public function argMutates()
    {
        return $this->set('a', $this->count = 2);
    }

    private function mutate(): void
    {
        $this->count = 2;
    }

    // A closure INSIDE state()/sequence() arguments runs on attributes, never on the factory.
    /** @return static */
    public function closureArgument()
    {
        return $this->state(fn (): array => ['a' => $this->count = 2])->sequence(static fn (): array => []);
    }

    // ----- A composite declared return is never rewritten when the body keeps the count -----
    /**
     * @template T
     * @param T $value
     * @return static|T
     */
    public function keepsOrValue($value)
    {
        return $this->state([]);
    }

    /**
     * @template T of int
     * @param T $value
     * @return static|T
     */
    public function mayReplace($value, bool $flag)
    {
        return $flag ? $this->count(2) : $value;
    }

    /** @return static|null */
    public function mayBeNull(bool $flag)
    {
        return $flag ? $this->count(2) : null;
    }

    // ----- `: self` / `: Factory` / the factory's own class: the RECEIVER type comes back when the body keeps the count -----
    public function nativeSelf(): self
    {
        return $this;
    }

    public function forOrg(string $org): self
    {
        return $this->state(fn (): array => ['org_id' => $org]);
    }

    /** @return self */
    public function docSelf()
    {
        return $this->state([]);
    }

    public function asBase(): Factory
    {
        return $this->state([]);
    }

    /** @return UserFactory */
    public function asOwnClass()
    {
        return $this->state([]);
    }

    public function selfBatch(): self
    {
        return $this->count(3);
    }

    public function baseBatch(): Factory
    {
        return $this->count(3);
    }
}

// An overridden Laravel method in the chain is no longer Laravel's, for the overriding class and for its
// ancestor's receivers alike (a `ChainBase<null>` may hold an `OverriddenSetFactory`).
/** @extends Factory<User> */
class ChainBase extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function nativeChain(): static
    {
        return $this->state(['a' => 1])->set('b', 2);
    }
}

final class OverriddenSetFactory extends ChainBase
{
    /**
     * @param string|int $key
     * @param mixed $value
     * @return static
     */
    #[\Override]
    public function set($key, $value)
    {
        return new static(3);
    }
}

/** @extends Factory<User> */
abstract class AbstractBase extends Factory
{
    /**
     * @return static
     * @psalm-impure
     */
    abstract public function declared();
}

final class ConcreteBase extends AbstractBase
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return static */
    #[\Override]
    public function declared()
    {
        return $this;
    }
}

$_admin = User::factory(3)->admin()->make();
/** @psalm-check-type-exact $_admin = Collection<int, User> */;

$_adminSingle = User::factory()->admin()->make();
/** @psalm-check-type-exact $_adminSingle = User */;

$_this = User::factory(3)->sameInstance()->make();
/** @psalm-check-type-exact $_this = Collection<int, User> */;

$_native = User::factory(3)->nativeChain()->make();
/** @psalm-check-type-exact $_native = Collection<int, User> */;

// Control flow is a statement besides `return`: the count is unknown.
$_branches = User::factory(3)->branches(true)->make();
/** @psalm-check-type-exact $_branches = User|Collection<int, User> */;

// `__call` serves `for*` / `has*` after the macro registry, so a macro of that name can change the count.
$_magic = User::factory(3)->magic()->make();
/** @psalm-check-type-exact $_magic = User|Collection<int, User> */;

$_batch = User::factory()->batch()->make();
/** @psalm-check-type-exact $_batch = User|Collection<int, User> */;

$_sequenced = User::factory()->sequenced()->make();
/** @psalm-check-type-exact $_sequenced = User|Collection<int, User> */;

$_resetCount = User::factory(3)->resetCount()->make();
/** @psalm-check-type-exact $_resetCount = User|Collection<int, User> */;

$_fresh = User::factory(3)->fresh()->make();
/** @psalm-check-type-exact $_fresh = User|Collection<int, User> */;

$_viaHelper = User::factory()->viaHelper()->make();
/** @psalm-check-type-exact $_viaHelper = User|Collection<int, User> */;

$_viaParent = User::factory()->viaParent()->make();
/** @psalm-check-type-exact $_viaParent = User|Collection<int, User> */;

$_viaClosure = User::factory()->viaClosure()->make();
/** @psalm-check-type-exact $_viaClosure = User|Collection<int, User> */;

$_mixed = User::factory(3)->mixedBranches(true)->make();
/** @psalm-check-type-exact $_mixed = User|Collection<int, User> */;

$_viaWhen = User::factory()->viaWhen()->make();
/** @psalm-check-type-exact $_viaWhen = User|Collection<int, User> */;

$_viaTimes = User::factory(3)->viaTimes()->make();
/** @psalm-check-type-exact $_viaTimes = User|Collection<int, User> */;

$_self = User::factory(3)->nativeSelf()->make();
/** @psalm-check-type-exact $_self = Collection<int, User> */;

$_selfSingle = User::factory()->nativeSelf()->make();
/** @psalm-check-type-exact $_selfSingle = User */;

$_propertyWrite = User::factory()->propertyWrite()->make();
/** @psalm-check-type-exact $_propertyWrite = User|Collection<int, User> */;

$_assignsLocal = User::factory()->assignsLocal()->make();
/** @psalm-check-type-exact $_assignsLocal = User|Collection<int, User> */;

$_statementCall = User::factory()->statementCall()->make();
/** @psalm-check-type-exact $_statementCall = User|Collection<int, User> */;

$_unsets = User::factory()->unsets()->make();
/** @psalm-check-type-exact $_unsets = User|Collection<int, User> */;

$_closureCall = User::factory()->closureCall()->make();
/** @psalm-check-type-exact $_closureCall = User|Collection<int, User> */;

$_argMutates = User::factory()->argMutates()->make();
/** @psalm-check-type-exact $_argMutates = User|Collection<int, User> */;

$_closureArgument = User::factory(3)->closureArgument()->make();
/** @psalm-check-type-exact $_closureArgument = Collection<int, User> */;

$_keepsOrValue = User::factory(3)->keepsOrValue(42);
/** @psalm-check-type-exact $_keepsOrValue = UserFactory<int<2, max>>&static|42 */;

$_mayReplace = User::factory(3)->mayReplace(42, false);
/** @psalm-check-type-exact $_mayReplace = UserFactory<int<2, max>|null>|int */;

$_mayBeNull = User::factory(3)->mayBeNull(true);
/** @psalm-check-type-exact $_mayBeNull = UserFactory<int<2, max>|null>|null */;

$_forOrg = User::factory(3)->forOrg('o')->admin()->make();
/** @psalm-check-type-exact $_forOrg = Collection<int, User> */;

$_docSelf = User::factory(3)->docSelf()->admin()->make();
/** @psalm-check-type-exact $_docSelf = Collection<int, User> */;

$_asBase = User::factory(3)->asBase()->admin()->make();
/** @psalm-check-type-exact $_asBase = Collection<int, User> */;

$_asOwnClass = User::factory()->asOwnClass()->admin()->make();
/** @psalm-check-type-exact $_asOwnClass = User */;

$_selfBatch = User::factory()->selfBatch()->make();
/** @psalm-check-type-exact $_selfBatch = User|Collection<int, User> */;

$_baseBatch = User::factory()->baseBatch()->make();
/** @psalm-check-type-exact $_baseBatch = Model|Collection<int, Model> */;

$_overriddenSet = OverriddenSetFactory::new()->nativeChain()->make();
/** @psalm-check-type-exact $_overriddenSet = User|Collection<int, User> */;

$_chainBase = ChainBase::times(3)->nativeChain()->make();
/** @psalm-check-type-exact $_chainBase = User|Collection<int, User> */;

$_abstract = ConcreteBase::new()->declared()->make();
/** @psalm-check-type-exact $_abstract = User */;

// ----- Late static identity: the unknown-count answer keeps `static`, so inherited methods stay precise -----
final class AdminFactory extends UserFactory
{
    /** @return static */
    public function superAdmin()
    {
        return $this->state(['super' => true]);
    }
}

$_subclass = AdminFactory::new()->batch()->superAdmin()->make();
/** @psalm-check-type-exact $_subclass = User|Collection<int, User> */;

$_subclassSelf = AdminFactory::new()->forOrg('o')->superAdmin()->make();
/** @psalm-check-type-exact $_subclassSelf = User */;

// ----- Receiver intersections survive: only the factory atomic's count is widened -----
interface Marker
{
}

function acceptMarker(Marker $marker): Marker
{
    return $marker;
}

/** @param UserFactory<null>&Marker $factory */
function intersections(UserFactory $factory): void
{
    acceptMarker($factory->batch());
    acceptMarker($factory->count(2));
    acceptMarker($factory->admin());
    acceptMarker($factory->forOrg('o'));
}

// ----- Ownership: a user-declared TCount that merely maps into Factory stays the user's -----
/**
 * @template-covariant TCount of int|null = null
 * @extends Factory<User, TCount>
 */
class OwnMapped extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return static */
    public function copy()
    {
        return clone $this;
    }
}

/** @param OwnMapped<null> $factory */
function ownership(OwnMapped $factory): void
{
    $_copy = $factory->copy()->make();
    /** @psalm-check-type-exact $_copy = User */;
}
?>
--EXPECTF--
