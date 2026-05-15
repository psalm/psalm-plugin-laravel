--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;

/**
 * Cache Repository::tags() accepts either an array or variadic string tag names
 * via func_get_args(). Array form is the documented public API but the variadic
 * form is equally valid at runtime.
 */
function cache_tags_variadic(Repository $cache): void
{
    $_single = $cache->tags('posts');
    /** @psalm-check-type-exact $_single = TaggedCache */

    $_variadic = $cache->tags('posts', 'comments');
    /** @psalm-check-type-exact $_variadic = TaggedCache */

    $_array = $cache->tags(['posts', 'comments']);
    /** @psalm-check-type-exact $_array = TaggedCache */
}
?>
--EXPECTF--
