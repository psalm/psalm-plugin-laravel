--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Higher-order where proxies (`->orWhere->scope()`, `->whereNot->scope()`,
 * `->orWhereNot->scope()`) must resolve to Builder<TModel> instead of collapsing to
 * mixed, so the trailing chain (->get(), further scopes) infers correctly.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1062
 */

/** Legacy scope through the orWhere proxy. */
function test_or_where_legacy_scope(): void
{
    $_result = Customer::query()->orWhere->active();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** #[Scope]-attributed scope through the orWhere proxy. */
function test_or_where_attribute_scope(): void
{
    $_result = Customer::query()->orWhere->verified();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** The proxied chain stays a builder, so ->get() narrows to the eloquent collection. */
function test_or_where_chain_to_get(): void
{
    $_result = Customer::query()->orWhere->active()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Customer> */
}

/** Proxy used after a static scope entry point. */
function test_static_scope_then_proxy(): void
{
    $_result = Customer::active()->orWhere->verified()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Customer> */
}

/** whereNot proxy resolves the same way. */
function test_where_not_proxy(): void
{
    $_result = Customer::query()->whereNot->active();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** orWhereNot proxy resolves the same way. */
function test_or_where_not_proxy(): void
{
    $_result = Customer::query()->orWhereNot->active();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** A scope with args through the proxy accepts the trailing args. */
function test_proxy_scope_with_arg(): void
{
    $_result = Customer::query()->orWhere->ofName('Ada');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/**
 * Variance: the proxy carries @template-covariant TModel, so a proxied call inside a
 * non-final model method typed `@return Builder<self>` resolves without InvalidReturnStatement.
 * Customer::activeOrVerified() exercises the declaration; here we assert the call-site type.
 */
function test_proxy_variance_self_return(): void
{
    $_result = (new Customer())->activeOrVerified();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}
?>
--EXPECTF--
