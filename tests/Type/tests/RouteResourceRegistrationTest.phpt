--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Routing\PendingSingletonResourceRegistration;

// Regression: both only() and except() accept variadic method names via func_get_args(),
// e.g. `$router->singleton(...)->except('show', 'destroy')`.
function only_variadic(PendingSingletonResourceRegistration $reg): void
{
    $_a = $reg->only('show', 'destroy');
    /** @psalm-check-type-exact $_a = \Illuminate\Routing\PendingSingletonResourceRegistration&static */

    $_b = $reg->only(['show']);
    /** @psalm-check-type-exact $_b = \Illuminate\Routing\PendingSingletonResourceRegistration&static */

    $_c = $reg->only('show');
    /** @psalm-check-type-exact $_c = \Illuminate\Routing\PendingSingletonResourceRegistration&static */
}

function except_variadic(PendingSingletonResourceRegistration $reg): void
{
    $_a = $reg->except('show', 'destroy');
    /** @psalm-check-type-exact $_a = \Illuminate\Routing\PendingSingletonResourceRegistration&static */

    $_b = $reg->except(['show']);
    /** @psalm-check-type-exact $_b = \Illuminate\Routing\PendingSingletonResourceRegistration&static */

    $_c = $reg->except('show');
    /** @psalm-check-type-exact $_c = \Illuminate\Routing\PendingSingletonResourceRegistration&static */
}
?>
--EXPECT--
