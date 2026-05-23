--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Direct call form of ArrayAccess::offsetGet. Psalm 7 does not currently
 * propagate the same source annotation through the `$cache['k']` syntax;
 * see the stub docblock for the limitation.
 */
function renderCacheArrayAccess(\Illuminate\Cache\Repository $cache) {
    echo $cache->offsetGet('user_input');
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
