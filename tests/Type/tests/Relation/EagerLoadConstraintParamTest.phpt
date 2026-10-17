--FILE--
<?php declare(strict_types=1);

use App\Builders\VehicleBuilder;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/*
 * The constraint closure of an eager-load array entry receives the Relation the key names (the LAST
 * segment of a dot path): Laravel runs `$constraints($relation)` from Builder::eagerLoadRelation().
 * An untyped `$q` becomes that Relation; a declared type is checked against it.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1945
 */

/** Reveals the argument's type in the InvalidArgument message where a closure body is an expression. */
function takesInt(int $value): never
{
    throw new \LogicException((string) $value);
}

/** @param Collection<int, Customer> $customers */
function test_untyped_closure_in_each_call_form(Customer $customer, Collection $customers): void
{
    Customer::query()->with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
    Customer::query()->withOnly(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
    Customer::with(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
    $customer->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
    $customer->loadMissing(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
    $customers->load(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
    $customers->loadMissing(['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
}

/** An arrow function is typed the same way (its storage id is the closure scheme's). */
function test_arrow_function(): void
{
    Customer::query()->with(['vehicles' => fn ($q) => takesInt($q)]);
}

/** A custom builder receiver, and a relation reached from it. */
function test_custom_builder_receiver(): void
{
    Vehicle::query()->with(['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    }]);
}

/** Dot path: the callback applies to the last segment. */
function test_dot_path(Customer $customer): void
{
    Customer::query()->with(['vehicles.workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    }]);
    $customer->load(['vehicles.customer' => function ($q): void {
        /** @psalm-check-type-exact $q = BelongsTo<Customer, Vehicle> */
        $q->where('id', 1);
    }]);
}

/** The nested-array form flattens to the dot path (`prepareNestedWithRelationships()`), `:columns` stripped per key. */
function test_nested_array_and_columns(): void
{
    Customer::query()->with(['vehicles' => ['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    }]]);
    Customer::query()->with(['vehicles:id,customer_id' => ['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    }]]);
    Customer::query()->with(['vehicles' => [
        'workOrders' => ['vehicle' => function ($q): void {
            /** @psalm-check-type-exact $q = BelongsTo<Vehicle, WorkOrder> */
            $q->where('id', 1);
        }],
    ]]);
}

/** Plain relation names and typed siblings in the same literal do not disturb the closure entry. */
function test_mixed_entries_and_named_argument(): void
{
    Vehicle::query()->with(['customer', 'workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    }, 'customer:id']);
    Customer::query()->with(relations: ['vehicles' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }]);
}

/** `with('relation', fn)`: the second argument is the constraint (Builder only). */
function test_string_and_callback_form(): void
{
    Customer::query()->with('vehicles', function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    });
    Customer::query()->with('vehicles.workOrders', function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    });
    Customer::query()->with(callback: function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<Vehicle, Customer> */
        $q->where('year', 2020);
    }, relations: 'vehicles');
}

/** A compatible declared type is kept (a wider Relation or the exact one); no coercion is reported. */
function test_typed_compatible_kept(Customer $customer): void
{
    Customer::query()->with(['vehicles' => function (HasMany $q): void {
        $q->where('year', 2020);
    }]);
    Customer::query()->with(['vehicles' => function (Relation $q): void {
        $q->where('year', 2020);
    }]);
    Customer::with(['vehicles' => function (HasMany $q): void {
        $q->where('year', 2020);
    }]);
    $customer->load(['vehicles' => function (HasMany $q): void {
        $q->where('year', 2020);
    }]);
    $customer->loadMissing(['vehicles.workOrders' => function (HasMany $q): void {
        $q->where('status', 'open');
    }]);
    Customer::query()->with('vehicles', function (HasMany $q): void {
        $q->where('year', 2020);
    });
}

/** A declared type that is not the resolved Relation is reported like a direct closure argument. */
function test_typed_incompatible_reported(Customer $customer): void
{
    Customer::query()->with(['vehicles' => function (BelongsTo $q): void {
        $q->where('id', 1);
    }]);
    $customer->load(['vehicles' => function (BelongsTo $q): void {
        $q->where('id', 1);
    }]);
    Customer::query()->with(['vehicles' => ['workOrders' => function (BelongsTo $q): void {
        $q->where('id', 1);
    }]]);
    Customer::query()->with('vehicles', function (BelongsTo $q): void {
        $q->where('id', 1);
    });
}

/**
 * A non-generic Eloquent Collection subclass names its model through `@extends`.
 *
 * @extends Collection<int, Vehicle>
 */
final class VehicleList extends Collection {}

function test_non_generic_collection_subclass(VehicleList $vehicles): void
{
    $vehicles->load(['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    }]);
    $vehicles->loadMissing(['customer' => function (BelongsTo $q): void {
        $q->where('id', 1);
    }]);
}

/** A Relation receiver forwards `with()` to its related model's builder. */
function test_relation_receiver(Customer $customer): void
{
    $customer->vehicles()->with(['workOrders' => function ($q): void {
        /** @psalm-check-type-exact $q = HasMany<WorkOrder, Vehicle> */
        $q->where('status', 'open');
    }]);
}

/** Inside a model, `$this` is the receiver. */
class CustomerWithEagerLoads extends Customer
{
    public function loadVehicles(): void
    {
        $this->load(['vehicles' => function ($q): void {
            /** @psalm-check-type-exact $q = HasMany<Vehicle, CustomerWithEagerLoads> */
            $q->where('year', 2020);
        }]);
    }
}

/** Plain names, column suffixes and string values beside a typed closure raise no false positive. */
function test_typed_closure_beside_plain_entries(Customer $customer): void
{
    $customer->load(['vehicles:id,customer_id', 'vehicles.workOrders' => function (HasMany $q): void {
        $q->where('status', 'open');
    }, 'vehicles.customer']);
    Customer::with(['vehicles' => ['workOrders' => function (Relation $q): void {
        $q->where('status', 'open');
    }, 'customer']]);
}

/** A closure held in a variable keeps the stub's element type: the literal's own closures are still checked. */
function test_variable_sibling_keeps_stub_element_type(): void
{
    $customer = function (BelongsTo $q): void {
        $q->where('id', 1);
    };
    Vehicle::query()->with(['workOrders' => function (HasMany $q): void {
        $q->where('status', 'open');
    }, 'customer' => $customer]);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of takesInt expects int, but Illuminate\Database\Eloquent\Relations\HasMany<App\Models\Vehicle, App\Models\Customer> provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::with expects array{vehicles?: Closure[impure](Illuminate\Database\Eloquent\Relations\HasMany<App\Models\Vehicle, App\Models\Customer>):mixed, ...<array-key, mixed>}, but array{vehicles: Closure[impure](Illuminate\Database\Eloquent\Relations\BelongsTo):void} provided
InvalidArgument on line %d: Argument 1 of App\Models\Customer::load expects array{vehicles?: Closure[impure](Illuminate\Database\Eloquent\Relations\HasMany<App\Models\Vehicle, App\Models\Customer>):mixed, ...<array-key, array<array-key, mixed>|callable[impure](Illuminate\Database\Eloquent\Relations\Relation<Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Model, mixed>):mixed|string>}, but array{vehicles: Closure[impure](Illuminate\Database\Eloquent\Relations\BelongsTo):void} provided
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::with expects array{vehicles?: array{workOrders?: Closure[impure](Illuminate\Database\Eloquent\Relations\HasMany<App\Models\WorkOrder, App\Models\Vehicle>):mixed, ...<array-key, mixed>}, ...<array-key, mixed>}, but array{vehicles: array{workOrders: Closure[impure](Illuminate\Database\Eloquent\Relations\BelongsTo):void}} provided
InvalidArgument on line %d: Argument 2 of Illuminate\Database\Eloquent\Builder::with expects Closure[impure](Illuminate\Database\Eloquent\Relations\HasMany<App\Models\Vehicle, App\Models\Customer>):mixed, but Closure[impure](Illuminate\Database\Eloquent\Relations\BelongsTo):void provided
