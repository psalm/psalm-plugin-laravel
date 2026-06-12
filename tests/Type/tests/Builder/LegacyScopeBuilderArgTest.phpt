--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regression for the dispatch-truth classifier (replacing BuilderScopeHandler's old
 * argument-shape heuristic).
 *
 * A legacy scopeXxx() method has NO bare-name method, so a call to the bare name ALWAYS
 * forwards through Laravel's __call / __callStatic, independent of what is passed as the first
 * argument. The previous heuristic inferred "direct call" from a Builder-typed first argument
 * and then declined, leaving the call with no resolved params/return and surfacing a false
 * `UndefinedMagicMethod`. The classifier keys on PHP dispatch (the bare name is not a real
 * method) instead, so the stripped signature applies and the misplaced Builder argument is
 * reported precisely against the scope's declared parameter. (A Builder passed to a `string`
 * param draws both InvalidArgument and InvalidCast, since Builder has no __toString, where the
 * old heuristic emitted a single false UndefinedMagicMethod.)
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1034
 */

/**
 * Legacy scope, static call, Builder passed as the first argument. Forwarded: the Builder is
 * checked against the stripped param (string $name), not misread as the injected $query.
 */
function test_legacy_static_with_builder_first_arg(): void
{
    Customer::ofName(Customer::query());
}

/** Same call on a builder instance: also forwarded, same diagnostics. */
function test_legacy_builder_instance_with_builder_first_arg(): void
{
    Customer::query()->ofName(Customer::query());
}

/**
 * Legacy scope whose post-$query param is OPTIONAL (defaulted). The old heuristic's arity
 * tie-breaker (required-count 0) made a single Builder argument satisfy "direct" and produced
 * the false UndefinedMagicMethod; dispatch truth still forwards, so the Builder is checked
 * against the stripped `string $status`.
 */
function test_legacy_optional_param_with_builder_first_arg(): void
{
    Customer::ofStatus(Customer::query());
}

/** Control: a correctly-typed forwarded argument resolves cleanly to Builder<Customer>. */
function test_legacy_static_valid_arg(): void
{
    $_result = Customer::ofName('Ada');
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Models\Customer::ofname expects string, but Illuminate\Database\Eloquent\Builder<App\Models\Customer> provided
InvalidCast on line %d: Illuminate\Database\Eloquent\Builder<App\Models\Customer> cannot be cast to string
InvalidArgument on line %d: Argument 1 of Illuminate\Database\Eloquent\Builder::ofname expects string, but Illuminate\Database\Eloquent\Builder<App\Models\Customer> provided
InvalidCast on line %d: Illuminate\Database\Eloquent\Builder<App\Models\Customer> cannot be cast to string
InvalidArgument on line %d: Argument 1 of App\Models\Customer::ofstatus expects string, but Illuminate\Database\Eloquent\Builder<App\Models\Customer> provided
InvalidCast on line %d: Illuminate\Database\Eloquent\Builder<App\Models\Customer> cannot be cast to string
