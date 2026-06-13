--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderFlexibleCacheData(\Illuminate\Cache\Repository $cache) {
    echo $cache->flexible('user_input', [30, 60], fn () => 'default');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
