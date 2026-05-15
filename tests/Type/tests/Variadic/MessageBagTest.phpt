--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\MessageBag;

/**
 * MessageBag::has(), hasAny(), missing() accept variadic string keys via
 * func_get_args(). Without @psalm-variadic the multi-arg calls would be
 * rejected as TooManyArguments.
 */
function message_bag_has_variadic(MessageBag $bag): void
{
    $_single = $bag->has('name');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $bag->has('name', 'email', 'missing');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $bag->has(['name', 'email']);
    /** @psalm-check-type-exact $_array = bool */
}

function message_bag_has_any_variadic(MessageBag $bag): void
{
    $_single = $bag->hasAny('name');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $bag->hasAny('name', 'email');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $bag->hasAny(['name', 'email']);
    /** @psalm-check-type-exact $_array = bool */
}

function message_bag_missing_variadic(MessageBag $bag): void
{
    $_single = $bag->missing('name');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $bag->missing('name', 'email', 'missing');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $bag->missing(['name', 'email']);
    /** @psalm-check-type-exact $_array = bool */
}
?>
--EXPECTF--
