--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Repository::float() casts the cached value to float. Like integer(),
 * the cast cannot produce an injection payload, so the return is not a
 * taint source.
 */
function renderCacheFloat(\Illuminate\Cache\Repository $cache): void {
    echo $cache->float('user_ratio');
}
?>
--EXPECTF--
