--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DirectScopeModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pins variadic and named-argument scope-call handling on the forwarded path (where the plugin
 * strips the leading $query that Laravel injects) and the direct path (real signature).
 *
 * Laravel's Builder::__call / Model::callNamedScope forward every caller argument after the
 * injected $query, so a forwarded variadic scope must keep its variadic flag through the strip:
 * a zero-arg call is valid, extra args draw no TooManyArguments, and each value is still checked
 * against the element type. Named arguments and argument unpacking are forwarded verbatim.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1039
 */

/* -- Forwarded legacy variadic scope (Customer::scopeOfNames) ---------------- */

/** Forwarded variadic: multiple values accepted, result keeps the model template. */
function test_variadic_forwarded_multiple(): void
{
    $_result = Customer::query()->ofNames('Ada', 'Bo');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Zero args: a variadic tail is optional, so no TooFewArguments. */
function test_variadic_forwarded_zero_args(): void
{
    $_result = Customer::query()->ofNames();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** More args than declared params: the variadic flag survives the $query slice — no TooManyArguments. */
function test_variadic_forwarded_extra_args(): void
{
    $_result = Customer::query()->ofNames('Ada', 'Bo', 'Cy');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Wrong element type is still checked against the variadic's `string`. */
function test_variadic_forwarded_wrong_type(): void
{
    $_result = Customer::query()->ofNames(1);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/* -- Named args / spread on a forwarded scope (Customer::scopeOfName) -------- */

/** Named argument matching the scope's (post-strip) param name resolves cleanly. */
function test_named_arg_forwarded(): void
{
    $_result = Customer::query()->ofName(name: 'Ada');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Spread of a positional list into a forwarded scope. */
function test_spread_arg_forwarded(): void
{
    $_result = Customer::query()->ofName(...['Ada']);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/* -- Forwarded variadic #[Scope] (DirectScopeModel::ofAnyName) --------------- */

/** Forwarded modern #[Scope] variadic: same forwarding behavior as the legacy form. */
function test_attribute_variadic_forwarded(): void
{
    $_result = DirectScopeModel::query()->ofAnyName('a', 'b');
    /** @psalm-check-type-exact $_result = Builder<DirectScopeModel> */
}

/** Forwarded #[Scope] variadic, zero args: still optional. */
function test_attribute_variadic_forwarded_zero_args(): void
{
    $_result = DirectScopeModel::query()->ofAnyName();
    /** @psalm-check-type-exact $_result = Builder<DirectScopeModel> */
}

/* -- Direct call of a variadic #[Scope] (real signature applies) ------------- */

/**
 * Direct instance call passing $query explicitly: ofAnyName is a real, accessible method, so
 * dispatch is direct and the full variadic signature applies — one trailing string is valid.
 *
 * @param Builder<DirectScopeModel> $query
 */
function test_attribute_variadic_direct_call(DirectScopeModel $model, Builder $query): void
{
    $model->ofAnyName($query, 'a');
}

/**
 * Direct call, wrong variadic element type: reported as argument 2 against the real signature
 * (no left-shift), not argument 1.
 *
 * @param Builder<DirectScopeModel> $query
 */
function test_attribute_variadic_direct_call_wrong_type(DirectScopeModel $model, Builder $query): void
{
    $model->ofAnyName($query, 1);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::ofnames expects string, but 1 provided
InvalidArgument on line %d: Argument 2 of App\Models\DirectScopeModel::ofAnyName expects string, but 1 provided
