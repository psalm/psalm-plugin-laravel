--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Repository::boolean() returns a strict bool. A boolean cannot carry an
 * injection payload, so the return is not a taint source.
 */
function renderCacheBoolean(\Illuminate\Cache\Repository $cache): void {
    echo $cache->boolean('user_flag') ? 'yes' : 'no';
}
?>
--EXPECTF--
