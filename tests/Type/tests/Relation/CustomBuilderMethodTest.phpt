--FILE--
<?php declare(strict_types=1);

use App\Models\Artist;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\Part;
use App\Models\Shop;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Issue #1262: Relation::__call forwards custom methods to the related model's
 * effective builder and substitutes the Relation only for builder-returning branches.
 */

function test_new_eloquent_builder_fluent_method(): void {
    $_result = (new Customer())->vehicles()->whereElectric()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Vehicle> */
}

function test_use_eloquent_builder_attribute_fluent_method(): void {
    $_result = (new Shop())->workOrders()->whereCompleted();
    /** @psalm-check-type-exact $_result = HasMany<WorkOrder, Shop> */
}

function test_static_builder_property_fluent_method(): void {
    $_result = (new Shop())->mechanics()->whereCertified();
    /** @psalm-check-type-exact $_result = HasManyThrough<Mechanic, Vehicle, Shop> */
}

function test_non_templated_custom_builder_fluent_method(): void {
    // Explicitly reference the related model so psalm-tester scans and registers its
    // metadata before analyzing the relation-only call in this isolated fixture.
    static function (Invoice $_invoice): void {};
    $_result = (new Shop())->latestInvoice()->wherePaid();
    /** @psalm-check-type-exact $_result = HasOne<Invoice, Shop> */
}

function test_method_inherited_from_abstract_custom_builder(): void {
    static function (Artist $_artist): void {};
    $_result = (new Shop())->artists()->accessible();
    /** @psalm-check-type-exact $_result = HasMany<Artist, Shop> */

    $_nullable = (new Shop())->artists()->accessibleOrNull();
    /** @psalm-check-type-exact $_nullable = HasMany<Artist, Shop>|null */
}

function test_terminal_custom_builder_returns(): void {
    $relation = (new Customer())->vehicles();

    $_count = $relation->countByMake('Toyota');
    /** @psalm-check-type-exact $_count = int<0, max> */

    $_first = $relation->firstByMake('Toyota');
    /** @psalm-check-type-exact $_first = Vehicle|null */

    $_collection = $relation->getByMake('Toyota');
    /** @psalm-check-type-exact $_collection = Collection<int, Vehicle> */

    $_void = $relation->recordQuery();
}

function test_builder_union_branch_is_decorated(): void {
    $_result = (new Customer())->vehicles()->maybeWhereElectric(true);
    /** @psalm-check-type-exact $_result = HasMany<Vehicle, Customer>|null */
}

function test_declared_custom_builder_parameters(): void {
    $relation = (new Customer())->vehicles();

    $_named = $relation->whereByOptions(year: 2024, make: 'Toyota');
    /** @psalm-check-type-exact $_named = HasMany<Vehicle, Customer> */

    $first = 'Toyota';
    $_variadic = $relation->whereByMakes($first, 'Honda', 'Volvo');
    /** @psalm-check-type-exact $_variadic = HasMany<Vehicle, Customer> */

    $count = null;
    $_out = $relation->withMatchCount($count);
    /** @psalm-check-type-exact $_out = HasMany<Vehicle, Customer> */
    /** @psalm-check-type-exact $count = int<0, max> */
}

function test_method_templates_degrade_to_declared_bounds(): void {
    // Method-level templates cannot be inferred on the missing-method provider path
    // (argument types are not reliably analyzed yet at this provider's event point), so they
    // resolve to their declared upper bound.
    $_unbounded = (new Customer())->vehicles()->passthrough('Toyota');
    /** @psalm-check-type-exact $_unbounded = mixed */

    $_bounded = (new Customer())->vehicles()->modelPassthrough(new Vehicle());
    /** @psalm-check-type-exact $_bounded = Illuminate\Database\Eloquent\Model */

    $_twoArgs = (new Customer())->vehicles()->chooseModel(new Vehicle(), new Vehicle());
    /** @psalm-check-type-exact $_twoArgs = Illuminate\Database\Eloquent\Model */
}

function test_custom_method_wins_over_query_builder_magic(): void {
    $_result = (new Shop())->mechanics()->groupBy('certification');
    /** @psalm-check-type-exact $_result = int */
}

function test_trait_fluent_methods_are_model_aware(): void {
    $_base = (new Shop())->owner()->onlyTrashed();
    /** @psalm-check-type-exact $_base = BelongsTo<Customer, Shop> */

    $_custom = (new Shop())->workOrders()->withTrashed();
    /** @psalm-check-type-exact $_custom = HasMany<WorkOrder, Shop> */

    $_missing = (new Shop())->parts()->onlyTrashed();
    /** @psalm-check-type-exact $_missing = mixed */
}

function test_custom_builder_params_reject_wrong_type(): void {
    (new Customer())->vehicles()->whereByMake(123);
}

function test_custom_builder_params_reject_wrong_arity(): void {
    (new Customer())->vehicles()->whereByMake();
    (new Customer())->vehicles()->whereByMake('Toyota', 'Honda');
}

function test_custom_builder_param_handoff_is_consumed_once(): void {
    // Custom MechanicBuilder::groupBy accepts exactly one string and populates the
    // HasManyThrough::groupby hand-off, which must be consumed by this call.
    (new Shop())->mechanics()->groupBy('certification');

    // Same relation class and method, different related model: this resolves through
    // Query\Builder's variadic groupBy and must not inherit MechanicBuilder's signature.
    (new Customer())->workOrders()->groupBy('status', 'priority');
}
?>
--EXPECTF--
AssignmentToVoid on line %d: Cannot assign $_void to type void
InvalidArgument on line %d: Argument 1 of %s::wherebymake expects string, but 123 provided
TooFewArguments on line %d: Too few arguments for %s::wherebymake - expecting make to be passed
TooManyArguments on line %d: Too many arguments for %s::wherebymake - expecting 1 but saw 2
