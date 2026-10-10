--FILE--
<?php declare(strict_types=1);

use App\Builders\WorkOrderBuilder;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * RelationCallbackParamsHandler declines (the stub / Laravel signature stands, so `$q` keeps today's type)
 * wherever the callback's builder is not provably ONE model's builder. Each case would change if its own
 * decline gate were removed (the closure param would become the related builder).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 */

/** A union receiver re-analyzes the closure per atomic: no single builder. */
function test_union_receiver_declines(bool $b): void
{
    $builder = $b ? Customer::query() : Supplier::query();
    $builder->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    });
}

/** A non-literal relation name cannot be walked. */
function test_non_literal_relation_name_declines(string $relation): void
{
    Customer::query()->whereHas($relation, function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    });
}

function test_unresolvable_segments_decline(): void
{
    Customer::query()->whereHas('missingRelation', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    });
    Customer::query()->whereHas('vehicles.missingRelation', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    });
    Customer::query()->whereHas('vehicles..workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    });
}

/** A MorphTo has no single related model: plain methods and intermediate segments decline. */
function test_morph_to_declines_for_plain_methods(): void
{
    DamageReport::query()->whereHas('reportable', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
    DamageReport::query()->whereHas('reportable.workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('id', 1);
    });
}

/** A morph method needs a direct MorphTo, with literal types. */
function test_morph_method_declines(string $type): void
{
    Customer::query()->whereHasMorph('vehicles', [Vehicle::class], function ($q, $t): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('type', $t);
    });
    DamageReport::query()->whereHasMorph('reportable.vehicle', [Vehicle::class], function ($q, $t): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('type', $t);
    });
    DamageReport::query()->whereHasMorph('reportable', '*', function ($q, $t): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('type', $t);
    });
    DamageReport::query()->whereHasMorph('reportable', [$type], function ($q, $t): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('type', $t);
    });
    DamageReport::query()->whereHasMorph('reportable', [Vehicle::class, 'unknown-alias'], function ($q, $t): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->where('type', $t);
    });
}

/** A passed-through callable is not a literal: the custom-builder receiver keeps Laravel's `Builder<Model>` hint. */
function test_passed_through_callable_keeps_today_behaviour(): void
{
    $typed = static fn (WorkOrderBuilder $q): WorkOrderBuilder => $q->whereCompleted();
    Vehicle::query()->whereHas('workOrders', $typed);
}

/** Unpacked args cannot be mapped to parameters, even with a literal relation name and closure. */
function test_unpacked_args_decline(): void
{
    Customer::query()->whereHas('vehicles', static function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    }, ...['>=', 1]);
}

/** A model instance forwards via `__call`: its receiver is not a Builder or Relation. */
function test_model_instance_receiver_declines(Customer $customer): void
{
    $customer->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    });
}

/** A variadic first param is filled from the slot alone, so the literal keeps the stub. */
function test_variadic_first_param_declines(): void
{
    Customer::query()->whereHas('vehicles', function (...$args): void {
        /** @psalm-check-type-exact $args = array<array-key, mixed> */
        takes_mixed($args);
    });
}

/**
 * `static`/`self` name no resolvable class, so a static call on them keeps the stub even inside a model subclass.
 */
class LocalCustomer extends Customer
{
    public function staticCalls(): void
    {
        static::whereHas('vehicles', function ($q): void {
            /** @psalm-check-type-exact $q = mixed */
            $q->whereElectric();
        });
        self::whereHas('vehicles', function ($q): void {
            /** @psalm-check-type-exact $q = mixed */
            $q->whereElectric();
        });
    }
}

/**
 * A class that merely forwards to Builder through `@mixin` is not a Builder: its first template argument says
 * nothing about the model, so the call keeps the stub.
 *
 * @template TWhatever
 * @mixin Builder<Customer>
 */
final class BuilderMixinHost
{
    /** @param TWhatever $_value */
    public function __construct(public mixed $_value) {}
}

/** @param BuilderMixinHost<Customer> $host */
function test_mixin_host_receiver_declines(BuilderMixinHost $host): void
{
    $host->whereHas('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->whereElectric();
    });
}

function takes_mixed(mixed $_value): void {}
?>
--EXPECTF--
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $t has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $t has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $t has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $t has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
MissingClosureParamType on line %d: Parameter $q has no provided type
MissingClosureParamType on line %d: Parameter $t has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method where
ArgumentTypeCoercion on line %d: Argument 2 of App\Builders\VehicleBuilder::whereHas expects Closure[impure](Illuminate\Database\Eloquent\Builder<TModel:App\Builders\WorkOrderBuilder as Illuminate\Database\Eloquent\Model>):mixed|null, but parent type Closure[impure](App\Builders\WorkOrderBuilder):App\Builders\WorkOrderBuilder<Illuminate\Database\Eloquent\Model> provided
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $args has no provided type
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method whereElectric
