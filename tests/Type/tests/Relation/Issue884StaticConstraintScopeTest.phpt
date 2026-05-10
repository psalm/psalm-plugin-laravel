--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Part;
use App\Models\Shop;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/884.
 *
 * A wrapper relation method whose body re-enters the chain via
 * `$this->existingRelation()->wherePivot(...)` (or any fluent chained method that
 * returns `$this`) currently produces an outer `Rel<...>&static`. The issue
 * thread argued this would block concrete-class declarations like
 * `@psalm-return BelongsToMany<Part, self, Pivot, 'pivot'>`. On current master
 * concrete declarations DO match the `&static`-on-outer form (Psalm absorbs the
 * intersection through the assignment), so this is a regression-guard test
 * pinning the user-visible contract: declaring a concrete-class return type on a
 * wrapper that chains off `$this->relation()` must not raise
 * `InvalidReturnStatement` / `LessSpecificReturnStatement`.
 *
 * The wrapper-on-`$this` shape is exercised by autoloaded fixtures
 * {@see Shop::suggestedParts()} (BelongsToMany + wherePivot) and
 * {@see Shop::recentWorkOrders()} (HasMany + where). Inline-defined classes
 * would bypass {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}'s
 * `class_exists`-gated registration, so the on-disk fixture is required.
 *
 * The external-caller cases below (free functions taking a Model instance)
 * cover a sibling shape: same chain shape but starting from `$workOrder->...`
 * rather than `$this->...`.
 *
 * Empty `--EXPECTF--` is the regression oracle for both shapes.
 */

// --- Wrapper-on-$this shape: invoke the Shop fixtures so the docblock-vs-body
//     match is actually exercised. The `@psalm-check-type-exact` pins the
//     handler-emitted Union (without `&static` on the outer) because the handler
//     emits the relation type for the outermost call before `wherePivot()`/
//     `where()` returns `$this` and adds the intersection.

function issue884_wrapper_on_this_belongsToMany_concrete_decl(Shop $shop): BelongsToMany
{
    $relation = $shop->suggestedParts();
    /** @psalm-check-type-exact $relation = BelongsToMany<Part, Shop, Pivot, 'pivot'> */
    return $relation;
}

function issue884_wrapper_on_this_hasMany_concrete_decl(Shop $shop): HasMany
{
    $relation = $shop->recentWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, Shop> */
    return $relation;
}

// --- External-caller shape: declared concrete-class return must still hold
//     against the chain's `&static`-on-outer inferred form.

/** @return BelongsToMany<Part, WorkOrder, Pivot, 'pivot'> */
function issue884_concrete_class_declaration_after_wherePivot(WorkOrder $workOrder): BelongsToMany
{
    return $workOrder->parts()->wherePivot('quantity', 1);
}

/** @return HasMany<Vehicle, Customer> */
function issue884_concrete_class_declaration_after_where(Customer $customer): HasMany
{
    return $customer->vehicles()->where('active', true);
}
?>
--EXPECTF--
