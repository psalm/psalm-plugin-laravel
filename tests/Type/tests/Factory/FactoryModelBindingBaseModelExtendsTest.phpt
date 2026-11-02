--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Reactor extends Model
    {
    }
}

namespace Database\Factories {
    use App\Models\Reactor;
    use Illuminate\Database\Eloquent\Factories\Factory;
    use Illuminate\Database\Eloquent\Model;

    /**
     * Explicit `@extends Factory<Model>` — a deliberate polymorphic base bound
     * to bare `Model`, even though `$model = Reactor::class` and the shortname
     * would both point at a concrete model. The explicit docblock is a real
     * user contract (Psalm sets template_type_extends_count when it is present),
     * so the handler must NOT override it back to `Reactor`.
     *
     * @extends Factory<Model>
     */
    final class ReactorFactory extends Factory
    {
        protected $model = Reactor::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\BaseModelExtends {
    use Database\Factories\ReactorFactory;

    $_one = (new ReactorFactory())->create();
    /** @psalm-check-type-exact $_one = \Illuminate\Database\Eloquent\Model */;
}
?>
--EXPECTF--
