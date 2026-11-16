--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountOwning;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

final class Widget extends Model
{
}

/**
 * @template TModel of Model
 * @extends Factory<TModel>
 */
abstract class BaseFactory extends Factory
{
    // Any override of a Laravel Factory method makes the plugin treat the class as owning its count.
    #[\Override]
    public function state($state): static
    {
        return parent::state($state);
    }

    public function admin(): static
    {
        return $this;
    }

    /** @return Collection<int, TModel> */
    public function many(int $count): Collection
    {
        $_counted = $this->count($count);
        /** @psalm-trace $_counted */
        $this->count($count)->admin();

        $models = $this->count($count)->create();
        /** @psalm-trace $models */

        return $models instanceof Collection ? $models : new Collection([$models]);
    }
}

/** @extends BaseFactory<Widget> */
final class WidgetFactory extends BaseFactory
{
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * A factory that overrides a Laravel method owns its count, and `@extends Factory<Widget>` leaves `TCount`
 * at the default, so there is no count to narrow: a receiver proven to be that class is the answer, keeping its
 * class (so `admin()` stays callable) and, in a generic base, its `TModel`.
 * #2313; "Factories" in docs/contributing/decisions.md.
 */
function leaf(WidgetFactory $factory): void
{
    $_counted = $factory->count(2);
    /** @psalm-check-type-exact $_counted = WidgetFactory */;
    $factory->count(2)->admin();

    $_times = WidgetFactory::times(2);
    /** @psalm-check-type-exact $_times = WidgetFactory */;
    $_sequenced = $factory->forEachSequence([]);
    /** @psalm-check-type-exact $_sequenced = WidgetFactory */;
    $factory->times(2)->admin();
}

/** @extends Factory<Widget, null> */
final class SingleWidgetFactory extends Factory
{
    #[\Override]
    public function state($state): static
    {
        return parent::state($state);
    }

    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * An explicit second template argument is the user's contract: the base `Factory<Widget, count>` answer stays,
 * and `count(3)->create()` is still the collection branch.
 */
function explicitContract(SingleWidgetFactory $factory): void
{
    $_created = $factory->count(3)->create();
    /** @psalm-check-type-exact $_created = \Illuminate\Database\Eloquent\Collection<int, Widget>|Widget */;
}

/** @extends Factory<Widget, int|null> */
final class DefaultContractWidgetFactory extends Factory
{
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * An explicit `<Widget, int|null>` WITHOUT an override still runs Laravel's count path: `count(2)` narrows, as on 4.x.
 *
 * @param WidgetFactory&Factory<Widget, null> $intersection
 */
function explicitDefaultWithoutOverride(DefaultContractWidgetFactory $factory, $intersection): void
{
    $_created = $factory->count(2)->make();
    /** @psalm-check-type-exact $_created = \Illuminate\Database\Eloquent\Collection<int, Widget> */;

    // A receiver intersection is not proven to be the class, so the base `Factory<Widget, count>` answers: never `mixed`.
    $_counted = $intersection->count(2);
    /** @psalm-check-type-exact $_counted = \Illuminate\Database\Eloquent\Factories\Factory<Widget, int<2, max>> */;
}
?>
--EXPECTF--
Trace on line %d: $_counted: App\Sandbox\CountOwning\BaseFactory<TModel:App\Sandbox\CountOwning\BaseFactory as Illuminate\Database\Eloquent\Model>&static
Trace on line %d: $models: (Illuminate\Database\Eloquent\Collection<int, TModel:App\Sandbox\CountOwning\BaseFactory as Illuminate\Database\Eloquent\Model>)|(TModel:App\Sandbox\CountOwning\BaseFactory as Illuminate\Database\Eloquent\Model)
