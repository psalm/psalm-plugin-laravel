--FILE--
<?php declare(strict_types=1);

/**
 * env() return type narrowing based on the default value argument.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/707
 */

function env_no_default(): void
{
    $_result = env('FOO');
    /** @psalm-check-type-exact $_result = string|null */
}

function env_null_default(): void
{
    $_result = env('FOO', null);
    /** @psalm-check-type-exact $_result = string|null */
}

function env_string_default(): void
{
    $_result = env('FOO', 'bar');
    /** @psalm-check-type-exact $_result = string */
}

function env_false_default(): void
{
    $_result = env('FOO', false);
    /** @psalm-check-type-exact $_result = string|false */
}

function env_true_default(): void
{
    $_result = env('FOO', true);
    /** @psalm-check-type-exact $_result = string|true */
}

function env_bool_default(bool $default): void
{
    $_result = env('FOO', $default);
    /** @psalm-check-type-exact $_result = string|bool */
}

function env_int_default(int $default): void
{
    $_result = env('FOO', $default);
    /** @psalm-check-type-exact $_result = string|int */
}

function env_literal_int_default(): void
{
    $_result = env('FOO', 42);
    /** @psalm-check-type-exact $_result = string|42 */
}

function env_float_default(float $default): void
{
    $_result = env('FOO', $default);
    /** @psalm-check-type-exact $_result = string|float */
}

function env_mixed_default(mixed $default): void
{
    $_result = env('FOO', $default);
    /** @psalm-check-type-exact $_result = string|null */
}

function env_nullable_int_default(?int $default): void
{
    $_result = env('FOO', $default);
    /** @psalm-check-type-exact $_result = string|null */
}
?>
--EXPECTF--
