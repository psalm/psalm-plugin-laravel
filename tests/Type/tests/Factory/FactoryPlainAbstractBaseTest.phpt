--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Alpha extends Model
    {
    }

    final class Beta extends Model
    {
    }
}

namespace Database\Factories {
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * The common house base: abstract, no docblock, no `@extends`. `TModel` has no default, so Psalm reports
     * the base itself, once; the leaves below extend a class that has no templates and report nothing.
     */
    abstract class BaseFactory extends Factory
    {
    }

    /**
     * Resolvable by the `Database\Factories\AlphaFactory` -> `App\Models\Alpha` convention, but
     * FactoryModelBindingHandler only binds a leaf whose DIRECT parent is `Factory` (the #677 abstract-base
     * guard), so the leaf keeps the base's unbound `Model` and the count default's union.
     */
    final class AlphaFactory extends BaseFactory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }

    /** `$model` does not change that: the guard skips every leaf behind an abstract base. */
    final class GammaFactory extends BaseFactory
    {
        protected $model = \App\Models\Beta::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }

    /** No `$model` and no convention match: the same unbound result. */
    final class OrphanFactory extends BaseFactory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\PlainAbstractBase {
    use Database\Factories\AlphaFactory;
    use Database\Factories\GammaFactory;
    use Database\Factories\OrphanFactory;

    $_alphaMake = (new AlphaFactory())->make();
    /** @psalm-check-type-exact $_alphaMake = \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */;

    $_alphaCreate = (new AlphaFactory())->create();
    /** @psalm-check-type-exact $_alphaCreate = \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */;

    $_gammaMake = (new GammaFactory())->make();
    /** @psalm-check-type-exact $_gammaMake = \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */;

    $_gammaCreate = (new GammaFactory())->create();
    /** @psalm-check-type-exact $_gammaCreate = \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */;

    $_orphanMake = (new OrphanFactory())->make();
    /** @psalm-check-type-exact $_orphanMake = \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */;

    $_orphanCreate = (new OrphanFactory())->create();
    /** @psalm-check-type-exact $_orphanCreate = \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */;
}
?>
--EXPECTF--
MissingTemplateParam on line %d: Database\Factories\BaseFactory has missing template params when extending Illuminate\Database\Eloquent\Factories\Factory, expecting at least 1
