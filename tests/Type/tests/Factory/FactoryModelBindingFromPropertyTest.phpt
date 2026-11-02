--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Widget extends Model
    {
    }
}

namespace Database\Factories {
    use App\Models\Widget;
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * Static-fallback (a): no `@extends` docblock, the model comes from the
     * `$model` property default. The shortname (`Pomelo`) intentionally does
     * NOT map to any model, so only the property path can resolve — proving
     * `resolveFromModelProperty()` fires independently of the convention.
     */
    final class PomeloFactory extends Factory
    {
        protected $model = Widget::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Property {
    use Database\Factories\PomeloFactory;

    $_one = (new PomeloFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Widget */;
}
?>
--EXPECTF--
