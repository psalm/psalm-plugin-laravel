--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountDecline;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// A count the plugin cannot see for certain keeps the union, never a false single model or Collection.

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
}

final class Other extends Model
{
    use HasFactory;

    /** @var class-string<OtherFactory> */
    protected static $factory = OtherFactory::class;
}

/** @extends Factory<Other> */
final class OtherFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

// ----- User overrides own the return type, the plugin declines -----
/** @extends Factory<User> */
final class OverridingFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return static */
    #[\Override]
    public function count(?int $count)
    {
        return $this;
    }

    /**
     * @param (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed> $attributes
     * @return static
     */
    #[\Override]
    public static function new($attributes = [])
    {
        return new self();
    }

    /**
     * @param array<string, mixed> ...$sequence
     * @return static
     */
    #[\Override]
    public function forEachSequence(...$sequence)
    {
        return $this;
    }
}

$_overrideCount = (new OverridingFactory())->count(3)->make();
/** @psalm-check-type-exact $_overrideCount = User|Collection<int, User> */;

$_overrideNew = OverridingFactory::new()->make();
/** @psalm-check-type-exact $_overrideNew = User|Collection<int, User> */;

$_overrideSequence = (new OverridingFactory())->forEachSequence(['a' => 1])->make();
/** @psalm-check-type-exact $_overrideSequence = User|Collection<int, User> */;

final class Overriding extends Model
{
    use HasFactory;

    /**
     * @param int|null $count
     * @param array<string, mixed> $state
     * @return UserFactory<int|null>
     */
    public static function factory($count = null, $state = [])
    {
        return UserFactory::new();
    }
}

$_overrideFactory = Overriding::factory(3)->make();
/** @psalm-check-type-exact $_overrideFactory = User|Collection<int, User> */;

// ----- An explicit second template argument is the user's contract and is left alone -----
/** @extends Factory<User, null> */
final class SingleFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/** @extends Factory<User, int> */
final class ManyFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

$_singleNew = SingleFactory::new()->make();
/** @psalm-check-type-exact $_singleNew = User */;

$_singleCounted = SingleFactory::new()->count(3)->make();
/** @psalm-check-type-exact $_singleCounted = Collection<int, User> */;

$_manyNew = ManyFactory::new()->make();
/** @psalm-check-type-exact $_manyNew = Collection<int, User> */;

$_manySequence = ManyFactory::new()->forEachSequence(['a' => 1])->make();
/** @psalm-check-type-exact $_manySequence = Collection<int, User> */;

// ----- Late static binding: a subclass may preset a count, so a non-final class answers the union -----
/** @var class-string<UserFactory> $factoryClass */
$factoryClass = UserFactory::class;
$_classStringNew = $factoryClass::new()->make();
/** @psalm-check-type-exact $_classStringNew = User|Collection<int, User> */;

$_classStringTimes = $factoryClass::times(3)->make();
/** @psalm-check-type-exact $_classStringTimes = Collection<int, User> */;

abstract class Entity extends Model
{
    use HasFactory;

    public static function viaStatic(): void
    {
        $_factory = static::factory(3);
        /** @psalm-trace $_factory */;
    }
}

final class Leaf extends Model
{
    use HasFactory;

    public static function viaStatic(): void
    {
        $_factory = static::factory(3);
        /** @psalm-trace $_factory */;
    }
}

/** @var class-string<Entity> $entityClass */
$entityClass = Entity::class;
$_entityFactory = $entityClass::factory(3);
/** @psalm-trace $_entityFactory */;

/** @var class-string<Leaf> $leafClass */
$leafClass = Leaf::class;
$_leafFactory = $leafClass::factory(3);
/** @psalm-trace $_leafFactory */;

// ----- Unions of receivers: each atomic keeps its own answer -----
/** @var UserFactory|OtherFactory $either */
$either = new UserFactory();
$_eitherCount = $either->count(3)->make();
/** @psalm-check-type-exact $_eitherCount = Collection<int, User>|Collection<int, Other> */;

$_eitherDefault = $either->make();
/** @psalm-check-type-exact $_eitherDefault = User|Collection<int, User>|Other|Collection<int, Other> */;

/** @var User|Other $model */
$model = new User();
$_modelUnion = $model::factory(3)->make();
/** @psalm-check-type-exact $_modelUnion = Collection<int, User>|Collection<int, Other> */;

$_conditional = (random_int(0, 1) === 0 ? User::factory(3) : User::factory())->make();
/** @psalm-check-type-exact $_conditional = User|Collection<int, User> */;

// ----- A factory received through a parameter keeps the default union, count() still narrows it -----
function bareParameter(UserFactory $factory): void
{
    $_made = $factory->make();
    /** @psalm-check-type-exact $_made = User|Collection<int, User> */;

    $_counted = $factory->count(2)->admin()->make();
    /** @psalm-check-type-exact $_counted = Collection<int, User> */;
}

/** @param Factory<User> $factory */
function baseParameter(Factory $factory): void
{
    $_counted = $factory->count(2)->make();
    /** @psalm-check-type-exact $_counted = Collection<int, User> */;

    $_sequence = $factory->forEachSequence(['a' => 1])->make();
    /** @psalm-check-type-exact $_sequence = Collection<int, User> */;
}

// ----- The counted factory is accepted where its count fits, rejected where it does not -----
/** @param Factory<User, null> $factory */
function acceptsSingle(Factory $factory): void
{
    $factory->make();
}

/** @param Factory<User, int> $factory */
function acceptsCounted(Factory $factory): void
{
    $factory->make();
}

function acceptsConcrete(UserFactory $factory): void
{
    $factory->make();
}

acceptsSingle(User::factory());
acceptsCounted(User::factory(3));
acceptsConcrete(User::factory(3));
acceptsConcrete(User::factory());

// Rejected: a plural factory is not a single one, a single factory is not a counted one.
acceptsSingle(User::factory(3));
acceptsCounted(User::factory());

// ----- Closures that receive the factory keep the concrete class -----
$_when = User::factory(3)->when(true, static fn (UserFactory $factory): UserFactory => $factory->admin())->create();
/** @psalm-check-type-exact $_when = Collection<int, User> */;

$_whenInferred = User::factory(3)->when(true, static fn ($factory) => $factory->admin())->create();
/** @psalm-check-type-exact $_whenInferred = Collection<int, User> */;
?>
--EXPECTF--
Trace on line %d: $_factory: Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountDecline\Entity, int<2, max>|null>
Trace on line %d: $_factory: Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountDecline\Leaf, int<2, max>|null>
Trace on line %d: $_entityFactory: Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountDecline\Entity, int<2, max>|null>
Trace on line %d: $_leafFactory: Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountDecline\Leaf, int<2, max>|null>
InvalidArgument on line %d: Argument 1 of App\Sandbox\CountDecline\acceptsSingle expects Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountDecline\User, null>, but App\Sandbox\CountDecline\UserFactory<int<2, max>> provided
InvalidArgument on line %d: Argument 1 of App\Sandbox\CountDecline\acceptsCounted expects Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountDecline\User, int>, but App\Sandbox\CountDecline\UserFactory<null> provided
