--FILE--
<?php declare(strict_types=1);

/**
 * `app.asset_url` is `env('ASSET_URL')` in the test app, so it is a null leaf whenever the variable is unset.
 * Typing it `null` made guards on it environment-dependent contradictions (#1683).
 */
function config_null_leaf_is_mixed(): void
{
    $_value = config('app.asset_url');
    /** @psalm-check-type-exact $_value = mixed */
}

function config_null_leaf_string_guard(): void
{
    $_value = config('app.asset_url');
    if (is_string($_value) && $_value !== '') {
        echo $_value;
    }
}

function config_null_leaf_filled_guard(): bool
{
    return filled(config('app.asset_url'));
}

function config_null_leaf_elvis(): void
{
    $_hostname = config('app.asset_url') ?: 'fallback';
}
?>
--EXPECTF--
