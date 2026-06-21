--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: this test asserts #[Scope]-attributed scope resolution.
// The #[Scope] attribute is Laravel 12+, so on Laravel 11 the plugin correctly does
// not resolve such methods as scopes (see EloquentModelMethods::hasScopeAttribute).
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Instance scope calls on the base Builder (Customer::query()->active()) must
 * resolve to Builder<Model> instead of mixed, with the scope's params (minus
 * the leading $query) used for argument checking.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/pull/1032
 */

/** Legacy scopeActive() called on a builder instance. */
function test_legacy_scope_on_builder(): void
{
    $_result = Customer::query()->active();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Modern #[Scope] attribute method called on a builder instance. */
function test_scope_attribute_on_builder(): void
{
    $_result = Customer::query()->verified();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Chained scopes keep the model template. */
function test_chained_scopes(): void
{
    $_result = Customer::query()->active()->verified();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Downstream aggregate narrowing works through a scope chain. */
function test_scope_chain_to_sum(): void
{
    $_result = Customer::query()->active()->sum('vehicles_count');
    /** @psalm-check-type-exact $_result = int */
}

/** Scope with a parameter: the arg after $query is accepted. */
function test_scope_with_arg(): void
{
    $_result = Customer::query()->ofName('Ada');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Scope args are type-checked against the scope's declared params. */
function test_scope_wrong_arg_type(): void
{
    $_result = Customer::query()->ofName(123);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Extra args beyond the scope's params are rejected. */
function test_scope_too_many_args(): void
{
    $_result = Customer::query()->ofName('Ada', 'extra');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Missing required scope args are rejected. */
function test_scope_too_few_args(): void
{
    $_result = Customer::query()->ofName();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Scope param with a default value: a zero-arg call is fine. */
function test_scope_default_param(): void
{
    $_result = Customer::query()->ofStatus();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Scope declared on an abstract parent model resolves params from the declaring class. */
function test_inherited_scope(): void
{
    $_result = Contract::query()->signedBetween(now(), now());
    /** @psalm-check-type-exact $_result = Builder<Contract> */
}

/** Scope hosted in a trait used by the model. */
function test_trait_scope(): void
{
    $_result = Contract::query()->flagged();
    /** @psalm-check-type-exact $_result = Builder<Contract> */
}

/**
 * Negative: a nonexistent method on a base Builder instance must not be typed by the
 * widened provider. It still resolves to mixed via Builder::__call (Psalm doesn't
 * report UndefinedMagicMethod here on master either).
 */
function test_nonexistent_method_on_builder(): void
{
    $_result = Customer::query()->completelyFakeMethod();
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::ofname expects string, but 123 provided
TooManyArguments on line %d: Too many arguments for Illuminate\Database\Eloquent\Builder::ofname - expecting 1 but saw 2
TooFewArguments on line %d: Too few arguments for Illuminate\Database\Eloquent\Builder::ofname - expecting name to be passed
