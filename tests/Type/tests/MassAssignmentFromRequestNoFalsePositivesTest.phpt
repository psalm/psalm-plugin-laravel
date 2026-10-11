--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace App\MassAssignmentFromRequestNoFalsePositives;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
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

/** forceFill()/forceCreate()/forceCreateQuietly() are an explicit author opt-out — out of scope. */
function force_variants_are_not_flagged(Customer $customer, Request $request): void
{
    $customer->forceFill($request->all());
    Customer::query()->forceCreate($request->all());
    Customer::query()->forceCreateQuietly($request->all());
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

/**
 * A model-or-builder union: the receiver may already be an explicit builder, so it is rejected
 * before the Builder/Relation fallback is ever consulted, even though that fallback alone would
 * still resolve Customer off the Builder<Customer> atomic (#1574 bot review round 2).
 *
 * @param Customer|Builder<Customer> $receiver
 */
function model_or_builder_receiver_is_not_flagged(Customer|Builder $receiver, Request $request): void
{
    $receiver->update($request->all());
}

/**
 * ANY argument narrows the result — a keyed all()/input() is not "the whole request" and is not
 * flagged, only the bare, zero-argument form is (#1574 review round 1).
 */
function keyed_forms_are_not_flagged(Request $request): void
{
    Customer::query()->create($request->all(['name']));
    Customer::query()->create($request->input('name'));
}

/**
 * A PropertyFetch receiver's type-based fallback (#1574 review round 1) is not a blanket accept: a
 * property typed as something other than Request still declines, even though it shares the
 * all()-call shape.
 */
final class CollectionHoldingService
{
    public function __construct(private readonly Collection $items)
    {
    }

    public function non_request_property_is_not_flagged(): void
    {
        Customer::query()->create($this->items->all());
    }
}

namespace App\MassAssignmentFromRequestNoFalsePositives\Shadow;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * A userland function named request(), declared in THIS namespace, shadows the global Illuminate
 * helper for every unqualified request() call inside it: PHP falls back to the global namespace only
 * when the current one has no function of that name. The written name alone must not be trusted —
 * only the call's own INFERRED type proves (or here, disproves) it is the real helper (#1574 bot
 * review round 2).
 */
function request(): Collection
{
    return Collection::make(['name' => 'shadow']);
}

function shadowed_request_helper_is_not_flagged(): void
{
    Customer::query()->create(request()->all());
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
MixedArgumentTypeCoercion on line %d: Argument 1 of App\Models\Customer::update expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
MixedArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create cannot be mixed, expecting array<string, mixed>
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<TKey:Illuminate\Support\Collection as array-key, TValue:Illuminate\Support\Collection as mixed> provided
MixedArgumentTypeCoercion on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::create expects array<string, mixed>, but parent type array<array-key, mixed> provided
