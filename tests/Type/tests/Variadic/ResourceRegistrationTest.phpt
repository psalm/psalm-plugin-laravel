--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Routing\PendingResourceRegistration;

/**
 * PendingResourceRegistration::only() / except() accept variadic method names
 * via func_get_args() — mirrors the existing PendingSingletonResourceRegistration
 * stub behaviour.
 */
function resource_only_variadic(PendingResourceRegistration $reg): void
{
    $_variadic = $reg->only('show', 'edit', 'update');
    /** @psalm-check-type-exact $_variadic = PendingResourceRegistration&static */

    $_array = $reg->only(['show', 'edit']);
    /** @psalm-check-type-exact $_array = PendingResourceRegistration&static */

    $_single = $reg->only('show');
    /** @psalm-check-type-exact $_single = PendingResourceRegistration&static */
}

function resource_except_variadic(PendingResourceRegistration $reg): void
{
    $_variadic = $reg->except('destroy', 'update');
    /** @psalm-check-type-exact $_variadic = PendingResourceRegistration&static */

    $_array = $reg->except(['destroy']);
    /** @psalm-check-type-exact $_array = PendingResourceRegistration&static */

    $_single = $reg->except('destroy');
    /** @psalm-check-type-exact $_single = PendingResourceRegistration&static */
}
?>
--EXPECTF--
