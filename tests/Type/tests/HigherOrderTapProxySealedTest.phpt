--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-sealed.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\HigherOrderTapProxy;
use Illuminate\Support\Stringable;

/**
 * Under sealAllMethods, a proxied call must resolve through HigherOrderTapProxy::__call rather
 * than raise UndefinedMagicMethod — the generic stub carries `__call`, so no proxy handler is
 * needed even in sealed mode.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1110
 */
function tap_proxy_sealed_chain(): void
{
    $_proxy = (new Stringable('x'))->tap();
    /** @psalm-check-type-exact $_proxy = HigherOrderTapProxy<Stringable> */

    $_target = (new Stringable('x'))->tap()->upper();
    /** @psalm-check-type-exact $_target = Stringable */
}
?>
--EXPECTF--
