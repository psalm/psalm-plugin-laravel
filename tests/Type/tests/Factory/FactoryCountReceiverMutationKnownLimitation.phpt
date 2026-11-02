--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountReceiverMutation;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

final class User extends Model
{
}

/**
 * Known limitation of the user-method count proof, see the "Accepted soundness gap" caveat in the class docblock
 * of {@see \Psalm\LaravelPlugin\Handlers\Eloquent\FactoryUserMethodCountProvider} (the source of truth).
 *
 * UNSOUND: a method whose body fails the proof widens the factory it RETURNS, but Psalm keeps the receiver
 * variable's own type, so a call whose result is discarded leaves the count stale. At runtime `change()` sets the
 * count to 2 and `make()` returns a Collection; the pinned type below is the single model.
 *
 * @extends Factory<User>
 */
class F extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }

    public function change(): static
    {
        $this->count = 2;

        return $this;
    }
}

$f = F::new();
$f->change();
$_stale = $f->make();
/** @psalm-check-type-exact $_stale = User */;
?>
--EXPECTF--
