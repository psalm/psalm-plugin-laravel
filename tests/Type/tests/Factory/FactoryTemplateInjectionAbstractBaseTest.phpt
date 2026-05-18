--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Bastion extends Model
    {
    }

    final class Citadel extends Model
    {
    }
}

namespace Database\Factories\Bastion {
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Database\Eloquent\Model;

    /**
     * #677 pattern: abstract base factory carries its own `@template TModel`
     * and re-binds it through `@extends Factory<TModel>`. Leaf factories
     * extend the abstract base, not `Factory` directly.
     *
     * The handler must skip every leaf — the direct-parent guard
     * (`parent_class === Factory::class`) short-circuits before any
     * resolution attempt. Re-binding `Factory<TModel>` on the leaf would
     * override the polymorphic chain the abstract base sets up and could
     * surface as `TooManyTemplateParams` (issue task explicitly calls out
     * filament's contravariance pattern as a regression to watch for).
     *
     * @template TModel of Model
     * @extends Factory<TModel>
     */
    abstract class AbstractBastionFactory extends Factory
    {
    }

    /**
     * Properly annotated leaf: the chain through the abstract base resolves
     * the model correctly. Verifies the handler does not interfere.
     *
     * @extends AbstractBastionFactory<\App\Models\Bastion>
     */
    final class BastionFactory extends AbstractBastionFactory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }

    /**
     * Leaf that intentionally OMITS the template arg on the abstract base
     * AND declares `$model` — the configuration that would tempt a naive
     * handler to inject `Factory<Citadel>` and over-collapse the chain.
     * The direct-parent guard prevents that injection: `CitadelFactory`'s
     * direct parent is `AbstractBastionFactory`, not `Factory`, so the
     * handler skips it entirely and the populator-derived chain stays
     * intact. No `TooManyTemplateParams` regression on `Factory`.
     */
    final class CitadelFactory extends AbstractBastionFactory
    {
        protected $model = \App\Models\Citadel::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Bastion {
    use Database\Factories\Bastion\BastionFactory;
    use Database\Factories\Bastion\CitadelFactory;

    $_one = (new BastionFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Bastion */;

    // The trap leaf: CitadelFactory declares `$model = Citadel::class` and
    // extends the abstract base without supplying the template arg. If the
    // handler ignored its direct-parent guard, it would inject
    // `Factory<Citadel>` here and `create()` would resolve to `Citadel`.
    // With the guard, the populator-derived chain stands and the type
    // collapses to the abstract base's unbound TModel (= `Model`).
    $_citadel = (new CitadelFactory())->create();
    /** @psalm-check-type-exact $_citadel = \Illuminate\Database\Eloquent\Model */;
}
?>
--EXPECTF--
