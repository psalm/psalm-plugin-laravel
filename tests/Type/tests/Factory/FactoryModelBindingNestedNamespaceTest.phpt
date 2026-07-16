--FILE--
<?php declare(strict_types=1);

namespace App {
    use Illuminate\Database\Eloquent\Model;

    // Laravel-correct target: flat `{AppNs}{flatBase}` (candidate 2).
    final class User extends Model
    {
    }
}

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    // Decoy: flat under `Models\` — the removed, wrong candidate. It must NOT
    // win over the real `App\User`.
    final class User extends Model
    {
    }
}

namespace Database\Factories\Admin {
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * Nested factory. Laravel's default resolver tries exactly two candidates:
     *   1. `App\Models\Admin\User` (nested under Models) — absent here.
     *   2. `App\User` (flat factory basename, no `Models\`) — the match.
     * The old handler also tried `App\Models\User`, which would wrongly match
     * the decoy above; the fix drops it. Mirrors Factory::modelName().
     */
    final class UserFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Nested {
    use Database\Factories\Admin\UserFactory;

    $_one = (new UserFactory())->create();
    /** @psalm-check-type-exact $_one = \App\User */;
}
?>
--EXPECTF--
