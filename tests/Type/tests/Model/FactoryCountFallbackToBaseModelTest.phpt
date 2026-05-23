--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Direct exercise of FactoryCountTypeProvider's third-tier Model fallback
 * (Option B in issue #960). Simulates a bare `Factory` receiver — no
 * template params on the type and no `extends Factory<X>` binding on the
 * called class.
 *
 * Without the fallback, the unbound receiver collapses through the stub's
 * `@return Factory<TModel, int|null>` and `make()`'s conditional picks the
 * single-model branch (`TModel = Model` because Psalm uses the template
 * bound when no binding is supplied). With the fallback, the handler still
 * returns `Factory<Model, 2>` so the conditional resolves to the plural
 * branch and the caller gets `Collection<int, Model>` instead of a bare
 * `Model`. Downgraded compared to ModelFactoryMethodTypeProvider's precise
 * resolution, but still iterable — avoids the `PossibleRawObjectIteration`
 * false positive on every `foreach`.
 *
 * The bare `Factory` parameter shape arises in real code via __callStatic
 * on a Facade, a 3rd-party stub lacking template args, etc., and is
 * genuinely outside the user's control.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/960
 */
function bareFactoryReceiver(Factory $f): void
{
    // Tier 1 (template params) and Tier 2 (extends binding) both fail.
    // Tier 3 fallback gives `Factory<Model, 2>`, and `make()` picks the
    // collection branch from the conditional.
    $_collection = $f->count(5)->make();
    /** @psalm-check-type-exact $_collection = \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */;

    // Foreach over the fallback collection result must not trigger
    // PossibleRawObjectIteration — the whole point of the fallback.
    foreach ($f->count(5)->make() as $_model) {
        /** @psalm-check-type-exact $_model = \Illuminate\Database\Eloquent\Model */;
    }
}

?>
--EXPECTF--
