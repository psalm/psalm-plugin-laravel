--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Glacier extends Model
    {
    }
}

namespace Database\Factories {
    use App\Models\Glacier;
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * The docblock-typed `$model` property: when the user adds
     * `@var class-string<X>`, Psalm parses the property type as `TClassString`
     * with the constraint class in `$as` (not the `TLiteralClassString` shape
     * used for an untyped `$model = X::class;`). The handler must read both
     * shapes.
     */
    final class GlacierFactory extends Factory
    {
        /** @var class-string<Glacier> */
        protected $model = Glacier::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Glacier {
    use Database\Factories\GlacierFactory;

    $_one = (new GlacierFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Glacier */;
}
?>
--EXPECTF--
