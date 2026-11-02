--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountUniformGate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// A definite count is answered only when EVERY class the receiver may be at runtime (its own and every known
// descendant) runs Laravel's own implementation of every method on the count path.

final class User extends Model
{
    use HasFactory;
}

// ----- An undiscovered factory (broad `newFactory()` return) has unproven count semantics -----
final class Broad extends Model
{
    use HasFactory;

    /** @return Factory<Broad> */
    protected static function newFactory(): Factory
    {
        return new BroadFactory();
    }
}

/** @extends Factory<Broad> */
final class BroadFactory extends Factory
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
}

$_fallbackCount = Broad::factory(3)->make();
/** @psalm-check-type-exact $_fallbackCount = Broad|Collection<int, Broad> */;

$_fallbackState = Broad::factory([])->make();
/** @psalm-check-type-exact $_fallbackState = Broad|Collection<int, Broad> */;

$_fallbackNone = Broad::factory()->make();
/** @psalm-check-type-exact $_fallbackNone = Broad */;

// ----- An ancestor-typed receiver may hold a descendant that overrides a method on the count path -----
/** @extends Factory<User> */
class F extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

final class OverridesCount extends F
{
    /** @return static */
    #[\Override]
    public function count(?int $count)
    {
        return new static();
    }
}

function ancestorTyped(F $f): void
{
    $_sequence = $f->forEachSequence([])->make();
    /** @psalm-check-type-exact $_sequence = User|Collection<int, User> */;

    $_count = $f->count(3)->make();
    /** @psalm-check-type-exact $_count = User|Collection<int, User> */;

    $_state = $f->state([])->make();
    /** @psalm-check-type-exact $_state = User|Collection<int, User> */;
}

// A literal class names the exact class, so a descendant's override does not matter.
$_exact = F::times(3)->make();
/** @psalm-check-type-exact $_exact = Collection<int, User> */;

// ----- The constructor calls the overridable `withFaker()` -----
/** @extends Factory<User> */
final class FakerFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    #[\Override]
    protected function withFaker()
    {
        $this->count = 2;

        return null;
    }
}

$_fakerNew = (new FakerFactory())->make();
/** @psalm-check-type-exact $_fakerNew = User|Collection<int, User> */;

$_fakerStatic = FakerFactory::new()->make();
/** @psalm-check-type-exact $_fakerStatic = User|Collection<int, User> */;

// ----- A nested `static` is widened with the top-level one when the body is not proven -----
/**
 * @extends Factory<User>
 * @method $this hasPosts()
 */
final class Nested extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return list<static> */
    public function wrapped(): array
    {
        return [$this->count(2)];
    }

    /** @return array{factory: static} */
    public function shaped(): array
    {
        return ['factory' => $this->count(2)];
    }

    /** @return \Closure(): static */
    public function deferred(): \Closure
    {
        return fn (): static => $this;
    }

    /** @return list<static> */
    public function keptWrapped(): array
    {
        return [$this];
    }

    // ----- Argument unpacking and property reads run user code before `state()` rebuilds the factory -----
    /** @param array{array<string, mixed>} $args */
    public function unpack(array $args): static
    {
        return $this->state(...$args);
    }

    /** @param array{state: array<string, mixed>} $args */
    public function namedUnpack(array $args): static
    {
        return $this->state(...$args);
    }

    /** @var array<string, mixed> */
    private array $preset = [];

    public function preset(): static
    {
        return $this->state($this->preset);
    }

    public function plain(): static
    {
        return $this->state(['a' => 1]);
    }
}

$_wrapped = Nested::times(3)->wrapped()[0]->make();
/** @psalm-check-type-exact $_wrapped = User|Collection<int, User> */;

$_shaped = Nested::times(3)->shaped()['factory']->make();
/** @psalm-check-type-exact $_shaped = User|Collection<int, User> */;

$_deferred = Nested::times(3)->deferred()()->make();
/** @psalm-check-type-exact $_deferred = User|Collection<int, User> */;

$_unpack = Nested::times(3)->unpack([[]])->make();
/** @psalm-check-type-exact $_unpack = User|Collection<int, User> */;

$_namedUnpack = Nested::times(3)->namedUnpack(['state' => []])->make();
/** @psalm-check-type-exact $_namedUnpack = User|Collection<int, User> */;

$_preset = Nested::times(3)->preset()->make();
/** @psalm-check-type-exact $_preset = User|Collection<int, User> */;

$_plain = Nested::times(3)->plain()->make();
/** @psalm-check-type-exact $_plain = Collection<int, User> */;

// ----- Magic `for*` / `has*` forwards to has() / for(), which must be Laravel's on the receiver and its descendants -----
/**
 * @extends Factory<User>
 * @method $this hasPosts()
 */
class Magic extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function viaMagic(): static
    {
        return $this->hasPosts();
    }
}

final class MagicChild extends Magic
{
    /**
     * @param  string|null  $relationship
     * @return static
     */
    #[\Override]
    public function has(Factory $factory, $relationship = null)
    {
        $this->count = 2;

        return $this;
    }
}

/** @param Magic<null> $factory */
function magicTyped(Magic $factory): void
{
    $_magic = $factory->viaMagic()->make();
    /** @psalm-check-type-exact $_magic = User|Collection<int, User> */;
}

/**
 * @extends Factory<User>
 * @method $this hasPosts()
 */
final class MagicOnly extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /**
     * @param  string|null  $relationship
     * @return static
     */
    #[\Override]
    public function has(Factory $factory, $relationship = null)
    {
        $this->count = 2;

        return $this;
    }

    public function viaMagic(): static
    {
        return $this->hasPosts();
    }
}

$_magicOverridden = MagicOnly::new()->viaMagic()->make();
/** @psalm-check-type-exact $_magicOverridden = User|Collection<int, User> */;
?>
--EXPECTF--
LessSpecificReturnStatement on line %d: The type %s is more general than the declared return type %s
