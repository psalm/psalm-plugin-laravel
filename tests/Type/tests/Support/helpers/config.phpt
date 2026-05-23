--FILE--
<?php declare(strict_types=1);

/**
 * config() return type narrowing based on dot-notation key resolution
 * against the booted Laravel app, with default-arg merge logic.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/752
 */

function config_string_key(): void
{
    $_result = config('app.name');
    /** @psalm-check-type-exact $_result = string */
}

function config_bool_key(): void
{
    $_result = config('app.debug');
    /** @psalm-check-type-exact $_result = bool */
}

function config_string_timezone(): void
{
    $_result = config('app.timezone');
    /** @psalm-check-type-exact $_result = string */
}

function config_missing_key_with_string_default(): void
{
    $_result = config('foo.bar.nonexistent', 'fallback');
    /** @psalm-check-type-exact $_result = string */
}

function config_missing_key_with_int_default(): void
{
    $_result = config('foo.bar.nonexistent', 42);
    /** @psalm-check-type-exact $_result = int */
}

function config_missing_key_no_default(): void
{
    $_result = config('foo.bar.nonexistent');
    /** @psalm-check-type-exact $_result = null */
}

function config_missing_key_with_typed_closure_default(): void
{
    $_result = config('foo.bar.nonexistent', fn (): string => 'fallback');
    /** @psalm-check-type-exact $_result = string */
}

function config_no_args_returns_repository(): void
{
    $_result = config();
    /** @psalm-check-type-exact $_result = Illuminate\Config\Repository */
}

function config_setter_form_returns_null(): void
{
    $_result = config(['app.name' => 'Custom']);
    /** @psalm-check-type-exact $_result = null */
}

function config_dynamic_key_remains_mixed(string $key): void
{
    $_result = config($key);
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
