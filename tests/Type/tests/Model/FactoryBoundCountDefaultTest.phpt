--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\UnboundCount;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

final class Sprocket extends Model
{
}

/**
 * `@extends Factory<Sprocket>` names only TModel and leaves TCount unbound.
 *
 * @extends Factory<Sprocket>
 */
final class SprocketFactory extends Factory
{
    /** @var class-string<Sprocket> */
    protected $model = Sprocket::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * `@extends Factory<Sprocket>` leaves `TCount` at its `int|null` default, but every way a count reaches
 * the factory is typed: `new SprocketFactory()` and `SprocketFactory::new()` are `SprocketFactory<null>`,
 * so these answer the single model Laravel returns. Only a factory whose count is not visible keeps the
 * default `int|null` and the union, which is the sound answer (see the parameter below). #2188;
 * "Factories" in docs/contributing/decisions.md.
 */
$_new = (new SprocketFactory())->create();
/** @psalm-check-type-exact $_new = \App\Sandbox\UnboundCount\Sprocket */;

$_make = SprocketFactory::new()->make();
/** @psalm-check-type-exact $_make = \App\Sandbox\UnboundCount\Sprocket */;

$_quiet = SprocketFactory::new()->state(['a' => 1])->createQuietly();
/** @psalm-check-type-exact $_quiet = \App\Sandbox\UnboundCount\Sprocket */;

$factory = new SprocketFactory();
$_held = $factory->create();
/** @psalm-check-type-exact $_held = \App\Sandbox\UnboundCount\Sprocket */;

// A factory received through a parameter may have been counted by the caller: the default stays the union.
function receivedFactory(SprocketFactory $factory): void
{
    $_received = $factory->create();
    /** @psalm-check-type-exact $_received = \App\Sandbox\UnboundCount\Sprocket|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\UnboundCount\Sprocket> */;
}
?>
--EXPECTF--
