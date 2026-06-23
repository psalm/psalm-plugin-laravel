--FILE--
<?php declare(strict_types=1);

use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Regression tests for psalm/psalm-plugin-laravel#1163.
 *
 * loadCount() applies its constraint closure through Builder::callScope():
 *
 *   // loadCount() -> loadAggregate() -> Builder::withAggregate()
 *   // Illuminate\Database\Eloquent\Builder::callScope()
 *   array_unshift($parameters, $this); // $this is the Builder from withAggregate()
 *   $scope(...$parameters);
 *
 * So the closure receives an Eloquent\Builder, NOT a Relation, and a closure
 * that (correctly) type-hints Builder must be accepted.
 *
 * Contrast with load() / loadMissing(): their eager-load path calls
 * $constraints($relation) (Builder::eagerLoadRelation), genuinely passing a
 * Relation — those stubs keep callable(Relation). The clean Relation-closure
 * cases below pin both, so a later "unify the families" edit cannot silently
 * flip them to Builder and re-break Relation-closure users.
 *
 * Scope: among the aggregate methods, only Model::loadCount was stubbed with a
 * typed callable, so it was the only false positive. The rest use loose types
 * (loadMax/loadMin/... keep Laravel's `array|string`, withCount/withAggregate
 * use `mixed`) and Collection::loadCount is unstubbed (no false positive today)
 * — all out of scope here. Laravel's own Collection docblock mistypes this as
 * callable(Relation<*, *, *>); our stub deliberately diverges to Builder, so do
 * not "sync" it back.
 *
 * Uses the autoloadable Shop archetype so the per-model handler is registered.
 */

/**
 * Closure correctly type-hinting Builder — the issue's repro. Must NOT error.
 */
function test_load_count_accepts_builder_closure(Shop $shop): Shop
{
    return $shop->loadCount([
        'workOrders' => static fn (Builder $query): Builder => $query->where('status', 'active'),
    ]);
}

/**
 * Contrast: load() and loadMissing() genuinely receive a Relation, so a
 * Relation-typed closure must stay clean on both. Guards the load/loadCount
 * asymmetry the fix introduces — a blanket "type them all as Builder" edit, or a
 * selective flip of either method, reddens one of these cases.
 */
function test_load_family_accepts_relation_closure(Shop $shop): Shop
{
    $shop->load([
        'workOrders' => static fn (Relation $query): Relation => $query,
    ]);

    return $shop->loadMissing([
        'workOrders' => static fn (Relation $query): Relation => $query,
    ]);
}

/**
 * Plain string / variadic form — must stay clean.
 */
function test_load_count_accepts_string(Shop $shop): Shop
{
    return $shop->loadCount('workOrders');
}

/**
 * Wrong closure param type — must still raise InvalidArgument. This proves the
 * argument stays a typed callable(Builder) and was not loosened to mixed.
 */
function test_load_count_wrong_param_type_errors(Shop $shop): Shop
{
    return $shop->loadCount([
        'workOrders' => static fn (string $query): string => $query,
    ]);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Models\Shop::loadCount expects %s, but %s provided
