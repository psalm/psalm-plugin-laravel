--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Mariner extends Model
    {
    }

    final class Skipper extends Model
    {
    }
}

namespace Database\Factories {
    use App\Models\Mariner;
    use App\Models\Skipper;
    use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * Laravel 11+ pattern: `#[UseModel(X::class)]` attribute overrides both
     * the `$model` property and the convention. Mirrors the
     * `cachedModelAttributes` branch in `Factory::modelName()`.
     *
     * Adversarial framing: `$model` and the convention both point at
     * `Mariner`, but the attribute says `Skipper`. The attribute must win.
     */
    #[UseModel(Skipper::class)]
    final class MarinerFactory extends Factory
    {
        protected $model = Mariner::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Mariner {
    use Database\Factories\MarinerFactory;

    $_one = (new MarinerFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Skipper */;
}
?>
--EXPECTF--
