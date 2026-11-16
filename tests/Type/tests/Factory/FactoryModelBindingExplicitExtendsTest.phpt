--FILE--
<?php declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    final class Beacon extends Model
    {
    }

    final class Pylon extends Model
    {
    }
}

namespace Database\Factories {
    use App\Models\Beacon;
    use App\Models\Pylon;
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * Explicit `@extends Factory<Pylon>` even though `$model = Beacon::class`
     * and the shortname would point elsewhere. The user binding must win — the
     * handler's `isDirectFactorySubclass()` guard detects the bound TModel and
     * skips injection. Psalm 7.6 gives `$model` the parent's `@var class-string<TModel>`
     * with TModel bound to Pylon, so the contradicting default is reported too.
     *
     * @extends Factory<Pylon>
     */
    final class BeaconFactory extends Factory
    {
        protected $model = Beacon::class;

        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Explicit {
    use Database\Factories\BeaconFactory;

    $_one = (new BeaconFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Pylon */;
}
?>
--EXPECTF--
InvalidPropertyAssignmentValue on line %d: $this->model with declared type 'class-string<App\Models\Pylon>' cannot be assigned type 'App\Models\Beacon::class'
