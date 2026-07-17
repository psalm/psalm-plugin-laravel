--FILE--
<?php declare(strict_types=1);

namespace Database\Factories {
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * No-match: no `$model`, and the shortname (`Phantom`) maps to no scanned
     * Model. The handler resolves nothing and injects nothing — it never
     * guesses a wrong binding. The chain falls back to base `Model` without
     * crashing (`MissingTemplateParam` is already suppressed for bare factory
     * subclasses by SuppressHandler, so no error surfaces either).
     */
    final class PhantomFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\Unresolvable {
    use Database\Factories\PhantomFactory;

    $_one = (new PhantomFactory())->create();
    /** @psalm-check-type-exact $_one = \Illuminate\Database\Eloquent\Model */;
}
?>
--EXPECTF--
