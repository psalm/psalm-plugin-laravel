--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regression for https://github.com/psalm/psalm-plugin-laravel/issues/882.
 *
 * When a public relation method delegates to a private helper that builds the
 * relation (`return $this->workOrdersByStatus('active')`),
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser::findRelationCallInExpr}
 * used to bail at the helper call (not in `FACTORY_TO_RELATION`) and the handler
 * returned null. Psalm then fell back to the untemplated stub default,
 * collapsing the inferred type to `HasMany<Model, Model>` and triggering
 * `MoreSpecificReturnType` / `LessSpecificReturnStatement` against the public
 * method's `@psalm-return HasMany<WorkOrder, self>` declaration.
 *
 * The fixture lives on disk in {@see Shop::activeWorkOrders()} so the
 * registration handler picks it up. Inline-defined models bypass the
 * `class_exists`-gated registration path and would not exercise this handler.
 *
 * Empty `--EXPECTF--` is the regression oracle.
 */

function issue882_helper_delegation_resolves_relation(Shop $shop): HasMany
{
    $relation = $shop->activeWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, Shop> */
    return $relation;
}

function issue882_second_delegation_resolves_relation(Shop $shop): HasMany
{
    $relation = $shop->completedWorkOrders();
    /** @psalm-check-type-exact $relation = HasMany<WorkOrder, Shop> */
    return $relation;
}
?>
--EXPECTF--
