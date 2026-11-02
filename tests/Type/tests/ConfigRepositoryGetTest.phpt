--FILE--
<?php declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Config\Repository as ConfigContract;

/**
 * Repository::get() return type narrowing — same dot-notation resolution as
 * config() helper. The Config facade routes here via Psalm's facade pipeline.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/752
 */

function repo_get_string_key(Repository $config): void
{
    $_result = $config->get('app.name');
    /** @psalm-check-type-exact $_result = string */
}

function repo_get_bool_key(Repository $config): void
{
    $_result = $config->get('app.debug');
    /** @psalm-check-type-exact $_result = bool */
}

function repo_get_missing_key_no_default(Repository $config): void
{
    $_result = $config->get('foo.bar.nonexistent');
    /** @psalm-check-type-exact $_result = null */
}

function repo_get_missing_key_string_default(Repository $config): void
{
    $_result = $config->get('foo.bar.nonexistent', 'fallback');
    /** @psalm-check-type-exact $_result = string */
}

function repo_get_missing_key_int_default(Repository $config): void
{
    $_result = $config->get('foo.bar.nonexistent', 42);
    /** @psalm-check-type-exact $_result = int */
}

function repo_get_dynamic_key_remains_mixed(Repository $config, string $key): void
{
    $_result = $config->get($key);
    /** @psalm-check-type-exact $_result = mixed */
}

function repo_get_via_contract(ConfigContract $config): void
{
    $_result = $config->get('app.name');
    /** @psalm-check-type-exact $_result = string */
}

function repo_get_array_first_arg_defers_to_stub(Repository $config): void
{
    // Repository::get(array) is the multi-key form (getMany). The handler
    // returns null on array first-arg so the stub's signature wins.
    $_result = $config->get(['app.name', 'app.debug']);
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
