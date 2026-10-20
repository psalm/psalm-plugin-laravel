--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

// Regression: on Psalm 7.0.0-rc6 a Stringable object (every Eloquent model)
// matched the `''` / `'0'` literal arms of the throw_unless() conditional, so
// the call resolved to `never` and every following line was treated as
// unreachable: taint and type issues after it silently vanished. The echo in
// each function must stay analyzed: the call to an undefined function after
// throw_*() is the proof of reachability (UndefinedFunction).

function throw_unless_model(Customer $user): void
{
    throw_unless($user);
    after_a();
}

function throw_unless_nullable_model(?Customer $user): void
{
    throw_unless($user);
    after_b();
}

function throw_unless_stringable(\Stringable $value): void
{
    throw_unless($value);
    after_c();
}

function throw_unless_countable(\Countable $value): void
{
    throw_unless($value);
    after_d();
}

function throw_unless_mixed(mixed $value): void
{
    throw_unless($value);
    after_e();
}

function throw_unless_model_or_string(Customer|string $value): void
{
    throw_unless($value);
    after_f();
}

function throw_if_nullable_model(?Customer $user): void
{
    throw_if($user);
    after_g();
}

function throw_if_mixed(mixed $value): void
{
    throw_if($value);
    after_h();
}

function throw_if_string(string $value): void
{
    throw_if($value);
    after_i();
}

// Not every object is truthy: casting an empty SimpleXMLElement, a zero GMP or a zero BcMath\Number to
// bool gives false, so throw_if() may return and throw_unless() may throw: the code after both stays
// reachable.
function throw_if_simplexml(\SimpleXMLElement $value): void
{
    throw_if($value);
    after_j();
}

function throw_unless_simplexml(\SimpleXMLElement $value): void
{
    throw_unless($value);
    after_k();
}

/**
 * Psalm treats every GMP / BcMath\Number as non-empty, so the `empty` assertion of throw_if() still
 * reports TypeDoesNotContainType; only the reachability of the code after the call is under test.
 *
 * @psalm-suppress TypeDoesNotContainType
 */
function throw_if_gmp(\GMP $value): void
{
    throw_if($value);
    after_l();
}

/** @psalm-suppress TypeDoesNotContainType */
function throw_if_bcmath(\BcMath\Number $value): void
{
    throw_if($value);
    after_m();
}

// A condition that is certainly truthy always throws in throw_if(): the call is `never`, so a `never`
// return type must not report InvalidReturnType. throw_unless() passes such a condition through. The
// `empty` assertion of throw_if() reports the always-truthy condition itself, which is not under test.
/**
 * @param non-empty-array<array-key, int> $items
 * @psalm-suppress TypeDoesNotContainType
 */
function throw_if_non_empty_array_never(array $items): never
{
    throw_if($items);
}

function throw_if_true_never(): never
{
    throw_if(true);
}

/**
 * @param non-falsy-string $value
 * @psalm-suppress TypeDoesNotContainType
 */
function throw_if_non_falsy_string_never(string $value): never
{
    throw_if($value);
}

/**
 * @param positive-int $value
 * @psalm-suppress DocblockTypeContradiction
 */
function throw_if_positive_int_never(int $value): never
{
    throw_if($value);
}

/** @psalm-suppress TypeDoesNotContainType */
function throw_if_object_never(Customer $value): never
{
    throw_if($value);
}

/**
 * @param non-empty-array<array-key, int> $items
 * @return non-empty-array<array-key, int>
 */
function throw_unless_non_empty_array_passes_through(array $items): array
{
    return throw_unless($items);
}

/**
 * @param non-falsy-string $value
 * @return non-falsy-string
 */
function throw_unless_non_falsy_string_passes_through(string $value): string
{
    return throw_unless($value);
}
?>
--EXPECTF--
UndefinedFunction on line %d: Function after_a does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_b does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_c does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_d does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_e does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_f does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_g does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_h does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_i does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_j does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_k does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_l does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
UndefinedFunction on line %d: Function after_m does not exist, consider enabling the allFunctionsGlobal config option if scanning legacy codebases
