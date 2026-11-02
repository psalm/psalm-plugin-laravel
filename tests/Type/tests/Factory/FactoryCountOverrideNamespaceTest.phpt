--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\CountOverrideNamespace {
    use Illuminate\Database\Eloquent\Model;

    final class User extends Model
    {
    }
}

// A class living under Laravel's factory namespace is still a user class: trust is the exact `Factory` class.
namespace Illuminate\Database\Eloquent\Factories {
    use App\Sandbox\CountOverrideNamespace\User;
    use Illuminate\Database\Eloquent\Collection;

    /** @extends Factory<User> */
    abstract class LookalikeFactory extends Factory
    {
        /** @return static */
        #[\Override]
        public function count(?int $count)
        {
            return $this;
        }
    }

    final class SneakyFactory extends LookalikeFactory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }

    $_sneaky = SneakyFactory::new()->count(3)->make();
    /** @psalm-check-type-exact $_sneaky = User|Collection<int, User> */;
}
?>
--EXPECTF--
