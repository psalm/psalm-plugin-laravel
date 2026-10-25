--FILE--
<?php declare(strict_types=1);

use Illuminate\Cookie\CookieJar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as CookieInstance;

interface InvokableFactory
{
    /** @psalm-impure */
    public function __invoke(): int;
}

/**
 * Known limitations (see the `getOrPut()` docblock in stubs/common/Support/Collection.phpstub, and the
 * `queued()` docblocks in stubs/common/Support/Facades/Cookie.phpstub and stubs/common/Cookie/CookieJar.phpstub):
 *
 * 1. An unbounded template argument may hold a Closure that Laravel invokes, but Psalm resolves
 *    `$default is callable` to false for it, so the result stays the bare template and no issue is raised.
 *    A `T of callable` bound or a `mixed` argument resolves to `T|mixed` instead.
 * 2. A value typed as an interface declaring `__invoke()` passes the `callable` guard, and Psalm does not
 *    treat `Closure` as final in conditional types, so it cannot rule `\Closure` out: the result and the
 *    receiver gain `|mixed`. Upstream cause, not fixable in the stubs.
 *
 * @template T
 * @param Collection<string, T> $items
 * @param T $fallback
 * @return T
 */
function get_or_put_unbounded_template(Collection $items, mixed $fallback): mixed
{
    return $items->getOrPut('key', $fallback);
}

/**
 * @template T
 * @param T $fallback
 * @return CookieInstance|T
 */
function cookie_queued_unbounded_template(mixed $fallback): mixed
{
    return Cookie::queued('name', $fallback);
}

/**
 * @template T
 * @param T $fallback
 * @return CookieInstance|T
 */
function cookie_jar_queued_unbounded_template(CookieJar $jar, mixed $fallback): mixed
{
    return $jar->queued('name', $fallback);
}

/** @param Collection<string, InvokableFactory> $items */
function get_or_put_invokable_interface(Collection $items, InvokableFactory $fallback): InvokableFactory
{
    return $items->getOrPut('key', $fallback);
}

/** @param Collection<string, InvokableFactory> $_items */
function accepts_factory_collection(Collection $_items): void {}

/** @param Collection<string, InvokableFactory> $items */
function get_or_put_invokable_interface_receiver(Collection $items, InvokableFactory $fallback): void
{
    $items->getOrPut('key', $fallback);
    accepts_factory_collection($items);
}

function cookie_jar_queued_invokable_interface(CookieJar $jar, InvokableFactory $fallback): CookieInstance|InvokableFactory
{
    return $jar->queued('name', $fallback);
}
?>
--EXPECTF--
MixedReturnStatement on line %d: Could not infer a return type
MixedReturnStatement on line %d: Possibly-mixed return value
MixedArgumentTypeCoercion on line %d: Argument 1 of accepts_factory_collection expects Illuminate\Support\Collection<string, InvokableFactory>, but parent type Illuminate\Support\Collection<string, InvokableFactory|mixed>&static provided
MixedReturnStatement on line %d: Could not infer a return type
MixedReturnStatement on line %d: Possibly-mixed return value
