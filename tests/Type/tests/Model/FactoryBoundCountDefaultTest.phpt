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
 * `TCount` defaults to its `int|null` bound, so a factory declared `@extends Factory<Sprocket>` answers
 * `Sprocket|Collection<int, Sprocket>` for `create()` / `make()` / `createQuietly()` and `lazy()`, although
 * Laravel returns a single model when no count was set. The default cannot be `null`: a count also reaches
 * a factory through `new SprocketFactory(3)`, `Sprocket::factory(3)` and `SprocketFactory::times(3)`,
 * which the type system cannot see, so a `null` default would type those as a single model (unsound).
 * Writing the second template argument (`@extends Factory<Sprocket, null>`) opts into the single model.
 * Precision for the unbound case is tracked in #2188; see "Factories" in docs/contributing/decisions.md.
 */
$_new = (new SprocketFactory())->create();
/** @psalm-check-type-exact $_new = \App\Sandbox\UnboundCount\Sprocket|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\UnboundCount\Sprocket> */;

$_make = SprocketFactory::new()->make();
/** @psalm-check-type-exact $_make = \App\Sandbox\UnboundCount\Sprocket|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\UnboundCount\Sprocket> */;

$_quiet = SprocketFactory::new()->state(['a' => 1])->createQuietly();
/** @psalm-check-type-exact $_quiet = \App\Sandbox\UnboundCount\Sprocket|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\UnboundCount\Sprocket> */;

$factory = new SprocketFactory();
$_held = $factory->create();
/** @psalm-check-type-exact $_held = \App\Sandbox\UnboundCount\Sprocket|\Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\UnboundCount\Sprocket> */;
?>
--EXPECTF--
