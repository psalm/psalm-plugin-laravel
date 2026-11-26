--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\PsalmStarProjectionCapability::skipUnlessSupported();
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `getQuery()` of a `HasMany<*, X>` is `Builder<Model>`, not `Builder<*>`, and no handler is involved:
 * the stub declares `@return Builder<TRelatedModel>` and `Builder::TModel` covariant, and Psalm reads a
 * `*` bound to a covariant argument as the bound of its template (#2380, item 4). The cost is the
 * hole every upcast `Builder<Customer>` already has: `mergeConstraintsFrom(Builder<TModel>)` accepts
 * any model's builder, where `Builder<*>` would reject it. Closing it needs an invariant `TModel`.
 *
 * Skipped on a Psalm without star projections.
 */

/** @param Builder<Model> $_builder */
function takes_model_builder(Builder $_builder): void {}

/** @param Builder<*> $_builder */
function takes_any_builder(Builder $_builder): void {}

/**
 * @param HasMany<*, Customer> $relation
 * @param Builder<*> $any
 * @param Builder<Model> $known
 */
function star_in_a_covariant_argument_reads_the_bound(HasMany $relation, Builder $any, Builder $known): void
{
    $_query = $relation->getQuery();
    /** @psalm-check-type-exact $_query = Builder<Model> */

    takes_model_builder($relation->getQuery());
    takes_any_builder($relation->getQuery());
    takes_model_builder($any);
    takes_any_builder($known);
}
--EXPECTF--
