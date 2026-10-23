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

// A count reaches a factory without count(): it must be accepted, and the result is never claimed
// to be a single model. The union is the honest answer while TCount is only known to be int|null.

// ----- Constructor argument, positional and named -----------------------------
$_ctorPositional = (new GadgetFactory(3))->create();
/** @psalm-check-type-exact $_ctorPositional = \App\Sandbox\CountArgument\Gadget|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\CountArgument\Gadget> */;

$_ctorNamed = (new GadgetFactory(count: 3))->make();
/** @psalm-check-type-exact $_ctorNamed = \App\Sandbox\CountArgument\Gadget|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\CountArgument\Gadget> */;

// ----- HasFactory::factory($count): a non-null number counts the factory, 0 included
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
