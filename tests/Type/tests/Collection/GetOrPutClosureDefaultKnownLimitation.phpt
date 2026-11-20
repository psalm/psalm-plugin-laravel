--FILE--
<?php declare(strict_types=1);

use Illuminate\Cookie\CookieJar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as CookieInstance;

/**
 * Known limitation (see the `getOrPut()` docblock in stubs/common/Support/Collection.phpstub, and the
 * `queued()` docblocks in stubs/common/Support/Facades/Cookie.phpstub and stubs/common/Cookie/CookieJar.phpstub):
 *
 * An unbounded template argument may hold a Closure that Laravel invokes, but Psalm resolves
 * `$default is callable` to false for it, so the result stays the bare template and no issue is raised.
 * A `T of callable` bound or a `mixed` argument resolves to `T|mixed` instead.
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
?>
--EXPECTF--
