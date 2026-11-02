--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountHierarchyGate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// ONE gate: a class gets a definite count only when neither it nor any known descendant declares a method that
// Laravel's `Factory` declares (`definition()` and a keep-rule `configure()` excepted).

final class User extends Model
{
    use HasFactory;
}

final class Post extends Model
{
    use HasFactory;
}

/** @extends Factory<Post> */
final class PostFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
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

    /** @param Factory<Post> $related */
    public function related(Factory $related): static
    {
        return $this->has($related);
    }
}

// ----- A descendant's override of a Laravel fluent method changes what an `F<null>` parameter does -----
final class SetChild extends F
{
    #[\Override]
    public function set($key, $value)
    {
        $this->count = 2;

        return $this;
    }
}

/** @param F<null> $f */
function viaSet(F $f): void
{
    $_set = $f->set('test', 1)->make();
    /** @psalm-check-type-exact $_set = User|Collection<int, User> */;
}

/** @extends Factory<User> */
class G extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

final class StateChild extends G
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

/** @param G<null> $g */
function viaState(G $g): void
{
    $_state = $g->state([])->make();
    /** @psalm-check-type-exact $_state = User|Collection<int, User> */;
}

// ----- `modelName()` / `newModel()` run before relationship chains rebuild the factory -----
/** @extends Factory<User> */
class ModelNameFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    /** @return class-string<User> */
    #[\Override]
    public function modelName()
    {
        $this->count = 2;

        return User::class;
    }

    /** @param Factory<Post> $related */
    public function related(Factory $related): static
    {
        return $this->has($related);
    }
}

$_modelName = ModelNameFactory::new()->related(PostFactory::new())->make();
/** @psalm-check-type-exact $_modelName = User|Collection<int, User> */;

/**
 * @extends Factory<User>
 * @method $this hasPosts()
 */
class H extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function withPosts(): static
    {
        return $this->hasPosts();
    }
}

final class NewModelChild extends H
{
    /** @psalm-suppress MethodSignatureMismatch */
    #[\Override]
    public function newModel(array $attributes = [])
    {
        $this->count = 2;

        return new User();
    }
}

/** @param H<null> $h */
function viaNewModel(H $h): void
{
    $_newModel = $h->withPosts()->make();
    /** @psalm-check-type-exact $_newModel = User|Collection<int, User> */;
}

// ----- Controls: no Laravel method declared anywhere, and the exempt `definition()` / keep-rule `configure()` -----
/** @extends Factory<User> */
class Clean extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    #[\Override]
    public function configure()
    {
        return $this->afterMaking(static fn () => null)->afterCreating(static fn () => null);
    }

    public function admin(): static
    {
        return $this->state(['admin' => true]);
    }
}

final class CleanChild extends Clean
{
    #[\Override]
    public function configure()
    {
        return $this;
    }
}

/** @param Clean<null> $c */
function control(Clean $c): void
{
    $_single = $c->admin()->make();
    /** @psalm-check-type-exact $_single = User */;

    $_many = $c->count(3)->admin()->make();
    /** @psalm-check-type-exact $_many = Collection<int, User> */;
}

/** @extends Factory<User> */
class Configured extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    #[\Override]
    public function configure()
    {
        $this->count = 3;

        return $this;
    }
}

$_configured = Configured::new()->make();
/** @psalm-check-type-exact $_configured = User|Collection<int, User> */;

// ----- Traits of `Factory` count too: an override of `when()` -----
/** @extends Factory<User> */
class WhenFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    #[\Override]
    public function when($value = null, ?callable $callback = null, ?callable $default = null)
    {
        $this->count = 2;

        return $this;
    }
}

$_when = WhenFactory::new()->make();
/** @psalm-check-type-exact $_when = User|Collection<int, User> */;
?>
--EXPECTF--
