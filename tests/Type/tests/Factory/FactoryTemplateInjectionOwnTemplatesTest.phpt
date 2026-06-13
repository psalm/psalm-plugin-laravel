--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Harbor extends Model
    {
    }

    final class Wharf extends Model
    {
    }
}

namespace Database\Factories {
    use App\Models\Harbor;
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Database\Eloquent\Model;

    /**
     * A concrete factory that extends `Factory` directly AND carries its own
     * `@template T of Model` plus an explicit `@extends Factory<T>`.
     * The handler must defer entirely on both guards:
     *
     *   - `template_types` is non-empty (own-templates skip)
     *   - `template_extended_offsets[Factory::class]` is set (alreadyBindsTModel)
     *
     * If a future refactor drops the `template_types` guard but keeps the
     * offsets check, this test still passes — the offsets guard alone would
     * catch it. The assertion below would only fail if BOTH guards were
     * removed and the handler started injecting `Factory<Harbor>` (from the
     * `$model` property) on top of the user's `T` binding.
     *
     * @template T of Model
     * @extends Factory<T>
     */
    final class GenericFactory extends Factory
    {
        protected $model = Harbor::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Harbor {
    use App\Models\Wharf;
    use Database\Factories\GenericFactory;

    // Adversarial framing: `$model = Harbor::class` would tempt a naive
    // handler to inject `Factory<Harbor>`. The caller binds `T = Wharf`.
    // If injection happened, TModel would resolve to `Harbor` here, not
    // `Wharf`. The conditional return resolves to either single or
    // Collection depending on Psalm's TCount resolution, so we assert the
    // union shape — the key signal is the *model* class, not plurality.
    /** @var GenericFactory<Wharf> $factory */
    $factory = new GenericFactory();
    $_one = $factory->create();
    /** @psalm-check-type-exact $_one = \App\Models\Wharf|\Illuminate\Database\Eloquent\Collection<int, \App\Models\Wharf> */;
}
?>
--EXPECTF--
