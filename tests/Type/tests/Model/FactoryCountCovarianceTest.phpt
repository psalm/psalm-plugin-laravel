--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountCovariance;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Widget extends Model
{
    use HasFactory;
}

/** @extends Factory<Widget> */
final class WidgetFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/**
 * `Factory<Widget>` is `Factory<Widget, int|null>` (the `TCount` default). The providers build narrower
 * counts (`WidgetFactory<null>` for `Widget::factory()`, `WidgetFactory<int<2, max>>` after `count(2)`), so
 * `TCount` must be covariant for those to be accepted.
 *
 * @param Factory<Widget> $factory
 */
function acceptsAnyCount(Factory $factory): void
{
    $factory->make();
}

/** @param Factory<Widget, null> $factory */
function acceptsNoCountOnly(Factory $factory): void
{
    $factory->make();
}

acceptsAnyCount(Widget::factory());
acceptsAnyCount(Widget::factory(3));
acceptsAnyCount(Widget::factory()->count(3));
acceptsAnyCount(Widget::factory()->count(null));
acceptsAnyCount(Widget::factory()->times(3));
acceptsAnyCount(new WidgetFactory());
acceptsAnyCount(WidgetFactory::new());
acceptsAnyCount(WidgetFactory::times(2));

// `new WidgetFactory()` and `WidgetFactory::new()` carry `null`, which a `Factory<Widget, null>` accepts.
acceptsNoCountOnly(new WidgetFactory());
acceptsNoCountOnly(WidgetFactory::new());

// Negative control: covariance widens only towards the bound, never the other way.
acceptsNoCountOnly(new WidgetFactory(3));
acceptsNoCountOnly(Widget::factory(3));
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Sandbox\CountCovariance\acceptsNoCountOnly expects Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountCovariance\Widget, null>, but App\Sandbox\CountCovariance\WidgetFactory<3> provided
InvalidArgument on line %d: Argument 1 of App\Sandbox\CountCovariance\acceptsNoCountOnly expects Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountCovariance\Widget, null>, but Illuminate\Database\Eloquent\Factories\Factory<App\Sandbox\CountCovariance\Widget, int<2, max>|null> provided
