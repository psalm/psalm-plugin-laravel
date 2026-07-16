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

    final class Rampart extends Model
    {
    }
}

namespace Database\Factories {
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Database\Eloquent\Model;

    /**
     * #677 abstract-base pattern: the base carries its own `@template TModel`
     * and re-binds it through `@extends Factory<TModel>`. It is abstract, so
     * the handler skips it (leaves only).
     *
     * @template TModel of Model
     * @extends Factory<TModel>
     */
    abstract class AbstractBastionFactory extends Factory
    {
    }

    /**
     * Leaf whose DIRECT parent is the abstract base, not `Factory`. The
     * direct-parent guard skips it; the populator-derived chain from the
     * annotated `@extends` resolves the model.
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
     * Trap leaf: declares `$model` and OMITS the template arg on the abstract
     * base. A handler ignoring the direct-parent guard would inject
     * `Factory<Citadel>` here. The guard prevents that — direct parent is the
     * abstract base — so the chain collapses to the base's unbound `Model`.
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

    /**
     * Concrete leaf whose DIRECT parent IS `Factory`. The handler injects
     * `Factory<Rampart>` via the shortname convention.
     */
    final class RampartFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\AbstractBase {
    use Database\Factories\BastionFactory;
    use Database\Factories\CitadelFactory;
    use Database\Factories\RampartFactory;

    // Annotated leaf: chain through the abstract base resolves the model.
    $_bastion = (new BastionFactory())->create();
    /** @psalm-check-type-exact $_bastion = \App\Models\Bastion */;

    // Trap leaf: direct-parent guard skips it, collapses to base's `Model`.
    $_citadel = (new CitadelFactory())->create();
    /** @psalm-check-type-exact $_citadel = \Illuminate\Database\Eloquent\Model */;

    // Direct `extends Factory` leaf: handler injects via convention.
    $_rampart = (new RampartFactory())->create();
    /** @psalm-check-type-exact $_rampart = \App\Models\Rampart */;
}
?>
--EXPECTF--
