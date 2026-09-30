--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace App\MassAssignmentFromRequest;

use App\Models\Customer;
use App\Models\UnguardedModel;
use Illuminate\Http\Request;

/**
 * The opt-in MassAssignmentFromRequest rule flags create()/fill()/update() calls whose argument's
 * provenance is proven to be raw, unfiltered request data. Only registered under the opt-in config
 * used by this test (see --ARGS-- above). See https://github.com/psalm/psalm-plugin-laravel/issues/1574.
 *
 * Every case but one goes through the explicit Customer::query()->... form to keep this test's
 * output free of the unrelated (already-covered) ImplicitQueryBuilderCall finding, which this same
 * opt-in config also enables and which fires on every bare, magic-forwarded static call regardless
 * of its argument. static_create_is_flagged() below is the one exception, covering the true
 * StaticCall receiver branch on purpose. MixedArgumentTypeCoercion lines are pre-existing plugin
 * behaviour independent of this rule (the *.phpstub stubs type the attribute map param
 * `array<string, mixed>`, stricter than the plain `array` the request-input methods return) and are
 * asserted alongside the rule's own finding rather than hidden.
 */

/** Instance fill() with $request->all() directly is flagged. */
function instance_fill_is_flagged(Customer $customer, Request $request): void
{
    $customer->fill($request->all());
}

/** Static create() with the request() helper's all() is flagged — the true StaticCall receiver. */
function static_create_is_flagged(): void
{
    Customer::create(request()->all());
}

/** Builder-forwarding update() (Model::query()->update(...)) is flagged. */
function builder_update_is_flagged(): void
{
    Customer::query()->update(request()->all());
}

/** Relation-forwarding create() ($customer->vehicles()->create(...)) is flagged. */
function relation_create_is_flagged(Customer $customer, Request $request): void
{
    $customer->vehicles()->create($request->all());
}

/** The query InputBag property form ($request->query->all()) is flagged. */
function query_bag_is_flagged(Request $request): void
{
    Customer::query()->create($request->query->all());
}

/** The request InputBag property form ($request->request->all()) is flagged. */
function request_bag_is_flagged(Request $request): void
{
    Customer::query()->create($request->request->all());
}

/** The json() form ($request->json()->all()) is flagged. */
function json_is_flagged(Request $request): void
{
    Customer::query()->create($request->json()->all());
}

/**
 * A simple local-assignment hop is still flagged: $data is assigned exactly once, as a direct
 * top-level statement, before the call, and read nowhere else.
 */
function local_assignment_hop_is_flagged(Request $request): void
{
    $data = $request->all();
    Customer::query()->create($data);
}

/**
 * A model with no $fillable/$guarded restriction gets the extra unguarded-severity wording
 * appended to the same finding, not a separate issue type.
 */
function unguarded_model_gets_suffix(Request $request): void
{
    UnguardedModel::query()->create($request->all());
}

/**
 * A PropertyFetch receiver not literally named query/request still resolves through the type-based
 * check: $this->request here is a property directly typed Request (constructor-promoted), not one
 * of Symfony's InputBag properties, and must not decline just because its var ($this) isn't a
 * Request itself (#1574 review round 1).
 */
final class RequestHoldingService
{
    public function __construct(private readonly Request $request)
    {
    }

    public function fill_via_injected_property_is_flagged(Customer $customer): void
    {
        $customer->fill($this->request->all());
    }
}

/**
 * input()/post()/query() called with NO arguments return the full request payload, the identical
 * hole as all() (#1574 review round 1).
 */
function bare_input_methods_are_flagged(Request $request): void
{
    Customer::query()->create($request->input());
    Customer::query()->create($request->post());
    Customer::query()->create($request->query());
}

/**
 * createQuietly()/updateQuietly()/updateOrFail() share the same shape as create()/update() and are
 * flagged too (#1574 bot review round 2 — these three were untested).
 */
function quietly_and_or_fail_variants_are_flagged(Customer $customer, Request $request): void
{
    Customer::query()->createQuietly($request->all());
    $customer->updateQuietly($request->all());
    $customer->updateOrFail($request->all());
}
?>
--EXPECTF--
MassAssignmentFromRequest on line %d: Customer::fill() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Customer::fill expects array<string, mixed>, but parent type array<array-key, mixed> provided
ImplicitQueryBuilderCall on line %d: Customer::create() is forwarded to the query builder through Laravel's __callStatic/__call magic. Use Customer::query()->create(...) instead.
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MassAssignmentFromRequest on line %d: Customer::update() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Vehicle::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Vehicle — use $request->validated() or $request->safe()->only([...]) instead.
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Relations\HasMany::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MassAssignmentFromRequest on line %d: UnguardedModel::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to UnguardedModel — use $request->validated() or $request->safe()->only([...]) instead. UnguardedModel declares no $fillable or $guarded restriction, so every column is writable this way.
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MassAssignmentFromRequest on line %d: Customer::fill() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Customer::fill expects array<string, mixed>, but parent type array<array-key, mixed> provided
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Customer::create() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Customer::createQuietly() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MassAssignmentFromRequest on line %d: Customer::updateQuietly() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Customer::updateQuietly expects array<string, mixed>, but parent type array<array-key, mixed> provided
MassAssignmentFromRequest on line %d: Customer::updateOrFail() mass-assigns raw request data. An attacker can add any key to the request and have it written to Customer — use $request->validated() or $request->safe()->only([...]) instead.
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Customer::updateOrFail expects array<string, mixed>, but parent type array<array-key, mixed> provided
