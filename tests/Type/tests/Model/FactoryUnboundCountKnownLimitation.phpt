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
 * KNOWN LIMITATION: a user factory declared as `@extends Factory<Sprocket>` leaves `TCount` at its
 * `int|null` bound, because Psalm ignores the `@template TCount of int|null = null` default of the stub
 * (it only honours defaults on purity templates). Since Psalm 7.0.0-rc6 a conditional return on such an
 * unresolved template evaluates BOTH branches, so `create()` / `make()` / `createQuietly()` answer
 * `Sprocket|Collection<int, Sprocket>` and `lazy()` a closure returning that union, where Laravel returns
 * a single model for every one of these calls (no count was set).
 *
 * Writing the second template argument (`@extends Factory<Sprocket, null>`) restores the single model.
 * Binding `TCount` to `null` plugin-side was rejected as unsound: a count also reaches a factory through
 * `new SprocketFactory(3)`, `Sprocket::factory(3)` and `SprocketFactory::times(3)`, all of which must stay
 * plural; see "Factories" in docs/contributing/decisions.md.
 *
 * @todo-by https://github.com/vimeo/psalm/issues/12446 expect the single model again (default of an omitted `@extends` argument honored)
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
