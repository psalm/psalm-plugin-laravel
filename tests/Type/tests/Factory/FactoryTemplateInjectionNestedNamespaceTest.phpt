--FILE--
<?php declare(strict_types=1);

namespace App\Models\Auth {
    use Illuminate\Database\Eloquent\Model;

    final class Sentinel extends Model
    {
    }
}

namespace Database\Factories\Auth {
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * Nested factory namespace pattern: `Database\Factories\Auth\SentinelFactory`
     * → `App\Models\Auth\Sentinel`. Mirrors Laravel's
     * `Str::replaceFirst('Database\\Factories\\', '', $fqcn)` then suffix
     * strip then prefix with `{AppNs}Models\`. This is the layout pterodactyl
     * and larger filament installs use.
     */
    final class SentinelFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Sentinel {
    use Database\Factories\Auth\SentinelFactory;

    $_one = (new SentinelFactory())->create();
    /** @psalm-check-type-exact $_one = \App\Models\Auth\Sentinel */;

    $_many = (new SentinelFactory())->count(3)->create();
    /** @psalm-check-type-exact $_many = \Illuminate\Database\Eloquent\Collection<int, \App\Models\Auth\Sentinel> */;
}
?>
--EXPECTF--
