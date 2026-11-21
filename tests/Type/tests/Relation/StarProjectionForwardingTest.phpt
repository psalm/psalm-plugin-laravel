--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\PsalmStarProjectionCapability::skipUnlessSupported();
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A `*` type argument of a relation is a marker on the receiver's atomic, not part of its
 * type-argument list. The forwarding handler used to rebuild the relation from that list, which
 * turned `HasMany<*, Customer>` into `HasMany<Model, Customer>`: the unknown model became a known
 * one, and writes the receiver rejects were accepted again.
 *
 * Skipped on a Psalm without star projections.
 */

/** @param HasMany<Model, Customer> $_relation */
function takes_model_relation(HasMany $_relation): void {}

/**
 * Path 1: `where()` reaches the relation through its `@mixin Builder`.
 *
 * @param HasMany<*, Customer> $relation
 */
function star_related_model_through_mixin(HasMany $relation): void
{
    $_result = $relation->where('id', 1);
    /** @psalm-check-type-exact $_result = HasMany<*, Customer> */
}

/**
 * Path 2: `limit()` reaches the relation through its `__call`.
 *
 * @param Relation<*, Customer, mixed> $relation
 */
function star_related_model_through_call(Relation $relation): void
{
    $_where = $relation->where('id', 1);
    /** @psalm-check-type-exact $_where = Relation<*, Customer, mixed> */

    $_limit = $relation->limit(3);
    /** @psalm-check-type-exact $_limit = Relation<*, Customer, mixed> */
}

/**
 * A `*` in another slot stays a `*`, and the known slot stays known.
 *
 * @param HasMany<Vehicle, *> $relation
 */
function star_declaring_model(HasMany $relation): void
{
    $_result = $relation->where('id', 1);
    /** @psalm-check-type-exact $_result = HasMany<Vehicle, *> */
}

/**
 * Without a `*` the forwarded relation is exactly what it was.
 *
 * @param HasMany<Vehicle, Customer> $relation
 */
function no_star(HasMany $relation): void
{
    $_where = $relation->where('id', 1);
    /** @psalm-check-type-exact $_where = HasMany<Vehicle, Customer> */

    $_limit = $relation->limit(3);
    /** @psalm-check-type-exact $_limit = HasMany<Vehicle, Customer>&static */
}

/**
 * The write the `*` forbids is still forbidden after a forwarded call: the unknown related model
 * is not `Model`, so neither the receiver nor its `where()` result fits `HasMany<Model, Customer>`.
 *
 * @param HasMany<*, Customer> $relation
 */
function star_write_is_rejected(HasMany $relation): void
{
    takes_model_relation($relation);
    takes_model_relation($relation->where('id', 1));
}
--EXPECTF--
ArgumentTypeCoercion on line %d: Argument 1 of takes_model_relation expects Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, App\Models\Customer>, but parent type Illuminate\Database\Eloquent\Relations\HasMany<*, App\Models\Customer> provided
ArgumentTypeCoercion on line %d: Argument 1 of takes_model_relation expects Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, App\Models\Customer>, but parent type Illuminate\Database\Eloquent\Relations\HasMany<*, App\Models\Customer> provided
