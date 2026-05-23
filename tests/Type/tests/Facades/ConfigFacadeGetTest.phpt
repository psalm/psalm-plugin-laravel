--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\Config;

/**
 * Config::get() through the facade — should route through the Repository
 * method provider via Psalm's facade pipeline.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/752
 */

function facade_get_string_key(): void
{
    $_result = Config::get('app.name');
    /** @psalm-check-type-exact $_result = string */
}

function facade_get_bool_key(): void
{
    $_result = Config::get('app.debug');
    /** @psalm-check-type-exact $_result = bool */
}

function facade_get_missing_key_string_default(): void
{
    $_result = Config::get('foo.bar.nonexistent', 'fallback');
    /** @psalm-check-type-exact $_result = string */
}

function facade_get_missing_key_no_default(): void
{
    $_result = Config::get('foo.bar.nonexistent');
    /** @psalm-check-type-exact $_result = null */
}

function facade_get_dynamic_key_remains_mixed(string $key): void
{
    $_result = Config::get($key);
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
