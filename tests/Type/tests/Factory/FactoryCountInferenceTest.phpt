--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountInference;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
    use HasFactory;

    /** @var class-string<UserFactory> */
    protected static $factory = UserFactory::class;
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

    /** @return static */
    public function admin()
    {
        return $this->state(['admin' => true]);
    }

    /** @return $this */
    public function thisAdmin()
    {
        return $this;
    }

    public function nativeStatic(): static
    {
        return $this->state(['admin' => true]);
    }

    public function nativeSelf(): self
    {
        return new self();
    }
}

final class AdminUserFactory extends UserFactory
{
    /** @return static */
    public function superAdmin()
    {
        return $this->state(['super' => true]);
    }
}

/**
 * @template TModel of Model
 * @extends Factory<TModel>
 */
abstract class AbstractGenericFactory extends Factory
{
    /** @return static */
    public function common()
    {
        return $this->state(['common' => true]);
    }
}

final class Thing extends Model
{
}

/** @extends AbstractGenericFactory<Thing> */
final class ThingFactory extends AbstractGenericFactory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return static */
    public function special()
    {
        return $this->state(['special' => true]);
    }
}

// ----- A model that binds the factory explicitly with `@use HasFactory<OrderFactory>` -----
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;
}

/** @extends Factory<Order> */
final class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return static */
    public function paid()
    {
        return $this->state(['paid' => true]);
    }
}

// ----- factory(): the argument decides, the receiver keeps the concrete class -----
$_none = User::factory()->make();
/** @psalm-check-type-exact $_none = User */;

$_null = User::factory(null)->make();
/** @psalm-check-type-exact $_null = User */;

$_stateArray = User::factory(['admin' => true])->make();
/** @psalm-check-type-exact $_stateArray = User */;

$_stateClosure = User::factory(static fn (array $attributes): array => $attributes)->make();
/** @psalm-check-type-exact $_stateClosure = User */;

$_stateNamed = User::factory(state: ['admin' => true])->make();
/** @psalm-check-type-exact $_stateNamed = User */;

$_three = User::factory(3)->create();
/** @psalm-check-type-exact $_three = Collection<int, User> */;

$_numericString = User::factory('3')->create();
/** @psalm-check-type-exact $_numericString = Collection<int, User> */;

$_countNamed = User::factory(count: 3)->create();
/** @psalm-check-type-exact $_countNamed = Collection<int, User> */;

$_zero = User::factory(0)->create();
/** @psalm-check-type-exact $_zero = Collection<int, User> */;

$_one = User::factory(1)->create();
/** @psalm-check-type-exact $_one = Collection<int, User> */;

// ----- Non-literal arguments follow is_numeric() -----
$int = random_int(0, 5);
$_int = User::factory($int)->create();
/** @psalm-check-type-exact $_int = Collection<int, User> */;

// The declared `factory()` parameter is `int|null|array|callable`; Laravel's runtime accepts any is_numeric() value.
/** @var float $float */
$float = 2.0;
/** @psalm-suppress InvalidArgument */
$_float = User::factory($float)->create();
/** @psalm-check-type-exact $_float = Collection<int, User> */;

/** @var numeric-string $numeric */
$numeric = '3';
/** @psalm-suppress InvalidArgument */
$_numeric = User::factory($numeric)->create();
/** @psalm-check-type-exact $_numeric = Collection<int, User> */;

/** @var bool $bool */
$bool = true;
/** @psalm-suppress InvalidArgument */
$_bool = User::factory($bool)->make();
/** @psalm-check-type-exact $_bool = User */;

/** @var array<string, mixed> $array */
$array = [];
$_array = User::factory($array)->make();
/** @psalm-check-type-exact $_array = User */;

/** @var int|null $maybeInt */
$maybeInt = null;
$_maybeInt = User::factory($maybeInt)->make();
/** @psalm-check-type-exact $_maybeInt = User|Collection<int, User> */;

/** @var string $string */
$string = '';
/** @psalm-suppress InvalidArgument */
$_string = User::factory($string)->make();
/** @psalm-check-type-exact $_string = User|Collection<int, User> */;

/** @var list<int|null> $unpacked */
$unpacked = [3];
/** @psalm-suppress InvalidArgument */
$_unpack = User::factory(...$unpacked)->make();
/** @psalm-check-type-exact $_unpack = User|Collection<int, User> */;

// ----- Constructor and new() -----
$_newThree = (new UserFactory(3))->make();
/** @psalm-check-type-exact $_newThree = Collection<int, User> */;

$_newNamed = (new UserFactory(count: 2))->make();
/** @psalm-check-type-exact $_newNamed = Collection<int, User> */;

$_newOne = (new UserFactory(1))->make();
/** @psalm-check-type-exact $_newOne = Collection<int, User> */;

$_newBare = (new UserFactory())->make();
/** @psalm-check-type-exact $_newBare = User */;

$_newNull = (new UserFactory(null))->make();
/** @psalm-check-type-exact $_newNull = User */;

$_staticNew = UserFactory::new()->make();
/** @psalm-check-type-exact $_staticNew = User */;

$_staticNewState = UserFactory::new(['admin' => true])->make();
/** @psalm-check-type-exact $_staticNewState = User */;

// ----- Custom methods stay callable after the count is set -----
$_adminStatic = User::factory(3)->admin()->create();
/** @psalm-check-type-exact $_adminStatic = Collection<int, User> */;

$_adminThis = User::factory(3)->thisAdmin()->create();
/** @psalm-check-type-exact $_adminThis = Collection<int, User> */;

$_adminNative = User::factory(3)->nativeStatic()->create();
/** @psalm-check-type-exact $_adminNative = Collection<int, User> */;

$_adminSingle = User::factory()->admin()->create();
/** @psalm-check-type-exact $_adminSingle = User */;

$_countThenAdmin = User::factory()->count(3)->admin()->create();
/** @psalm-check-type-exact $_countThenAdmin = Collection<int, User> */;

$_timesThenAdmin = UserFactory::times(3)->admin()->make();
/** @psalm-check-type-exact $_timesThenAdmin = Collection<int, User> */;

$_countNullSingle = User::factory(3)->count(null)->make();
/** @psalm-check-type-exact $_countNullSingle = User */;

$_sequence = User::factory()->forEachSequence(['a' => 1], ['a' => 2])->admin()->make();
/** @psalm-check-type-exact $_sequence = Collection<int, User> */;

$held = User::factory(3);
$_held = $held->create();
/** @psalm-check-type-exact $_held = Collection<int, User> */;

// ----- Subclass of a concrete factory, and a generic abstract intermediate -----
$_adminSub = (new AdminUserFactory(3))->superAdmin()->admin()->create();
/** @psalm-check-type-exact $_adminSub = Collection<int, User> */;

$_adminSubCount = AdminUserFactory::new()->count(3)->superAdmin()->admin()->make();
/** @psalm-check-type-exact $_adminSubCount = Collection<int, User> */;

$_generic = (new ThingFactory(3))->special()->common()->create();
/** @psalm-check-type-exact $_generic = Collection<int, Thing> */;

// ----- Explicit `@use HasFactory<OrderFactory>` -----
$_orderMany = Order::factory(3)->paid()->create();
/** @psalm-check-type-exact $_orderMany = Collection<int, Order> */;

$_orderOne = Order::factory()->paid()->create();
/** @psalm-check-type-exact $_orderOne = Order */;

// ----- Native `: self` drops the count: the union is the sound answer -----
$_nativeSelf = User::factory(3)->nativeSelf()->create();
/** @psalm-check-type-exact $_nativeSelf = User|Collection<int, User> */;
?>
--EXPECTF--
