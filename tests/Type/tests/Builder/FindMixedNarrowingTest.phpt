--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Regression for #975. `Builder::find($mixed)` (and `findOrFail` / `findOrNew`)
 * previously widened to `Collection<int, TModel>|TModel|null` because the
 * stub uses a template conditional:
 *
 *   `psalm-return (T is (array|Arrayable) ? Collection<int, TModel> : TModel|null)`
 *
 * When the caller passes `mixed` (e.g., `$_client->client_id` where `$_client`
 * is `\stdClass` from `\DB::select()`), Psalm cannot prove the negative branch
 * and combines both, so chained `$client->prop = ...` surfaces as
 * `UndefinedPropertyAssignment` on the spurious Collection branch.
 *
 * `BuilderFindMixedHandler` collapses the mixed case to the scalar-id branch
 * (`TModel|null` / `TModel`), matching Larastan's overload trade-off. Concrete
 * scalar/array arguments still narrow through the stub conditional.
 * The `findOr` companion, the relation-class re-declarations (BelongsToMany
 * pivot intersection, HasManyThrough/HasOneOrManyThrough/HasOneOrMany), and
 * the accepted soundness trade-off (mixed-but-actually-array) are covered in
 * sibling tests: FindOrMixedNarrowingTest, RelationFindMixedNarrowingTest,
 * FindMixedTradeOffTest.
 *
 * From invoiceninja's app/Console/Commands/CheckData.php:
 *
 *   $client = Client::withTrashed()->find($_client->client_id);
 *   $client->paid_to_date = $total_paid_to_date;
 */
function test_find_mixed_narrows_to_model_or_null(mixed $id): void
{
    $_r = Customer::find($id);
    /** @psalm-check-type-exact $_r = Customer&static|null */
}

// The exact invoiceninja chain shape: SoftDeletes scope before find($mixed).
// If withTrashed() ever drops the TModel template, this assertion regresses
// before any downstream property access does.
function test_find_mixed_via_with_trashed_narrows(mixed $id): void
{
    $_r = Customer::withTrashed()->find($id);
    /** @psalm-check-type-exact $_r = Customer&static|null */
}

// Bare query() path. Note `Customer|null` (no `&static`): the Model::query()
// stub returns `Builder<static>`, and Psalm strips the `&static` intersection
// when binding through the explicit call. The handler returns whatever the
// template parameter resolves to, so it matches the stub's pre-existing
// behavior on this path (both literal and mixed args).
function test_find_mixed_via_query_narrows(mixed $id): void
{
    $_r = Customer::query()->find($id);
    /** @psalm-check-type-exact $_r = Customer|null */
}

function test_find_or_fail_mixed_narrows_to_model(mixed $id): void
{
    $_r = Customer::findOrFail($id);
    /** @psalm-check-type-exact $_r = Customer&static */
}

function test_find_or_new_mixed_narrows_to_model(mixed $id): void
{
    $_r = Customer::findOrNew($id);
    /** @psalm-check-type-exact $_r = Customer&static */
}

// Positive control: a literal int still narrows through the stub conditional.
function test_find_int_narrows_to_model_or_null(): void
{
    $_r = Customer::find(123);
    /** @psalm-check-type-exact $_r = Customer&static|null */
}

// Positive control: an array argument still narrows through the stub conditional.
// Guards against the handler over-collapsing or a stub regression that drops
// the conditional altogether.
function test_find_array_narrows_to_collection(): void
{
    $_r = Customer::find([1, 2, 3]);
    /** @psalm-check-type-exact $_r = Collection<int, Customer&static> */
}

?>
--EXPECTF--
