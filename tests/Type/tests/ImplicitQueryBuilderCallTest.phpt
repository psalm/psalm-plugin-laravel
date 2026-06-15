--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace App\RequireQuery;

use App\Models\Customer;
use App\Models\DirectScopeModel;
use App\Models\Invoice;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The opt-in ImplicitQueryBuilderCall rule flags query builder, custom builder, and local
 * scope methods called directly on a model (forwarded by Laravel through __callStatic/__call)
 * and asks for the explicit Model::query()->... form instead. Only registered under the opt-in
 * config used by this test (see --ARGS-- above).
 */

/**
 * A model declaring real methods whose names collide with query builder methods. These are real
 * methods, not magic forwarding, and must not be flagged.
 */
class ShadowingModel extends Model
{
    public static function find(int $id): ?self
    {
        return $id > 0 ? new self() : null;
    }

    public function orderBy(string $column): bool
    {
        return $column !== '';
    }
}

/** Inherits the shadowing methods, so the inherited real method is also not flagged. */
class ChildShadowingModel extends ShadowingModel {}

/** Eloquent\Builder methods, a Query\Builder method, and a forwarded helper are all flagged. */
function static_builder_calls_are_flagged(): void
{
    Customer::where('active', 1);
    Customer::find(1);
    Customer::create();
    Customer::first();
    Customer::whereIn('id', [1, 2]);
}

/** A legacy scopeXxx() invoked by its forwarded bare name is flagged (the User::active() case). */
function static_legacy_scope_is_flagged(): void
{
    Customer::active();
}

/**
 * A protected modern #[Scope] invoked by its forwarded bare name is flagged. The static form of
 * a modern scope also raises InvalidStaticInvocation (an upstream Psalm limitation, see #1042 /
 * vimeo/psalm#11876); both diagnostics agree the direct call should move to query()->active().
 */
function static_modern_scope_is_flagged(): void
{
    DirectScopeModel::active();
}

/**
 * Custom Eloquent builder methods forwarded from the model are flagged, for both registration
 * mechanisms: Vehicle via newEloquentBuilder(), Invoice via the #[UseEloquentBuilder] attribute.
 */
function static_custom_builder_method_is_flagged(): void
{
    Vehicle::whereElectric();
    Invoice::wherePaid();
}

/**
 * A dynamic where{Column}() clause the plugin resolves (Customer has @property string $id) is a
 * forwarded builder call and is flagged. An unresolvable column would instead be a typo left to
 * UndefinedMagicMethod.
 */
function dynamic_where_clause_is_flagged(): void
{
    Customer::whereId('cust-1');
}

/** Instance calls forwarded through __call are flagged too, including custom builder methods. */
function instance_calls_are_flagged(Customer $customer, Vehicle $vehicle): void
{
    $customer->where('active', 1);
    $customer->find(1);
    $vehicle->whereByMake('Toyota');
}

/** The explicit query() entry point is the desired form — the builder receiver is never flagged. */
function explicit_query_is_not_flagged(): void
{
    Customer::query()->where('active', 1)->orderBy('id')->first();
}

/** Real methods declared on the framework Model base are not magic forwarding — not flagged. */
function real_framework_methods_are_not_flagged(Customer $customer): void
{
    Customer::all();
    Customer::query();
    $customer->save();
}

/** A method on a non-model class (here Collection::make) is not flagged. */
function non_model_calls_are_not_flagged(): void
{
    Collection::make([1, 2, 3]);
}

/**
 * A real method declared on (or inherited by) the model is not flagged, even when its name
 * collides with a query builder method — it is a real method, not magic forwarding.
 */
function real_colliding_methods_are_not_flagged(ShadowingModel $model, ChildShadowingModel $child): void
{
    ShadowingModel::find(1);
    $model->orderBy('name');
    $child->orderBy('name');
}

/**
 * An ambiguous union receiver (two distinct models here) gives no single class to name and may
 * already be a builder, so it is not flagged.
 */
function ambiguous_union_receiver_is_not_flagged(Customer|Vehicle $model): void
{
    $model->where('active', 1);
}

/**
 * A model-or-builder union: the receiver may already be an explicit builder, so the call is not
 * flagged (the Builder atomic makes the receiver ambiguous).
 *
 * @param Customer|Builder<Customer> $receiver
 */
function model_or_builder_receiver_is_not_flagged(Customer|Builder $receiver): void
{
    $receiver->where('active', 1);
}

/**
 * A trait builder macro on a plain base-Builder model (SoftDeletes::withTrashed on Customer) is
 * a runtime macro the plugin does not resolve as forwarded, so it is not flagged (and not a typo).
 */
function softdeletes_macro_is_not_flagged(): void
{
    Customer::withTrashed();
}

/**
 * A relation receiver is skipped — `$customer->vehicles()` is a real model method (not flagged),
 * and the `where()` chained on the resulting HasMany has a non-model receiver, so neither is
 * flagged. Covers both the real-user-method non-flag path and the relation-receiver skip.
 */
function relation_receiver_is_not_flagged(Customer $customer): void
{
    $customer->vehicles()->where('vin', 'X');
}

/**
 * A genuine direct scope call — a public scope passed the builder explicitly (scope composition)
 * — invokes the real method and is not magic forwarding, so it is not flagged.
 *
 * @param Builder<DirectScopeModel> $query
 */
function direct_scope_call_is_not_flagged(DirectScopeModel $model, Builder $query): void
{
    $model->hasAnyName($query, ['a', 'b']);
}

/**
 * A genuinely undefined method is left to Psalm's UndefinedMagicMethod, not mislabelled as a
 * "use query()" suggestion: the rule only flags calls Laravel actually resolves by forwarding.
 */
function undefined_method_is_not_mislabelled(Customer $customer): void
{
    $customer->undefinedBuilderMethod();
}
?>
--EXPECTF--
ImplicitQueryBuilderCall on line %d: Avoid calling where() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->where(...).
ImplicitQueryBuilderCall on line %d: Avoid calling find() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->find(...).
ImplicitQueryBuilderCall on line %d: Avoid calling create() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->create(...).
ImplicitQueryBuilderCall on line %d: Avoid calling first() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->first(...).
ImplicitQueryBuilderCall on line %d: Avoid calling whereIn() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->whereIn(...).
ImplicitQueryBuilderCall on line %d: Avoid calling active() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->active(...).
ImplicitQueryBuilderCall on line %d: Avoid calling active() directly on the DirectScopeModel model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. DirectScopeModel::query()->active(...).
InvalidStaticInvocation on line %d: Method App\Models\DirectScopeModel::active is not static, but is called statically
ImplicitQueryBuilderCall on line %d: Avoid calling whereElectric() directly on the Vehicle model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Vehicle::query()->whereElectric(...).
ImplicitQueryBuilderCall on line %d: Avoid calling wherePaid() directly on the Invoice model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Invoice::query()->wherePaid(...).
ImplicitQueryBuilderCall on line %d: Avoid calling whereId() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->whereId(...).
ImplicitQueryBuilderCall on line %d: Avoid calling where() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->where(...).
ImplicitQueryBuilderCall on line %d: Avoid calling find() directly on the Customer model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Customer::query()->find(...).
ImplicitQueryBuilderCall on line %d: Avoid calling whereByMake() directly on the Vehicle model: the call is forwarded through Laravel's __callStatic/__call magic to the query builder. Use an explicit query entry point instead, e.g. Vehicle::query()->whereByMake(...).
UndefinedMagicMethod on line %d: Magic method App\Models\Customer::undefinedbuildermethod does not exist
