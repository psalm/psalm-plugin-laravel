--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Negative coverage for https://github.com/psalm/psalm-plugin-laravel/issues/1613.
 *
 * Delegated relation bodies the parser cannot prove decline (the return-type handler yields null),
 * so Psalm's own inference stays: a declared non-relation type, a chain that changes the relation
 * class (`->one()`, declared as the new class, as a `Relation` supertype or as a union), an earlier
 * conditional return (in the delegating body, in the delegated helper, or between two factory
 * calls), a chain call that may swap the relation (`when()`) or leave it (`getRelated()`), a
 * helper with no parseable relation body, a delegation cycle, and a trait method picked with
 * `insteadof`, directly or through nested traits (Psalm records the losing trait's body).
 */

function issue1613_non_relation_return_stays(Shop $shop): int
{
    $total = $shop->workOrderTotal();
    /** @psalm-check-type-exact $total = int */
    return $total;
}

function issue1613_relation_class_change_declines(Shop $shop): HasOne
{
    $relation = $shop->firstWorkOrder();
    /** @psalm-check-type-exact $relation = HasOne<Model, Model> */
    return $relation;
}

function issue1613_declared_supertype_declines(Shop $shop): Relation
{
    $relation = $shop->onlyWorkOrder();
    /** @psalm-check-type-exact $relation = Relation<Model, Model, mixed> */
    return $relation;
}

function issue1613_declared_union_declines(Shop $shop): HasMany|HasOne
{
    $relation = $shop->workOrderOrFirst();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model>|HasOne<Model, Model> */
    return $relation;
}

function issue1613_conditional_return_declines(Shop $shop): HasMany
{
    $relation = $shop->workOrdersOrInvoices();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_when_chain_declines(Shop $shop): HasMany
{
    $relation = $shop->conditionalWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_get_related_chain_declines(Shop $shop): HasMany
{
    $relation = $shop->workOrderRevisions();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_insteadof_trait_declines(Shop $shop): HasMany
{
    $relation = $shop->revisions();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_nested_insteadof_trait_declines(Supplier $supplier): HasMany
{
    $relation = $supplier->revisions();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_helper_early_return_declines(Shop $shop): HasMany
{
    $relation = $shop->invoicesViaHelper();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_direct_early_return_declines(Shop $shop): HasMany
{
    $relation = $shop->invoicesOrWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_unparseable_helper_declines(Shop $shop): HasMany
{
    $relation = $shop->namedWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_cycle_declines(Shop $shop): HasMany
{
    $relation = $shop->cyclicWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}

function issue1613_cycle_partner_declines(Shop $shop): HasMany
{
    $relation = $shop->mirroredWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<Model, Model> */
    return $relation;
}
?>
--EXPECTF--
