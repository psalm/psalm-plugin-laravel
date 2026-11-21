--FILE--
<?php declare(strict_types=1);

use Illuminate\Cookie\CookieJar;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as CookieInstance;

/**
 * An unbounded template argument may hold a Closure that Laravel invokes, and Psalm cannot decide `\Closure`
 * against it. Since Psalm 7.14 (vimeo/psalm#12672) the result is therefore widened with `mixed`, so returning it
 * as the bare template is reported as MixedReturnStatement (before, the possible Closure result stayed silent).
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
MixedReturnStatement on line %d: Possibly-mixed return value
MixedReturnStatement on line %d: Possibly-mixed return value
MixedReturnStatement on line %d: Possibly-mixed return value
