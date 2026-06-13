--FILE--
<?php declare(strict_types=1);

namespace Database\Factories\NoMatch {
    use Illuminate\Database\Eloquent\Factories\Factory;

    /**
     * No `$model` declared, no class matches the convention strip
     * (`App\Models\Unicorn` does not exist anywhere in the codebase).
     * The handler must defer cleanly — without injection, the chain falls
     * back to the populator-derived `TModel = Model` default. We assert on
     * that fallback shape to lock in the no-false-positive contract: a
     * silent wrong binding would surface here as `App\Models\Unicorn`.
     *
     * `MissingTemplateParam` itself is suppressed for any Factory subclass
     * by the existing SuppressHandler (because the populator always fills
     * `template_extended_params[Factory::class]['TModel']` with the parent
     * template's default constraint) — that behavior is outside this
     * handler's contract and not asserted here.
     */
    final class UnicornFactory extends Factory
    {
        /** @return array<string, mixed> */
        #[\Override]
        public function definition(): array
        {
            return [];
        }
    }
}

namespace App\Sandbox\NoMatch {
    use Database\Factories\NoMatch\UnicornFactory;

    $_one = (new UnicornFactory())->create();
    /** @psalm-check-type-exact $_one = \Illuminate\Database\Eloquent\Model */;
}
?>
--EXPECTF--
