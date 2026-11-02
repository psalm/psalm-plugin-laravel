--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountArgument;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Gadget extends Model
{
    use HasFactory;
}

/**
 * @extends Factory<Gadget>
 */
final class GadgetFactory extends Factory
{
    /** @var class-string<Gadget> */
    protected $model = Gadget::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

// A count reaches a factory without count(): it must be accepted and typed, never claimed to be a single model.

// ----- Constructor argument, positional and named -----------------------------
$_ctorPositional = (new GadgetFactory(3))->create();
/** @psalm-check-type-exact $_ctorPositional = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\CountArgument\Gadget> */;

$_ctorNamed = (new GadgetFactory(count: 3))->make();
/** @psalm-check-type-exact $_ctorNamed = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\CountArgument\Gadget> */;

// ----- HasFactory::factory($count): a non-null number counts the factory, 0 included. The model's factory is not
// discoverable here (no `Database\Factories` naming), so the actual class and its count semantics are unproven: unknown.
$_factoryThree = Gadget::factory(3)->make();
/** @psalm-check-type-exact $_factoryThree = \App\Sandbox\CountArgument\Gadget|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\CountArgument\Gadget> */;

$_factoryZero = Gadget::factory(0)->create();
/** @psalm-check-type-exact $_factoryZero = \App\Sandbox\CountArgument\Gadget|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\CountArgument\Gadget> */;

// ----- factory() without a count stays a single model ------------------------
$_factoryNone = Gadget::factory()->make();
/** @psalm-check-type-exact $_factoryNone = \App\Sandbox\CountArgument\Gadget */;

// ----- Static times() sets a literal count ------------------------------------
$_times = GadgetFactory::times(3)->create();
/** @psalm-check-type-exact $_times = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\CountArgument\Gadget> */;
?>
--EXPECTF--
