--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Repository::integer() casts the cached value to int. Like Request::integer(),
 * the cast cannot produce an injection payload, so the return is not a taint
 * source.
 */
function renderCacheInteger(\Illuminate\Cache\Repository $cache): void {
    echo $cache->integer('user_age');
}
?>
--EXPECTF--
