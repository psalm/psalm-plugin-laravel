--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountOverrideGate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// A factory that overrides how the count is set, kept, or constructed owns its count: the plugin never narrows it.

final class User extends Model
{
    use HasFactory;
}

/** @extends Factory<User> */
class UserFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

// ----- configure() runs inside new() and may set a count -----
final class ConfiguredFactory extends UserFactory
{
    #[\Override]
    public function configure()
    {
        $this->count = 3;

        return $this;
    }
}

$_configured = ConfiguredFactory::new()->make();
/** @psalm-check-type-exact $_configured = User|Collection<int, User> */;

// ----- state() runs inside new(), so an override of it owns the count -----
final class StateOverrideFactory extends UserFactory
{
    /**
     * @param mixed $state
     * @return static
     */
    #[\Override]
    public function state($state)
    {
        return new static(2);
    }
}

$_stateNew = StateOverrideFactory::new()->make();
/** @psalm-check-type-exact $_stateNew = User|Collection<int, User> */;

$_stateTimes = StateOverrideFactory::times(3)->make();
/** @psalm-check-type-exact $_stateTimes = User|Collection<int, User> */;

// ----- A constructor with its own default count -----
final class DefaultCountFactory extends UserFactory
{
    public function __construct(?int $count = 3)
    {
        parent::__construct($count);
    }
}

$_ctorNew = DefaultCountFactory::new()->make();
/** @psalm-check-type-exact $_ctorNew = User|Collection<int, User> */;

$_ctorDirect = (new DefaultCountFactory())->make();
/** @psalm-check-type-exact $_ctorDirect = User|Collection<int, User> */;

$_ctorNull = (new DefaultCountFactory(null))->make();
/** @psalm-check-type-exact $_ctorNull = User|Collection<int, User> */;

// ----- count() that ignores its argument: factory(3), times(), forEachSequence() all route through it -----
final class NoCountFactory extends UserFactory
{
    /** @return static */
    #[\Override]
    public function count(?int $count)
    {
        return $this;
    }
}

final class NoCountUser extends Model
{
    use HasFactory;

    /** @var class-string<NoCountFactory> */
    protected static $factory = NoCountFactory::class;
}

$_viaModel = NoCountUser::factory(3)->make();
/** @psalm-check-type-exact $_viaModel = User|Collection<int, User> */;

$_viaTimes = NoCountFactory::times(3)->make();
/** @psalm-check-type-exact $_viaTimes = User|Collection<int, User> */;

$_viaSequence = NoCountFactory::new()->forEachSequence(['x' => 1])->make();
/** @psalm-check-type-exact $_viaSequence = User|Collection<int, User> */;

final class NewFactoryUser extends Model
{
    use HasFactory;

    protected static function newFactory(): NoCountFactory
    {
        return NoCountFactory::new();
    }
}

$_viaNewFactory = NewFactoryUser::factory(3)->make();
/** @psalm-check-type-exact $_viaNewFactory = User|Collection<int, User> */;

// ----- newInstance() rebuilds the factory behind every state()/count() call -----
final class ResetInstanceFactory extends UserFactory
{
    /**
     * @param array<string, mixed> $arguments
     * @return static
     */
    #[\Override]
    protected function newInstance(array $arguments = [])
    {
        return new static();
    }
}

$_resetByState = (new ResetInstanceFactory(3))->state([])->make();
/** @psalm-check-type-exact $_resetByState = User|Collection<int, User> */;

$_resetByCount = ResetInstanceFactory::new()->count(3)->make();
/** @psalm-check-type-exact $_resetByCount = User|Collection<int, User> */;

// ----- An override on an ancestor reaches every descendant -----
abstract class OverridingBase extends UserFactory
{
    /** @return static */
    #[\Override]
    public function count(?int $count)
    {
        return $this;
    }
}

final class InheritingFactory extends OverridingBase
{
}

$_inherited = InheritingFactory::new()->count(3)->make();
/** @psalm-check-type-exact $_inherited = User|Collection<int, User> */;

// ----- A plain subclass keeps the exact count -----
final class PlainFactory extends UserFactory
{
}

$_plain = PlainFactory::new()->make();
/** @psalm-check-type-exact $_plain = User */;

$_plainCounted = PlainFactory::times(3)->make();
/** @psalm-check-type-exact $_plainCounted = Collection<int, User> */;

// ----- A user's own TCount template is not the plugin's: it is never rewritten -----
/**
 * @template TCount of int|null
 * @extends Factory<User, null>
 */
final class OwnCountFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

$_ownCount = OwnCountFactory::new()->count(3)->make();
/** @psalm-check-type-exact $_ownCount = Collection<int, User> */;
?>
--EXPECTF--
