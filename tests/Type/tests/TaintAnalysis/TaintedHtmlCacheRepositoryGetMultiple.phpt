--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function renderGetMultipleCacheData(\Illuminate\Cache\Repository $cache) {
    foreach ($cache->getMultiple(['name', 'email']) as $value) {
        echo $value;
    }
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
