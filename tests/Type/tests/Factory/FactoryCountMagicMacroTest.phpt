--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountMagicMacro;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

// `__call` serves `has*` / `for*` AFTER the macro registry: a macro registered as `hasBatch` or `forAdmin` can
// change the count, so a call dispatched through `__call` never passes the whole-body rule.

final class User extends Model
{
}

/**
 * @extends Factory<User>
 * @method $this hasBatch()
 */
class F extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function install(): void
    {
        self::macro('hasBatch', function () {
            return $this->count(2);
        });
    }

    public function batch(): static
    {
        return $this->hasBatch();
    }
}

$_viaMacro = F::new()->batch()->make();
/** @psalm-check-type-exact $_viaMacro = User|Collection<int, User> */;

/**
 * @extends Factory<User>
 * @method $this hasBatch()
 */
class Configured extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    #[\Override]
    public function configure(): static
    {
        return $this->hasBatch();
    }
}

$_configured = Configured::new()->make();
/** @psalm-check-type-exact $_configured = User|Collection<int, User> */;
?>
--EXPECTF--
