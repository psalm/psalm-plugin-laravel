--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Attribute::make() must accept closures with 0, 1, or 2 parameters for both
 * get and set, regardless of purity. Laravel calls the closure with
 * ($value, $attributes) but PHP allows ignoring trailing arguments.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/515
 */

/** Pure closure with 2 params — must not trigger purity mismatch (UnusedClosureParam expected) */
function test_pure_two_param_getter(): Attribute
{
    return Attribute::make(
        get: fn (bool|null $value, array $attributes): bool => (bool) $value,
    );
}

/** Zero-param getter — common pattern for computed attributes */
function test_zero_param_getter(): Attribute
{
    return Attribute::make(
        get: fn (): string => \ucfirst('computed'),
    );
}

/** Single-param getter */
function test_single_param_getter(): Attribute
{
    return Attribute::make(
        get: fn (mixed $value): ?int => $value !== null ? (int) $value : null,
    );
}

/** Two-param impure getter — the full signature (UnusedClosureParam expected) */
function test_two_param_impure_getter(): Attribute
{
    return Attribute::make(
        get: function (mixed $value, array $attributes): string {
            error_log('side effect');
            return (string) $value;
        },
    );
}

/** Setter with concrete type hint (string, not TSet) */
function test_concrete_setter_type(): Attribute
{
    return Attribute::make(
        set: fn (string $value): string => \strtolower($value),
    );
}

/** Setter with 2 params — using both $value and $attributes */
function test_two_param_setter(): Attribute
{
    return Attribute::make(
        set: function (string $value, array $attributes): string {
            error_log((string) \count($attributes));
            return \strtolower($value);
        },
    );
}

/** Combined get + set with varying param counts */
function test_combined_get_set(): Attribute
{
    return Attribute::make(
        get: fn (): string => \ucfirst('computed'),
        set: fn (string $value): string => \strtolower($value),
    );
}

/** Attribute::get() shorthand with zero params */
function test_get_shorthand_zero_params(): Attribute
{
    return Attribute::get(
        fn (): string => \ucfirst('computed'),
    );
}

/** Attribute::set() shorthand with single param */
function test_set_shorthand_single_param(): Attribute
{
    return Attribute::set(
        fn (string $value): string => \strtolower($value),
    );
}

/** Template inference: TGetResult inferred from callable return type */
function test_get_template_inference(): Attribute
{
    $attr = Attribute::get(fn (mixed $value): int => (int) $value);
    /** @psalm-check-type-exact $attr = Attribute<int, never> */
    return $attr;
}

/** Template inference: TSetParam inferred from callable parameter type */
function test_set_template_inference(): Attribute
{
    $attr = Attribute::set(fn (string $value): string => \strtolower($value));
    /** @psalm-check-type-exact $attr = Attribute<never, string> */
    return $attr;
}
?>
--EXPECTF--
UnusedClosureParam on line %d: Param attributes is never referenced in this method
UnusedClosureParam on line %d: Param attributes is never referenced in this method
