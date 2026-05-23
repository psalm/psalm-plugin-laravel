--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * Regression: static calls to query-forwarding Builder methods on a bare Model.
 *
 * invoiceninja exercises the following four static-on-Model patterns. Earlier plugin
 * versions raised UndefinedMagicMethod for each (resolved on later releases):
 *
 *   Company::cursor()                          // forward to Builder::cursor()
 *   ClientContact::whereClientId($id)          // dynamic where{Column}
 *   CompanyUser::whereDoesntHave('user')       // QueriesRelationships method
 *   RecurringInvoiceInvitation::where('a', 1)  // 2-arg where form
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/498
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/647
 */

// Cursor: Eloquent\Builder::cursor() returns LazyCollection<int, TModel>.
// Static call goes via Model::__callStatic -> Builder pseudo-mixin.
// `&static` reflects late-static binding through the pseudo_static_methods path.
function test_static_cursor(): void
{
    $_result = Customer::cursor();
    /** @psalm-check-type-exact $_result = LazyCollection<int, Customer&static> */
}

// Dynamic where{Column}: Customer has @property string $id, so whereId() must resolve
// via Model::__callStatic forwarding to Builder::__call. With resolveDynamicWhereClauses
// enabled (default), ModelMethodHandler confirms existence via DynamicWhereResolver and
// returns Builder<Customer>. See https://github.com/psalm/psalm-plugin-laravel/issues/1000.
function test_static_dynamic_where_column(): void
{
    $_result = Customer::whereId('cust-1');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

// QueriesRelationships::whereDoesntHave called statically. Maps to Builder::whereDoesntHave.
function test_static_where_doesnt_have(): void
{
    $_result = Customer::whereDoesntHave('vehicles');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

// Multi-arg where: the 2- and 3-arg forms of Builder::where called statically.
function test_static_where_two_args(): void
{
    $_result = Customer::where('email', 'foo@example.com');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

function test_static_where_three_args(): void
{
    $_result = Customer::where('id', '!=', 0);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

// Chained cursor: each() callback parameter must be the model, not stdClass.
// The invoiceninja regression typed `function ($model)` as stdClass after this chain.
//
// The outer `LazyCollection<...>&static` reflects late-static binding: cursor()
// is dispatched via the pseudo_static_methods path which threads `static` through
// the return type; LazyCollection::each() then returns `$this`, preserving the
// outer `&static` annotation. Do not "simplify" it — dropping `&static` will
// re-introduce the bug it is pinning.
function test_static_cursor_chain_each(): void
{
    $_result = Customer::cursor()->each(function (Customer $customer): bool {
        /** @psalm-check-type-exact $customer = Customer&static */
        return $customer->exists;
    });
    /** @psalm-check-type-exact $_result = LazyCollection<int, Customer&static>&static */
}

?>
--EXPECTF--
