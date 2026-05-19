--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reproducer (documented broken behavior): `Customer::find($mixedId)` widens to
 * `Collection|Customer|null`, so chained property access on the resulting model
 * triggers `UndefinedPropertyAssignment` on the Collection branch.
 *
 * From invoiceninja's app/Console/Commands/CheckData.php:
 *
 *   $client = Client::withTrashed()->find($_client->client_id);
 *   $client->paid_to_date = $total_paid_to_date;
 *
 * `$_client->client_id` comes from `\DB::select(...)` which returns
 * `list<\stdClass>`, so each `client_id` is `mixed`. The `Builder::find` stub
 * uses a conditional return type:
 *
 *   `psalm-return (T is (array|Arrayable) ? Collection<int, TModel> : TModel|null)`
 *
 * When `T = mixed`, Psalm cannot prove the negative branch of the conditional,
 * so the return type widens to the union of both branches:
 * `Collection<int, Customer>|Customer|null`.
 *
 * Larastan handles this with an `@method static` overload pair (one for scalar,
 * one for array). The plugin's stub does not, hence this reproducer.
 *
 * Property assignment on the union surfaces as
 * `UndefinedPropertyAssignment ... Collection::$<name>`.
 */
function test_find_mixed_widens_to_union(mixed $id): void
{
    $client = Customer::find($id);
    if ($client === null) {
        return;
    }
    // $client is Collection<int, Customer>|Customer here; writes against magic
    // properties fail on the Collection branch.
    $client->email = 'override@example.com';
}

// Same widening through the SoftDeletes scope — the exact invoiceninja chain shape.
// If withTrashed() ever drops the TModel template (collapsing the chain),
// the inner find($mixed) result becomes mixed and this assertion's array branch
// vanishes, surfacing the regression here rather than on a downstream model
// property access.
function test_find_mixed_via_with_trashed_widens(mixed $id): void
{
    $client = Customer::withTrashed()->find($id);
    if ($client === null) {
        return;
    }
    $client->email = 'override@example.com';
}

// Positive control: a literal int narrows the conditional correctly to TModel|null.
function test_find_int_narrows_to_model_or_null(): void
{
    $_r = Customer::find(123);
    /** @psalm-check-type-exact $_r = Customer&static|null */
}

// Positive control: an array argument narrows to the Collection branch only.
// Guards against a future stub regression that drops the conditional altogether.
function test_find_array_narrows_to_collection(): void
{
    $_r = Customer::find([1, 2, 3]);
    /** @psalm-check-type-exact $_r = Collection<int, Customer&static> */
}

?>
--EXPECTF--
UndefinedMagicPropertyAssignment on line %d: Magic instance property App\Models\Customer::$email is not defined
UndefinedPropertyAssignment on line %d: Instance property Illuminate\Database\Eloquent\Collection::$email is not defined
UndefinedMagicPropertyAssignment on line %d: Magic instance property App\Models\Customer::$email is not defined
UndefinedPropertyAssignment on line %d: Instance property Illuminate\Database\Eloquent\Collection::$email is not defined
