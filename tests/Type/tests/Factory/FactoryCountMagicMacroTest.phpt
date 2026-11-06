--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountMagicMacro;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// `__call` serves `has*` / `for*` AFTER the macro registry: a macro registered as `hasBatch` or `forAdmin` can
// change the count, so a call dispatched through `__call` never passes the whole-body rule.

final class User extends Model
{
    /** @return HasMany<User, $this> */
    public function macroCollision(): HasMany
    {
        return $this->hasMany(self::class);
    }
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

// A boot macro (tests/Type/macro-fixtures.php) named after a real relation wins over the #2215 forwarder: its own
// signature and return type stand.
$_macro = F::new()->hasMacroCollision('x');
/** @psalm-check-type-exact $_macro = int */;

$_macroArity = F::new()->hasMacroCollision();
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for hasMacroCollision - expecting label to be passed
