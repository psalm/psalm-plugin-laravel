--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountTraitAlias;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Conditionable;

// A Laravel trait method aliased over a `Factory` method is no longer `Factory`'s own implementation: the
// gate accepts a name only when its declaring method is exactly the one `Factory` declares.

final class User extends Model
{
}

/** @extends Factory<User> */
class F extends Factory
{
    use Conditionable {
        when as state;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function bump(): void
    {
        $this->count = 2;
    }

    public function preset(): static
    {
        return $this->state(function (): array {
            $this->bump();

            return [];
        })->state([]);
    }
}

$_aliased = F::new()->preset()->make();
/** @psalm-check-type-exact $_aliased = User|Collection<int, User> */;
?>
--EXPECTF--
