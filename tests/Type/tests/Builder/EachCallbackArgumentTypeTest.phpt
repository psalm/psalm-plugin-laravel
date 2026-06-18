--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\MechanicSpecialization;
use App\Models\Vehicle;
use App\Models\WorkOrder;

/**
 * Regression guard for https://github.com/psalm/psalm-plugin-laravel/issues/793
 *
 * `Builder::each()` (and the Relation overrides) used to raise a false-positive
 * `ArgumentTypeCoercion` when the callback (a) narrowed the model param to the
 * concrete Builder<TModel> subclass, or (b) omitted the trailing `int` param that
 * Laravel's `@param callable(TValue, int): mixed` declares.
 *
 * Root cause in the issue was that `TValue` (from the `BuildsQueries` trait) did not
 * bind to the Builder's `TModel`, so it resolved to the trait default `Model`, and a
 * `callable(Customer)` is narrower than the expected `callable(Model, int)`.
 *
 * On the currently supported toolchain (Laravel 13, Psalm 6) `TValue` binds to `TModel`
 * and Psalm accepts a callback with fewer params (PHP discards extra runtime args), so
 * none of these patterns error. The genuine true-positive (wrong model type) must still
 * be reported.
 */

/** Canonical issue case: narrowed param + omitted int param. */
function test_each_narrowed_param_omits_int(): void
{
    Customer::query()->where('active', true)->each(static function (Customer $c): void {
        $c->getKey();
    });
}

/** Runtime contract: the two-param `(TModel, int)` form is also accepted. */
function test_each_two_param_callback(): void
{
    Customer::query()->each(static function (Customer $c, int $i): bool {
        return $c->getKey() === $i;
    });
}

/** Relation override (BelongsToMany::each) binds TValue to the related model. */
function test_belongs_to_many_each(Mechanic $mechanic): void
{
    $mechanic->specializations()->each(static function (MechanicSpecialization $s): void {
        $s->getKey();
    });
}

/** Relation override (HasManyThrough::each) binds TValue to the related model. */
function test_has_many_through_each(Customer $customer): void
{
    $customer->workOrders()->each(static function (WorkOrder $w): void {
        $w->getKey();
    });
}

/** True positive preserved: a callback typed for an unrelated model must still error. */
function test_each_wrong_model_type_still_errors(): void
{
    Customer::query()->each(static function (Vehicle $v): void {
        $v->getKey();
    });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::each expects callable(App\Models\Customer, int):mixed, but impure-Closure(App\Models\Vehicle):void provided
