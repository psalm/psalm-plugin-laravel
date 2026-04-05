--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * @todo Enable when Psalm supports taint flow through Closure params.
 * @see https://github.com/vimeo/psalm/issues/11786
 *
 * Expected: TaintedHtml — taint from $request->input() should flow through
 * Cache::remember() callback to echo.
 *
 * Currently disabled: Psalm's @psalm-flow connects the Closure object's taint
 * node, not its return value's taint node, so taint is lost.
 */

// function renderCachedInput(\Illuminate\Http\Request $request): void {
//     $value = \Illuminate\Support\Facades\Cache::remember('key', 60, fn() => $request->input('q'));
//     echo $value;
// }
?>
--EXPECTF--
