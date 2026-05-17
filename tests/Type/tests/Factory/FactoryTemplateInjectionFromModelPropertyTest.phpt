--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Vessel extends Model
    {
    }
}

namespace Database\Factories {
    use App\Models\Vessel;
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * Factory declares `$model` but omits `@extends Factory<X>`. The plugin
     * should read the `$model` default and inject the binding so chains
     * resolve to the concrete model rather than `MissingTemplateParam`.
     */
    final class VesselFactory extends Factory
    {
        protected $model = Vessel::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Vessel {
    use Database\Factories\VesselFactory;

    $_one = (new VesselFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Vessel */;

    $_many = (new VesselFactory())->count(3)->create();
    /** @psalm-check-type-exact $_many = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Vessel> */;
}
?>
--EXPECTF--
