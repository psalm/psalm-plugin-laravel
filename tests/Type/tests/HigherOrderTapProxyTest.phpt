--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\Response;
use Illuminate\Support\HigherOrderTapProxy;
use Illuminate\Support\Stringable;

/**
 * The no-arg higher-order form of Tappable::tap() — typed by TappableTapHandler as a generic
 * HigherOrderTapProxy over the target, with `__call` threading the target type back.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1110
 */

/** No-arg tap() yields the proxy, generic over the target. */
function tap_proxy_noarg(): void
{
    $_r = (new Stringable('x'))->tap();
    /** @psalm-check-type-exact $_r = HigherOrderTapProxy<Stringable> */
}

/** A proxied call returns the TARGET, not the called method's own return — and never errors. */
function tap_proxy_chained_returns_target(): void
{
    $_fluent = (new Stringable('x'))->tap()->upper();
    /** @psalm-check-type-exact $_fluent = Stringable */

    // isEmpty() normally returns bool; through the proxy it returns the target instead.
    $_nonFluent = (new Stringable('x'))->tap()->isEmpty();
    /** @psalm-check-type-exact $_nonFluent = Stringable */
}

/** The proxy exposes the target via $target. */
function tap_proxy_target_property(): void
{
    $_r = (new Stringable('x'))->tap()->target;
    /** @psalm-check-type-exact $_r = Stringable */
}

/** tap() with a callback still returns the instance — no proxy. */
function tap_proxy_with_callback(): void
{
    $_r = (new Stringable('x'))->tap(static function (Stringable $s): void {
        echo (string) $s;
    });
    /** @psalm-check-type-exact $_r = Stringable&static */
}

/** Works for any direct Tappable user, e.g. Http\Client\Response. */
function tap_proxy_on_response(Response $response): void
{
    $_proxy = $response->tap();
    /** @psalm-check-type-exact $_proxy = HigherOrderTapProxy<Response> */

    $_target = $response->tap()->status();
    /** @psalm-check-type-exact $_target = Response */
}

/** A nullable callable could be either branch at runtime → union of proxy and instance. */
function tap_proxy_nullable_callback(?callable $cb): void
{
    $_r = (new Stringable('x'))->tap($cb);
    /** @psalm-check-type-exact $_r = HigherOrderTapProxy<Stringable>|Stringable */
}

/** An explicit literal `null` is the same as the no-arg form — the proxy, not the instance. */
function tap_proxy_explicit_null(): void
{
    $_r = (new Stringable('x'))->tap(null);
    /** @psalm-check-type-exact $_r = HigherOrderTapProxy<Stringable> */
}

/**
 * Regression for the load-bearing `static` strip in the handler: when the receiver is `$this`
 * (the only call site that carries `static`), the proxy template arg must NOT keep `static`, or
 * a chained call re-binds it to the proxy and yields a bogus `TapHost&HigherOrderTapProxy<...>`
 * intersection instead of the target.
 */
class TapHost
{
    use \Illuminate\Support\Traits\Tappable;

    public function name(): string
    {
        return 'x';
    }

    public function probe(): void
    {
        $_self = $this->tap();
        /** @psalm-check-type-exact $_self = HigherOrderTapProxy<TapHost> */

        $_target = $this->tap()->name();
        /** @psalm-check-type-exact $_target = TapHost */
    }
}
?>
--EXPECTF--
