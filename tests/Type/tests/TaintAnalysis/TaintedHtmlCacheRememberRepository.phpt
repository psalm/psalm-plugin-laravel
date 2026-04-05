--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * @todo Enable when Psalm supports taint flow through Closure params.
 * @see https://github.com/vimeo/psalm/issues/11786
 *
 * Expected: TaintedHtml — taint from $request->input() should flow through
 * Repository::remember() callback to echo.
 */

// function renderCachedInput(\Illuminate\Http\Request $request, \Illuminate\Cache\Repository $cache): void {
//     $value = $cache->remember('key', 60, fn(): mixed => $request->input('q'));
//     echo $value;
// }
?>
--EXPECTF--
