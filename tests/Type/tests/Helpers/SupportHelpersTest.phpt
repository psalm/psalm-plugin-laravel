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

// `if (filled($x))` on `?string` narrows `$x` to `string` (not
// `non-empty-string`) via the `!null` assertion alone. An earlier
// iteration added a `!=''` assertion to tighten this to `non-empty-string`,
// but the loose-equality assertion was applied to every atomic of the
// input type and broke `class-string<X>` and non-string inputs (see
// https://github.com/psalm/psalm-plugin-laravel/issues/771). The
// `non-empty-string` expectation is therefore intentionally not asserted.
function filled_narrows_nullable_string_to_string(?string $value): string
{
    if (filled($value)) {
        /** @psalm-check-type-exact $value = string */;
        return $value;
    }
    return 'fallback';
}

// `mixed` must not be over-narrowed. `!null` is a no-op here because
// `mixed` already covers every possibility, so `mixed` stays `mixed`
// inside the true branch.
function filled_does_not_over_narrow_mixed(mixed $value): mixed
{
    if (filled($value)) {
        /** @psalm-check-type-exact $value = mixed */;
        return $value;
    }
    return null;
}

/**
 * Mixed union: `null` drops out, `int`, `string`, and `array` survive
 * (`string` is not narrowed to `non-empty-string` after the #771 revert).
 * The return type matches Psalm's normalized form (`array<array-key, mixed>`)
 * so that the check-type-exact assertion and the declared return type are
 * consistent.
 *
 * @param  array|int|string|null  $value
 * @return array<array-key, mixed>|int|string
 */
function filled_narrows_union_input($value)
{
    if (filled($value)) {
        /** @psalm-check-type-exact $value = array<array-key, mixed>|int|string */;
        return $value;
    }
    return 0;
}

// Symmetric narrowing: `! blank($x)` and `filled($x)` both narrow `?string`
// to `string` (not `non-empty-string`). See
// https://github.com/psalm/psalm-plugin-laravel/issues/771.
function blank_narrows_nullable_string_to_string(?string $value): string
{
    if (! blank($value)) {
        /** @psalm-check-type-exact $value = string */;
        return $value;
    }
    return 'fallback';
}

// Regression tests for https://github.com/psalm/psalm-plugin-laravel/issues/771.
// The previous `!=''` assertion on `filled()` collapsed `class-string<X>`
// to `non-empty-string` (losing the template parameter) and raised
// `RedundantCondition` / `TypeDoesNotContainType` on non-string inputs.
// These tests pin that the revert restores the pre-regression behavior.

/**
 * @param  ?class-string<\Throwable>  $fqcn
 */
function filled_preserves_class_string_template_in_guard(?string $fqcn): void
{
    if (filled($fqcn)) {
        /** @psalm-check-type-exact $fqcn = class-string<Throwable> */;
    }
}

/**
 * End-to-end reproducer from issue #771: invoking a static method on a
 * `?class-string<X>` narrowed via `filled()`. With the `!=''` assertion
 * in place `class-string<X>` collapsed to `non-empty-string` and this
 * emitted `InvalidStringClass` on the static call site.
 *
 * @param  callable(): ?class-string<\DateTime>  $fetch
 */
function filled_guard_allows_static_method_on_class_string(callable $fetch): \DateTime|false|null
{
    if (filled($cls = $fetch())) {
        return $cls::createFromFormat('Y-m-d', '2024-01-01');
    }
    return null;
}

/**
 * `filled()` on a non-string atomic must not emit `RedundantCondition`.
 * The EXPECTF block for this test file is empty, so any Psalm issue raised
 * by the call site below would fail the test.
 *
 * @param  array<string, mixed>  $value
 */
function filled_on_array_does_not_emit_redundant_condition(array $value): bool
{
    return filled($value);
}

/**
 * `filled()` on an object atomic must not emit `TypeDoesNotContainType`.
 */
function filled_on_object_does_not_emit_type_does_not_contain_type(\stdClass $value): bool
{
    return filled($value);
}

enum FilledTestStatus
{
    case Active;
    case Inactive;
}

/**
 * `filled()` on an enum instance must not emit `TypeDoesNotContainType`.
 * Issue #771 reports 7 such diagnostics on enum/Model/Closure inputs.
 */
function filled_on_enum_instance_does_not_emit_issue(FilledTestStatus $value): bool
{
    return filled($value);
}

/**
 * End-to-end reproducer from issue #771: `filled()` on a
 * `?class-string<EnumClass>` must preserve the template parameter so
 * that `$enum::cases()` resolves. With the `!=''` assertion in place the
 * resulting type had a spurious `non-empty-string` atomic, which caused
 * `UndefinedMethod` / `MixedArgument` on the `cases()` call site.
 *
 * @param  callable(): ?class-string<FilledTestStatus>  $fetch
 * @return list<FilledTestStatus>
 */
function filled_guard_preserves_enum_class_string_for_cases(callable $fetch): array
{
    if (filled($enum = $fetch())) {
        return $enum::cases();
    }
    return [];
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
