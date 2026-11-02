--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountInertArguments;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// A variable argument is inert only when it is a parameter with a DECLARED type that cannot run factory code:
// scalars, null, arrays of them, `Closure`, or a `Model`. Anything else may call back into the factory.

final class User extends Model
{
    use HasFactory;
}

final class Project extends Model
{
}

/**
 * @extends Factory<User>
 * @psalm-suppress UnusedClosureParam, MissingParamType, MixedArgument, InvalidArgument, MixedArgumentTypeCoercion
 */
class F extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    // ----- Inert: declared scalar, null, array shapes, Closure, Model -----
    public function forProject(?Project $project): self
    {
        return $this->state(fn (array $attributes) => ['project_id' => $project?->getKey()]);
    }

    public function withName(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    /** @param array<string, int|null> $attributes */
    public function withShape(array $attributes): static
    {
        return $this->state($attributes);
    }

    /** @param array{a: int, b?: list<string|null>} $attributes */
    public function withKeyed(array $attributes): static
    {
        return $this->state($attributes);
    }

    public function withClosure(Closure $state): static
    {
        return $this->state($state);
    }

    /** @param int|float|bool|null $value */
    public function withDocblock($value): static
    {
        return $this->set('v', $value);
    }

    // ----- Not inert: the argument may run code that bumps the count -----
    public function untyped($value): static
    {
        return $this->state($value);
    }

    public function mixedParam(mixed $value): static
    {
        return $this->state($value);
    }

    public function iterableParam(iterable $values): static
    {
        return $this->state($values);
    }

    /** @param \Illuminate\Support\Collection<array-key, mixed> $models */
    public function reused($models): static
    {
        return $this->recycle($models);
    }

    public function otherObject(\ArrayObject $values): static
    {
        return $this->state($values);
    }

    public function self(): static
    {
        return $this->state($this);
    }

    public function callableParam(callable $state): static
    {
        return $this->state($state);
    }

    public function byRef(array &$state): static
    {
        return $this->state($state);
    }

    /** @param string ...$names */
    public function variadic(string ...$names): static
    {
        return $this->state($names);
    }
}

$_forProject = F::new()->forProject(null)->make();
/** @psalm-check-type-exact $_forProject = User */;

$_forProjectMany = F::new()->count(3)->forProject(null)->make();
/** @psalm-check-type-exact $_forProjectMany = Collection<int, User> */;

$_name = F::new()->withName('a')->make();
/** @psalm-check-type-exact $_name = User */;

$_shape = F::new()->withShape(['a' => 1])->make();
/** @psalm-check-type-exact $_shape = User */;

$_keyed = F::new()->withKeyed(['a' => 1])->make();
/** @psalm-check-type-exact $_keyed = User */;

$_closure = F::new()->withClosure(static fn (): array => [])->make();
/** @psalm-check-type-exact $_closure = User */;

$_docblock = F::new()->withDocblock(1)->make();
/** @psalm-check-type-exact $_docblock = User */;

$_untyped = F::new()->untyped([])->make();
/** @psalm-check-type-exact $_untyped = User|Collection<int, User> */;

$_mixed = F::new()->mixedParam([])->make();
/** @psalm-check-type-exact $_mixed = User|Collection<int, User> */;

$_iterable = F::new()->iterableParam([])->make();
/** @psalm-check-type-exact $_iterable = User|Collection<int, User> */;

$_reused = F::new()->reused(new \Illuminate\Support\Collection())->make();
/** @psalm-check-type-exact $_reused = User|Collection<int, User> */;

$_other = F::new()->otherObject(new \ArrayObject())->make();
/** @psalm-check-type-exact $_other = User|Collection<int, User> */;

$_self = F::new()->self()->make();
/** @psalm-check-type-exact $_self = User|Collection<int, User> */;

$_callable = F::new()->callableParam(static fn (): array => [])->make();
/** @psalm-check-type-exact $_callable = User|Collection<int, User> */;

$_arr = [];
$_byRef = F::new()->byRef($_arr)->make();
/** @psalm-check-type-exact $_byRef = User|Collection<int, User> */;

$_variadic = F::new()->variadic('a')->make();
/** @psalm-check-type-exact $_variadic = User|Collection<int, User> */;
?>
--EXPECTF--
