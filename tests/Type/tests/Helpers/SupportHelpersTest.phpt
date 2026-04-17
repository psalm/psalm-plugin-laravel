--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Optional;

function test_optional(\Throwable $throwable): string
{
    return optional($throwable)->getMessage();
}

/*
function test_optional_with_nullable_arg(?\Throwable $throwable): ?string
{
    return optional($throwable)->getMessage();
}
*/

/** @return false */
function false_is_not_blank(): bool
{
    return blank(false);
}

/** @return false */
function true_is_not_blank(): bool
{
    return blank(true);
}

/** @return false */
function zero_int_is_not_blank(): bool
{
    return blank(0);
}

/** @return false */
function zero_float_is_not_blank(): bool
{
    return blank(0.0);
}

/** @return false */
function zero_numeric_string_is_not_blank(): bool
{
    return blank('0');
}

/** @return false */
function non_empty_array_is_not_blank(): bool
{
    return blank(['a']);
}

/** @return true */
function null_is_blank(): bool
{
    return blank(null);
}

/** @return true */
function empty_string_is_blank(): bool
{
    return blank('');
}

/** @return true */
function empty_array_is_blank(): bool
{
    return blank([]);
}

function string_of_spaces_is_blank(): bool
{
    return blank('  ');
}

function class_basename_allows_passing_fqcn(): string
{
    return class_basename(\App\Models\Customer::class);
}

function class_basename_allows_passing_object(): string
{
    return class_basename(new \stdClass());
}

function class_uses_recursive_allows_passing_fqcn(): array
{
    return class_uses_recursive(\App\Models\Customer::class);
}

function class_uses_recursive_allows_passing_object(): array
{
    return class_uses_recursive(new \stdClass());
}

/** @return true */
function false_is_filled(): bool
{
    return filled(false);
}

/** @return true */
function true_is_filled(): bool
{
    return filled(true);
}

/** @return true */
function zero_int_is_filled(): bool
{
    return filled(0);
}

/** @return true */
function zero_float_is_filled(): bool
{
    return filled(0.0);
}

/** @return true */
function zero_numeric_string_is_filled(): bool
{
    return filled('0');
}

/** @return true */
function non_empty_array_is_filled(): bool
{
    return filled(['a']);
}

/** @return false */
function null_is_not_filled(): bool
{
    return filled(null);
}

/** @return false */
function empty_string_is_not_filled(): bool
{
    return filled('');
}

/** @return false */
function empty_array_is_not_filled(): bool
{
    return filled([]);
}

function non_empty_string_is_unknown_filled_or_not(): bool
{
    return filled('  ');
}

// Regression tests for https://github.com/psalm/psalm-plugin-laravel/issues/751.
// Conditional return types must not collapse to literal `true`/`false` for
// wider input types that only partially overlap with the narrow clauses.
// These use @psalm-check-type-exact so a regression to the old stub (which
// returned literal `false`/`true` here) fails the test. A plain `: bool`
// return would silently accept the literal subtype.
function nullable_string_is_unknown_filled_or_not(?string $value): bool
{
    $result = filled($value);
    /** @psalm-check-type-exact $result = bool */;
    return $result;
}

function nullable_string_is_unknown_blank_or_not(?string $value): bool
{
    $result = blank($value);
    /** @psalm-check-type-exact $result = bool */;
    return $result;
}

function string_is_unknown_filled_or_not(string $value): bool
{
    $result = filled($value);
    /** @psalm-check-type-exact $result = bool */;
    return $result;
}

function array_is_unknown_blank_or_not(array $value): bool
{
    $result = blank($value);
    /** @psalm-check-type-exact $result = bool */;
    return $result;
}

function mixed_is_unknown_filled_or_not(mixed $value): bool
{
    $result = filled($value);
    /** @psalm-check-type-exact $result = bool */;
    return $result;
}

/**
 * Union that straddles multiple narrow clauses (`string` overlaps with `''`,
 * `array` overlaps with `array<never, never>`). Exercises the nested-conditional
 * rationale from the stub comment.
 *
 * @param string|array $value
 */
function string_or_array_union_is_unknown_filled_or_not($value): bool
{
    $result = filled($value);
    /** @psalm-check-type-exact $result = bool */;
    return $result;
}

/**
 * The exact reproduction from the issue: assignment inside the condition.
 *
 * @param callable(): ?string $fetch
 */
function filled_guard_with_assignment_in_condition(callable $fetch): string
{
    if (filled($handler = $fetch())) {
        return $handler . '(';
    }
    return 'fallback';
}

// Assertion-based narrowing: `if (filled($nullable))` narrows the nullable
// away, which makes the idiomatic guard pattern `filled($x)` work end-to-end.
function filled_narrows_nullable_string_to_non_null(?string $value): string
{
    if (filled($value)) {
        return $value;
    }
    return 'fallback';
}

function blank_narrows_nullable_string_to_non_null(?string $value): string
{
    if (! blank($value)) {
        return $value;
    }
    return 'fallback';
}

function object_get_returns_first_arg_when_second_is_null(\stdClass $object): \stdClass
{
    return object_get($object, null);
}

function object_get_returns_first_arg_when_second_is_empty_string(\stdClass $object): \stdClass
{
    return object_get($object, '');
}

function retry_has_callable_with_return_type(): int
{
    return retry(2, fn (): int => 42);
}

function retry_has_callable_without_return_type(): int
{
    return retry(2, fn () => 42);
}

/** @return stringable-object */
function str_without_args_returns_anonymous_class_instance(): object
{
    return str();
}

function str_with_arh_returns_Stringable_instance(): \Illuminate\Support\Stringable
{
    return str('some string');
}

function tap_without_callback(): \DateTime
{
    return tap(new \DateTime);
}

function tap_accepts_callable(): \DateTime
{
    /** @psalm-suppress UnusedClosureParam */
    return tap(new \DateTime, fn (\DateTime $now) => null);
}

/** @return false */
function throw_if_with_bool_arg(bool $var): bool
{
    throw_if($var);
    return $var;
}

/** @return ''|'0' **/
function throw_if_with_string_arg(string $var): string
{
    throw_if($var);
    return $var;
}

/** @return list<never> **/
function throw_if_with_array_arg(array $var): array
{
    throw_if($var);
    return $var;
}

/** @return 0 **/
function throw_if_with_int_arg(int $var): int
{
    throw_if($var);
    return $var;
}

/** @return 0.0 **/
function throw_if_with_float_arg(float $var): float
{
    throw_if($var);
    return $var;
}

/** @return true */
function throw_unless_with_bool_arg(bool $var): bool
{
    throw_unless($var);
    return $var;
}

/** @return non-empty-string **/
function throw_unless_with_string_arg(string $var): string
{
    throw_unless($var);
    return $var;
}

/** @return non-empty-array **/
function throw_unless_with_array_arg(array $var): array
{
    throw_unless($var);
    return $var;
}

function throw_unless_with_int_arg(int $var): int
{
    throw_unless($var);
    return $var;
}

function throw_unless_with_float_arg(float $var): float
{
    throw_unless($var);
    return $var;
}

// class_uses_recursive() support
/** @return array<trait-string|class-string, trait-string|class-string> **/
function test_class_uses_recursive(): array {
  return class_uses_recursive(\App\Models\Customer::class);
}

// trait_uses_recursive() support
trait CustomSoftDeletes {
    use \Illuminate\Database\Eloquent\SoftDeletes;
}

/** @return array<trait-string, trait-string> **/
function test_trait_uses_recursive(): array {
    return trait_uses_recursive(CustomSoftDeletes::class);
}

// transform
// @todo enable it (it was working)
//function it_uses_callback_return_type_if_value_is_not_blank(): float {
//    return transform(42, fn ($value) => $value * 1.1, fn () => null);
//}

function it_uses_default_return_type_if_value_is_blank_and_default_is_callable(): int {
    return transform([], fn () => 'any', fn () => 42);
}

function it_uses_default_return_type_if_value_is_blank_and_default_is_not_callable(): int {
    return transform(null, fn () => 'any', 42);
}
?>
--EXPECTF--
