--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pins LEGACY scope calls made on a MODEL INSTANCE ($customer->active()), distinct from the
 * builder-instance form covered by InstanceScopeCallTest (Customer::query()->active()).
 *
 * Runtime: $customer->active() is not a real method, so Model::__call forwards it to
 * $this->newQuery()->active() (vendor/laravel/framework/.../Model.php), which routes through
 * Builder::__call -> callNamedScope and returns the builder. Forwarding happens whenever the
 * bare-name method is unreachable from outside — nonexistent (legacy `scopeXxx`) or inaccessible
 * (a protected/private #[Scope], pinned in ProtectedScopeSurfacesTest). A PUBLIC #[Scope] is the
 * exception: its bare-name method is accessible, so PHP invokes it directly and hits the real
 * signature ($customer->verified() raises TooFewArguments for the missing $query — an
 * ArgumentCountError at runtime) instead of forwarding. The legacy `active`/`ofName` scopes and
 * the public `verified` are both pinned below.
 *
 * Not pinnable by this suite: the in-body form `$this->active()`. Per-model providers are keyed
 * on the exact concrete class, so a snippet-local subclass of a registered model is not itself
 * registered (its scopes report UndefinedMagicMethod), and the bodies of the registered fixture
 * models are never analyzed (test:app builds a fresh app without them; PHPT suites analyze only
 * the snippet). The instance-receiver path below exercises the same resolution code.
 */

/** Legacy scope on a model instance resolves to the builder. */
function test_legacy_scope_on_model_instance(Customer $customer): void
{
    $_result = $customer->active();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Legacy scope with an argument: the value after $query is accepted. */
function test_legacy_scope_with_arg_on_model_instance(Customer $customer): void
{
    $_result = $customer->ofName('Ada');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Negative: the argument is type-checked against the scope's declared param (minus $query). */
function test_scope_argument_type_checked_on_model_instance(Customer $customer): void
{
    $_result = $customer->ofName(123);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/**
 * Negative: too many args. Mirrors InstanceScopeCallTest's builder-instance coverage on this
 * distinct model-instance route (Model::__call -> newQuery()), so a regression that dropped
 * arity checking on the instance path would be caught.
 */
function test_scope_too_many_args_on_model_instance(Customer $customer): void
{
    $customer->ofName('a', 'b');
}

/** Negative: too few args (the required $name after $query is missing). */
function test_scope_too_few_args_on_model_instance(Customer $customer): void
{
    $customer->ofName();
}

/**
 * Contrast: a PUBLIC #[Scope] called on an instance is a real, accessible method, so PHP invokes
 * it directly (no __call forwarding) — Psalm checks the real signature and reports the missing
 * $query as TooFewArguments. True positive: at runtime this is an ArgumentCountError.
 */
function test_public_attribute_scope_on_instance_needs_query(Customer $customer): void
{
    $customer->verified();
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Models\Customer::ofname expects string, but 123 provided
TooManyArguments on line %d: Too many arguments for App\Models\Customer::ofname - expecting 1 but saw 2
TooFewArguments on line %d: Too few arguments for App\Models\Customer::ofname - expecting name to be passed
TooFewArguments on line %d: Too few arguments for method App\Models\Customer::verified saw 0
