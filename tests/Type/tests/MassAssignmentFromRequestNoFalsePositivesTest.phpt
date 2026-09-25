--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace App\MassAssignmentFromRequestNoFalsePositives;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Every case below must never emit MassAssignmentFromRequest. Static calls go through the explicit
 * Customer::query()->... form so the unrelated (already-covered) ImplicitQueryBuilderCall finding,
 * which this same opt-in config also enables, does not have to be asserted here too.
 * MixedArgumentTypeCoercion lines that remain are pre-existing plugin behaviour independent of this
 * rule (see MassAssignmentFromRequestTest.phpt's docblock) and are asserted, not hidden.
 */

/** validated() is the recommended fix, not the violation — never flagged. */
function validated_is_not_flagged(Customer $customer, FormRequest $request): void
{
    $customer->fill($request->validated());
}

/** safe()->only([...]) is key-filtered — never flagged, even though safe()->all() would still be raw. */
function safe_only_is_not_flagged(FormRequest $request): void
{
    Customer::query()->create($request->safe()->only(['name']));
}

/** only()/except() are key-filtered forms of the same request — never flagged. */
function only_and_except_are_not_flagged(Request $request): void
{
    Customer::query()->create($request->only(['name']));
    Customer::query()->create($request->except(['password']));
}

/** forceFill()/forceCreate() are an explicit author opt-out — deliberately out of scope. */
function force_variants_are_not_flagged(Customer $customer, Request $request): void
{
    $customer->forceFill($request->all());
    Customer::query()->forceCreate($request->all());
}

/**
 * A variable whose provenance is not proven (a plain typed parameter, no assignment in this
 * function at all) is never flagged — the rule only follows a direct call or one local hop.
 */
function unproven_variable_is_not_flagged(array $attributes): void
{
    Customer::query()->create($attributes);
}

/**
 * A local variable reassigned after the request read (three occurrences, not two) breaks the
 * one-hop proof and is not flagged.
 */
function reassigned_variable_is_not_flagged(Request $request): void
{
    $data = $request->all();
    $data = array_merge($data, ['source' => 'web']);
    Customer::query()->create($data);
}

/**
 * Collection::all() has the identical .all() call shape but a non-Request receiver — the rule is
 * provenance-restricted to Illuminate\Http\Request (and subclasses), so this is not flagged.
 */
function non_request_all_receiver_is_not_flagged(): void
{
    Customer::query()->create(Collection::make(['name' => 'x'])->all());
}

/**
 * An ambiguous union receiver (two distinct models) gives no single class to name, mirroring
 * UnknownModelAttributeHandler's own receiver gate — not flagged.
 */
function ambiguous_union_receiver_is_not_flagged(Customer|Vehicle $model, Request $request): void
{
    $model->fill($request->all());
}
?>
--EXPECTF--
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Customer::forceFill expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::forceCreate expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array{source: 'web', ...<array-key, mixed>} provided
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Customer::fill expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Vehicle::fill expects array<string, mixed>, but parent type array<array-key, mixed> provided
